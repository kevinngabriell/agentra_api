<?php

// Subscription billing and plan entitlements.
//
// Plans (app_services rows): Starter (free, forever), Profesional, Tim. A company's *effective* plan is
// the plan it has actually paid for; when a paid plan is unpaid or past its billing date the company
// falls back to Starter limits instead of being locked out. Features are gated server-side with
// requirePlanFeature() / assertPolicyQuota() — the web app only mirrors what the API enforces.

const PLAN_TIER_STARTER = 'starter';
const PLAN_TIER_PRO     = 'pro';
const PLAN_TIER_TEAM    = 'team';

const PLAN_TIER_RANK  = [PLAN_TIER_STARTER => 0, PLAN_TIER_PRO => 1, PLAN_TIER_TEAM => 2];
const PLAN_TIER_LABEL = [PLAN_TIER_STARTER => 'Starter', PLAN_TIER_PRO => 'Profesional', PLAN_TIER_TEAM => 'Tim'];

const STARTER_POLICY_LIMIT = 50;

// Lowest tier that includes each feature (mirrors the pricing cards).
const PLAN_FEATURE_MIN_TIER = [
    'whatsapp'              => PLAN_TIER_PRO,   // Notifikasi WhatsApp otomatis
    'export'                => PLAN_TIER_PRO,   // Ekspor data Excel & PDF
    'commission_statements' => PLAN_TIER_PRO,   // Laporan komisi lengkap (rekonsiliasi per statement)
    'sub_agents'            => PLAN_TIER_TEAM,  // Manajemen multi-agen, pembagian komisi, analitik tim
];

const SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS = 24;

/** Free plans are Starter; "tim"/"team" by name is Tim; any other paid plan is Profesional. */
function planTierOf(string $service_name, int $price): string {
    if ($price <= 0) return PLAN_TIER_STARTER;
    return preg_match('/\b(tim|team)\b/i', $service_name) ? PLAN_TIER_TEAM : PLAN_TIER_PRO;
}

// Clamps the day-of-month so Jan 31 + 1 month = Feb 28/29, not Mar 3.
function addBillingCycle(string $date, string $billing_cycle): string {
    $base = new DateTime($date);
    $day  = (int)$base->format('j');
    $base->modify('first day of this month');
    $base->modify($billing_cycle === 'yearly' ? '+1 year' : '+1 month');
    $base->setDate((int)$base->format('Y'), (int)$base->format('n'), min($day, (int)$base->format('t')));
    return $base->format('Y-m-d');
}

/**
 * The company's subscribed vs effective plan.
 * Returns tier (effective), subscribed_tier, plan_name, in_effect, policy_limit (null = unlimited),
 * payment_status and period_end.
 */
