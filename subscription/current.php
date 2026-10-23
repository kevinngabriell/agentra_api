<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/subscription.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method not allowed');
}

$authUser = requireAuth();
$userId   = cleanInput($authUser['sub'] ?? '');

$userResult = mysqli_query($conn, "SELECT company_id FROM " . CORE_SCHEMA . ".app_user
    WHERE user_id = '$userId' LIMIT 1");

if (!$userResult || mysqli_num_rows($userResult) === 0) {
    jsonResponse(401, 'Token tidak valid atau sudah kedaluwarsa.');
}

$user      = mysqli_fetch_assoc($userResult);
$companyId = cleanInput($user['company_id']);

$subResult = mysqli_query($conn, "SELECT s.subscription_id, s.service_id, s.billing_cycle, s.subscription_status,
        s.start_date, s.next_billing_date, s.payment_status,
        svc.service_name, svc.service_price
    FROM " . CORE_SCHEMA . ".app_subscription s
    JOIN " . CORE_SCHEMA . ".app_services svc ON s.service_id = svc.service_id
    WHERE s.app_company_id = '$companyId' AND s.subscription_status = 'active'
    ORDER BY s.created_at DESC
    LIMIT 1");

if (!$subResult || mysqli_num_rows($subResult) === 0) {
    jsonResponse(404, 'Tidak ada langganan aktif.');
}

$sub  = mysqli_fetch_assoc($subResult);
$plan = getCompanyPlan($conn, $companyId);

$periodEnd = date('Y-m-d', strtotime($sub['next_billing_date'] . ' -1 day'));

// A pending (unexpired) checkout lets the web app resume payment instead of starting a new one.
mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'expired'
    WHERE company_id = '$companyId' AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()");
$payRes = mysqli_query($conn, "SELECT id AS payment_id, order_id, service_id, amount, status, payment_link_url, expires_at
    FROM " . APP_SCHEMA . ".subscription_payment
    WHERE company_id = '$companyId' AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
$pending = $payRes ? mysqli_fetch_assoc($payRes) : null;
if ($pending) $pending['amount'] = (int)$pending['amount'];

jsonResponse(200, 'OK', [
    'subscription_id'      => $sub['subscription_id'],
    'plan'                 => [
        'plan_id'   => $sub['service_id'],
        'name'      => $sub['service_name'],
        'price_idr' => (int)$sub['service_price'],
    ],
    'billing_cycle'        => $sub['billing_cycle'],
    'status'               => $sub['subscription_status'],
    'payment_status'       => $sub['payment_status'],
    'current_period_start' => $sub['start_date'],
    'current_period_end'   => $periodEnd,
    'next_billing_date'    => $sub['next_billing_date'],
    // What the company can actually use right now (falls back to Starter while a paid plan is unpaid or lapsed).
    'effective'            => [
        'tier'            => $plan['tier'],
        'plan_name'       => PLAN_TIER_LABEL[$plan['tier']],
        'subscribed_tier' => $plan['subscribed_tier'],
        'in_effect'       => $plan['in_effect'],
        'policy_limit'    => $plan['policy_limit'],
        'features'        => array_keys(array_filter(PLAN_FEATURE_MIN_TIER, fn($min) => PLAN_TIER_RANK[$plan['tier']] >= PLAN_TIER_RANK[$min])),
    ],
    'pending_payment'      => $pending,
    'usage'                => [
        'active_policy_count' => countActivePolicies($conn, $companyId),
    ],
]);