function getCompanyPlan($conn, string $company_id, bool $fresh = false): array {
    static $cache = [];
    if (!$fresh && isset($cache[$company_id])) return $cache[$company_id];

    $cid = mysqli_real_escape_string($conn, $company_id);
    $res = mysqli_query($conn, "SELECT s.service_id, s.billing_cycle, s.payment_status, s.next_billing_date,
            svc.service_name, svc.service_price
        FROM " . CORE_SCHEMA . ".app_subscription s
        JOIN " . CORE_SCHEMA . ".app_services svc ON svc.service_id = s.service_id
        WHERE s.app_company_id = '$cid' AND s.app_id = '" . AGENTRA_APP_ID . "' AND s.subscription_status = 'active'
        ORDER BY s.created_at DESC LIMIT 1");
    $sub = $res ? mysqli_fetch_assoc($res) : null;

    $plan = [
        'tier' => PLAN_TIER_STARTER, 'subscribed_tier' => PLAN_TIER_STARTER, 'plan_id' => $sub['service_id'] ?? null,
        'plan_name' => PLAN_TIER_LABEL[PLAN_TIER_STARTER], 'in_effect' => true, 'payment_status' => $sub['payment_status'] ?? null,
        'period_end' => $sub['next_billing_date'] ?? null, 'billing_cycle' => $sub['billing_cycle'] ?? null,
    ];

    if ($sub) {
        $subscribed = planTierOf((string)$sub['service_name'], (int)$sub['service_price']);
        $plan['subscribed_tier'] = $subscribed;
        $plan['plan_name']       = $sub['service_name'];
        if ($subscribed !== PLAN_TIER_STARTER) {
            $paidAndCurrent = $sub['payment_status'] === 'paid'
                && $sub['next_billing_date'] !== null && $sub['next_billing_date'] >= date('Y-m-d');
            $plan['in_effect'] = $paidAndCurrent;
            $plan['tier']      = $paidAndCurrent ? $subscribed : PLAN_TIER_STARTER;
        }
    }
    $plan['policy_limit'] = $plan['tier'] === PLAN_TIER_STARTER ? STARTER_POLICY_LIMIT : null;

    return $cache[$company_id] = $plan;
}

function companyHasFeature($conn, string $company_id, string $feature): bool {
    $min = PLAN_FEATURE_MIN_TIER[$feature] ?? PLAN_TIER_STARTER;
    return PLAN_TIER_RANK[getCompanyPlan($conn, $company_id)['tier']] >= PLAN_TIER_RANK[$min];
}

/** Throws 403 ERR_PLAN_005 unless the company's effective plan includes the feature. */
function requirePlanFeature($conn, string $company_id, string $feature): void {
    if (companyHasFeature($conn, $company_id, $feature)) return;
    $required = PLAN_FEATURE_MIN_TIER[$feature] ?? PLAN_TIER_PRO;
    throw ApiException::from('ERR_PLAN_005', [
        'feature' => $feature, 'required_plan' => PLAN_TIER_LABEL[$required], 'required_tier' => $required,
    ]);
}

function countActivePolicies($conn, string $company_id): int {
    $cid = mysqli_real_escape_string($conn, $company_id);
    $res = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid' AND renewal_status NOT IN ('lapsed', 'cancelled')");
    return $res ? (int)(mysqli_fetch_assoc($res)['cnt'] ?? 0) : 0;
}

/** Throws 403 ERR_PLAN_006 if adding $adding more active policies would exceed the plan's cap. */
function assertPolicyQuota($conn, string $company_id, int $adding = 1): void {
    $plan = getCompanyPlan($conn, $company_id);
    if ($plan['policy_limit'] === null) return;
    if (countActivePolicies($conn, $company_id) + $adding > $plan['policy_limit']) {
        throw ApiException::from('ERR_PLAN_006', [
            'plan' => PLAN_TIER_LABEL[$plan['tier']], 'limit' => $plan['policy_limit'], 'required_tier' => PLAN_TIER_PRO,
        ]);
    }
}

// Caller owns the transaction. Marks the subscription paid for a new period and syncs app_company.expired_at.
function activateSubscriptionFromPayment($conn, array $payment, string $now): string {
    $company_id    = mysqli_real_escape_string($conn, $payment['company_id']);
    $service_id    = mysqli_real_escape_string($conn, $payment['service_id']);
    $billing_cycle = $payment['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
    $amount        = (int)$payment['amount'];
    $today         = date('Y-m-d');

    $existing_result = mysqli_query($conn, "SELECT subscription_id, service_id, subscription_status, payment_status, current_period_end, next_billing_date
        FROM " . CORE_SCHEMA . ".app_subscription
        WHERE app_company_id = '$company_id' AND app_id = '" . AGENTRA_APP_ID . "'
          AND subscription_status IN ('active', 'paused', 'pending', 'expired')
        ORDER BY created_at DESC LIMIT 1 FOR UPDATE");
    $existing = $existing_result ? mysqli_fetch_assoc($existing_result) : null;

    // Renewing the same plan early extends from the current period end; anything else starts today.
    $period_start = $today;
    if ($existing && $existing['subscription_status'] === 'active' && $existing['payment_status'] === 'paid' && $existing['service_id'] === $payment['service_id']) {
        $current_end = $existing['current_period_end'] ?? $existing['next_billing_date'];
        if ($current_end && $current_end > $today) $period_start = $current_end;
    }
    $period_end = addBillingCycle($period_start, $billing_cycle);

    if ($existing) {
        $subscription_id = $existing['subscription_id'];
        if (!mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_subscription SET
                service_id = '$service_id', billing_cycle = '$billing_cycle',
                subscription_status = 'active', payment_status = 'paid',
                start_date = '$today', current_period_start = '$period_start', current_period_end = '$period_end',
                next_billing_date = '$period_end',
                pricing_model_snapshot = 'fixed', price_snapshot = $amount
            WHERE subscription_id = '$subscription_id'")) {
            throw new Exception('subscription update failed: ' . mysqli_error($conn));
        }
    } else {
        $subscription_id = generateUUID();
        if (!mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_subscription
            (subscription_id, app_company_id, app_id, service_id, start_date, next_billing_date,
             billing_cycle, subscription_status, payment_status, current_period_start, current_period_end,
             pricing_model_snapshot, price_snapshot, created_at)
            VALUES ('$subscription_id', '$company_id', '" . AGENTRA_APP_ID . "', '$service_id', '$today', '$period_end',
                    '$billing_cycle', 'active', 'paid', '$period_start', '$period_end',
                    'fixed', $amount, '$now')")) {
            throw new Exception('subscription insert failed: ' . mysqli_error($conn));
        }
    }

    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_company SET expired_at = '$period_end 23:59:59'
        WHERE company_id = '$company_id' AND app_id = '" . AGENTRA_APP_ID . "'");

    return $subscription_id;
}
