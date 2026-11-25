<?php

// Premium ledger for mid-term changes (policy_endorsements) and cancellation maths.
//
// Model
//   policies.premium_amount / sum_insured      the CURRENT annual contract (what existing screens show)
//   policies.issued_premium_amount / _customer  the ORIGINAL debit note; payments bill this amount
//   policy_endorsements                         one debit or credit note per premium-changing endorsement,
//                                               with its own commission (pending → received / clawed back)
//
// A correction (agent's own typo) folds into the issued figures and the policy commission record.
// An endorsement (insurer changed the contract) never touches the original debit note: its premium
// difference, pro-rated from the effective date, is billed or refunded through its own note.

require_once __DIR__ . '/db.php';

const END_TYPES = ['sum_insured_change', 'add_item', 'remove_item', 'extension', 'insured_change', 'period_change',
                   'cancellation', 'stock_adjustment', 'other'];
const END_CALC_METHODS = ['pro_rata', 'short_period', 'flat', 'manual', 'full'];
const END_COMMISSION_STATUSES = ['included', 'pending', 'received', 'clawed_back', 'waived'];
const CANCELLATION_REASONS = ['insured_request', 'non_payment', 'sold', 'moved_insurer', 'insurer_request', 'other'];
const CANCELLATION_METHODS = ['short_period', 'pro_rata', 'full', 'none', 'manual'];

// Standard short-period scale: % of the annual premium the insurer keeps for a period of cover,
// any part of a month counting as a full month. This is the scale printed in most Indonesian
// property wordings; check the wording of the policy, the app lets the agent override the amount.
const SHORT_PERIOD_SCALE = [1 => 20, 2 => 30, 3 => 40, 4 => 50, 5 => 60, 6 => 70, 7 => 75, 8 => 80, 9 => 85, 10 => 90, 11 => 95, 12 => 100];

const END_DEBIT_NOTE_DAYS = 30; // default due date of an endorsement debit note, after the effective date

// ── Pure maths ───────────────────────────────────────────────────────────────

function endDays(string $from, string $to): int {
    return (int)(new DateTime($from))->diff(new DateTime($to))->format('%r%a');
}

/** Share of the period still to run at $effective: 1.0 at the start, 0.0 at the end. */
function endProRataFactor(string $start, string $end, string $effective): float {
    $total = max(1, endDays($start, $end));
    $remaining = endDays(max($start, min($effective, $end)), $end);
    return max(0.0, min(1.0, $remaining / $total));
}

/** Whole months from $from to $to, a part month counting as a full one. 0 when $to <= $from. */
function endMonthsCeil(string $from, string $to): int {
    if ($to <= $from) return 0;
    $d = (new DateTime($from))->diff(new DateTime($to));
    $months = $d->y * 12 + $d->m;
    if ($d->d > 0) $months++;
    return max(1, $months);
}

/** % of the annual premium kept for $months of cover (short-period scale). */
function shortPeriodPercent(int $months): int {
    if ($months <= 0) return 0;
    return SHORT_PERIOD_SCALE[min(12, $months)];
}

/**
 * Premium charged (+) or returned (-) for an annual premium change taking effect on $effective.
 *   pro_rata      annual × remaining share of the period
 *   short_period  increase: annual × scale(remaining months); decrease: annual × (1 − scale(elapsed months))
 *   flat / full   the whole annual change
 *   manual        $manual as given
 */
function endChargedPremium(string $method, int $annual_delta, string $start, string $end, string $effective, ?int $manual = null): int {
    return match ($method) {
        'pro_rata'     => (int)round($annual_delta * endProRataFactor($start, $end, $effective)),
        'short_period' => $annual_delta >= 0
            ? (int)round($annual_delta * shortPeriodPercent(endMonthsCeil($effective, $end)) / 100)
            : (int)round($annual_delta * (100 - shortPeriodPercent(endMonthsCeil($start, $effective))) / 100),
        'flat', 'full' => $annual_delta,
        'manual'       => (int)($manual ?? 0),
        default        => throw ApiException::from('ERR_V21_001', ['field' => 'calc_method']),
    };
}

/**
 * Refund on cancellation of a policy whose billed premium is $billed.
 *   short_period  insured cancels: insurer keeps the short-period premium for the time on risk
 *   pro_rata      insurer cancels: refund the unexpired share
 *   full          cancelled from inception (ab initio): refund everything
 *   none          nothing is refunded
 * Returns the refund as a positive number.
 */
function cancellationRefund(string $method, int $billed, string $start, string $end, string $cancel_date, ?int $manual = null): int {
    $refund = match ($method) {
        'short_period' => (int)round($billed * (100 - shortPeriodPercent(endMonthsCeil($start, $cancel_date))) / 100),
        'pro_rata'     => (int)round($billed * endProRataFactor($start, $end, $cancel_date)),
        'full'         => $billed,
        'none'         => 0,
        'manual'       => (int)($manual ?? 0),
        default        => throw ApiException::from('ERR_V21_001', ['field' => 'method']),
    };
    return max(0, min($billed, $refund));
}

/** Commission and its tax on a premium amount, signed like the premium. */
function endCommission(int $premium, float $rate_percent, float $tax_rate): array {
    $commission = (int)round($premium * $rate_percent / 100);
    $tax = (int)round($commission * $tax_rate);
    return [$commission, $tax];
}

// ── Note numbers ─────────────────────────────────────────────────────────────

/** Next sequential note number, e.g. DN-2026-00042. $type is DN (debit) or CN (credit). */
function nextNoteNumber($conn, string $company_id, string $type, ?string $date = null): string {
    $year = (int)substr($date ?? date('Y-m-d'), 0, 4);
    dbExec($conn, "INSERT INTO {app}.document_sequences (company_id, seq_type, seq_year, last_no) VALUES (?, ?, ?, LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)", [$company_id, $type, $year]);
    $n = (int)mysqli_insert_id($conn);
    return sprintf('%s-%d-%05d', $type, $year, $n);
}

/** The policy's original debit note number, assigned on first use. */
function ensurePolicyDebitNote($conn, string $company_id, string $policy_id): ?string {
    $p = dbOne($conn, "SELECT debit_note_number, coverage_start FROM {app}.policies WHERE policy_id = ? AND company_id = ?", [$policy_id, $company_id]);
    if (!$p) return null;
    if ($p['debit_note_number']) return $p['debit_note_number'];
    $no = nextNoteNumber($conn, $company_id, 'DN');
    dbExec($conn, "UPDATE {app}.policies SET debit_note_number = ? WHERE policy_id = ? AND debit_note_number IS NULL", [$no, $policy_id]);
    return dbValue($conn, "SELECT debit_note_number FROM {app}.policies WHERE policy_id = ?", [$policy_id]);
}

/**
 * After a correction of a policy priced without coverage items: moves the original debit note by the
 * correction's difference. $before_premium / $before_customer are the figures read before the change.
 */
function correctionShiftIssued($conn, string $policy_id, int $before_premium, int $before_customer): void {
    dbExec($conn, "UPDATE {app}.policies
        SET issued_premium_amount   = COALESCE(issued_premium_amount, ?) + (premium_amount - ?),
            issued_customer_premium = COALESCE(issued_customer_premium, ?) + (customer_premium_amount - ?)
        WHERE policy_id = ?", [$before_premium, $before_premium, $before_customer, $before_customer, $policy_id]);
}

/** Freezes the original debit note at the policy's current figures (creation and renewal only). */
function snapshotIssuedPremium($conn, string $policy_id): void {
    dbExec($conn, "UPDATE {app}.policies SET issued_premium_amount = premium_amount, issued_customer_premium = customer_premium_amount WHERE policy_id = ?", [$policy_id]);
}

// ── Ledger ───────────────────────────────────────────────────────────────────

/** Billing options an endorsement request may carry next to change_kind / effective_date. */
function endBillingParse(array $in): array {
    return [
        'calc_method'    => inEnum($in, 'calc_method', END_CALC_METHODS) ?? 'pro_rata',
        'premium_delta'  => inMoney($in, 'premium_delta', false, true),
        'materai_amount' => inMoney($in, 'endorsement_materai') ?? 0,
        'biaya_polis'    => inMoney($in, 'endorsement_biaya_polis') ?? 0,
        'due_date'       => inDate($in, 'due_date'),
        'skip_billing'   => inBool($in, 'skip_billing') === 1,
    ];
}

function endFetchPolicy($conn, string $company_id, string $policy_id): array {
    $p = dbOne($conn, "SELECT policy_id, company_id, policy_number, coverage_start, coverage_end, premium_amount, sum_insured,
            commission_rate, commission_tax_rate, issued_premium_amount, issued_customer_premium, customer_premium_amount,
            renewal_status, payment_status, currency, exchange_rate
        FROM {app}.policies WHERE policy_id = ? AND company_id = ?", [$policy_id, $company_id]);
    if (!$p) throw ApiException::from('ERR_POLICY_001');
    return $p;
}

/**
 * Writes one ledger row. $e keys: endorsement_type, effective_date, annual_premium_delta, sum_insured_delta,
 * calc_method, premium_delta (manual), materai_amount, biaya_polis, due_date, endorsement_number, description,
 * source, reference_type, reference_id. Returns the stored row, or null when nothing is billed or refunded.
 */
function endRecord($conn, array $policy, array $e, string $username): ?array {
    $effective = $e['effective_date'] ?? date('Y-m-d');
    if ($effective < $policy['coverage_start']) $effective = $policy['coverage_start'];
    $annual = (int)($e['annual_premium_delta'] ?? 0);
    $method = $e['calc_method'] ?? 'pro_rata';
    $premium = endChargedPremium($method, $annual, $policy['coverage_start'], $policy['coverage_end'], $effective, $e['premium_delta'] ?? null);
    $fees = (int)($e['materai_amount'] ?? 0) + (int)($e['biaya_polis'] ?? 0);
    $si = (int)($e['sum_insured_delta'] ?? 0);
    if ($premium === 0 && $fees === 0 && $si === 0 && $annual === 0 && empty($e['force'])) return null;

    [$comm, $tax] = endCommission($premium, (float)$policy['commission_rate'], (float)$policy['commission_tax_rate']);
    $gross = $premium + $fees;
    $noteType = $gross > 0 ? 'debit' : ($gross < 0 ? 'credit' : 'none');
    $noteNo = $noteType === 'none' ? null : nextNoteNumber($conn, $policy['company_id'], $noteType === 'debit' ? 'DN' : 'CN');
    $due = $e['due_date'] ?? ($noteType === 'debit' ? date('Y-m-d', strtotime(max($effective, date('Y-m-d')) . ' +' . END_DEBIT_NOTE_DAYS . ' days')) : null);

    $id = dbNewId('end');
    dbInsert($conn, 'policy_endorsements', [
        'endorsement_id' => $id, 'company_id' => $policy['company_id'], 'policy_id' => $policy['policy_id'],
        'endorsement_number' => $e['endorsement_number'] ?? null, 'endorsement_type' => $e['endorsement_type'] ?? 'other',
        'effective_date' => $effective, 'description' => $e['description'] ?? null, 'calc_method' => $method,
        'annual_premium_delta' => $annual, 'premium_delta' => $premium, 'sum_insured_delta' => $si,
        'materai_amount' => (int)($e['materai_amount'] ?? 0), 'biaya_polis' => (int)($e['biaya_polis'] ?? 0),
        'commission_rate' => (float)$policy['commission_rate'], 'commission_tax_rate' => (float)$policy['commission_tax_rate'],
        'commission_delta' => $comm, 'commission_tax_delta' => $tax,
        'note_type' => $noteType, 'note_number' => $noteNo, 'due_date' => $due,
        'commission_status' => $comm === 0 ? 'waived' : 'pending',
        'source' => $e['source'] ?? 'manual', 'reference_type' => $e['reference_type'] ?? null, 'reference_id' => $e['reference_id'] ?? null,
        'created_by' => $username,
    ]);
    return endFetch($conn, $policy['company_id'], $id);
}

function endShape(array $r): array {
    foreach (['annual_premium_delta', 'premium_delta', 'sum_insured_delta', 'materai_amount', 'biaya_polis', 'commission_delta', 'commission_tax_delta'] as $k) $r[$k] = (int)$r[$k];
    $r['commission_rate'] = (float)$r['commission_rate'];
    $r['commission_tax_rate'] = (float)$r['commission_tax_rate'];
    $r['is_void'] = (bool)(int)$r['is_void'];
    $r['gross_amount'] = $r['premium_delta'] + $r['materai_amount'] + $r['biaya_polis'];
    $r['net_commission_delta'] = $r['commission_delta'] - $r['commission_tax_delta'];
    $r['is_settled'] = $r['settled_date'] !== null;
    return $r;
}

function endFetch($conn, string $company_id, string $endorsement_id): ?array {
    $r = dbOne($conn, "SELECT e.*, p.policy_number FROM {app}.policy_endorsements e JOIN {app}.policies p ON p.policy_id = e.policy_id
                       WHERE e.endorsement_id = ? AND e.company_id = ?", [$endorsement_id, $company_id]);
    return $r ? endShape($r) : null;
}

/** Totals for a policy: billed = original debit note + every non-void endorsement note. */
function endPolicySummary($conn, array $policy): array {
    $s = dbOne($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(premium_delta), 0) AS premium, COALESCE(SUM(materai_amount + biaya_polis), 0) AS fees,
            COALESCE(SUM(commission_delta), 0) AS commission,
            COALESCE(SUM(CASE WHEN note_type = 'debit' AND settled_date IS NULL THEN premium_delta + materai_amount + biaya_polis ELSE 0 END), 0) AS debit_open,
            COALESCE(SUM(CASE WHEN note_type = 'credit' AND settled_date IS NULL THEN -(premium_delta + materai_amount + biaya_polis) ELSE 0 END), 0) AS credit_open
        FROM {app}.policy_endorsements WHERE policy_id = ? AND is_void = 0", [$policy['policy_id']]);
    $issued = (int)($policy['issued_premium_amount'] ?? $policy['premium_amount']);
    return [
        'endorsement_count'   => (int)$s['n'],
        'issued_premium'      => $issued,
        'issued_customer_premium' => (int)($policy['issued_customer_premium'] ?? $policy['customer_premium_amount'] ?? 0),
        'endorsement_premium' => (int)$s['premium'],
        'endorsement_fees'    => (int)$s['fees'],
        'billed_premium'      => $issued + (int)$s['premium'],
        'endorsement_commission' => (int)$s['commission'],
        'open_debit_amount'   => (int)$s['debit_open'],
        'open_credit_amount'  => (int)$s['credit_open'],
    ];
}

/**
 * Bills a change to the annual contract. Call after the change is saved with the totals from before and
 * after it. Corrections, or requests with skip_billing, bill nothing. Returns the ledger row or null.
 */
function endFromContractChange($conn, string $company_id, string $policy_id, array $change, array $billing,
                               array $before, array $after, string $type, string $description, string $source,
                               string $username, ?string $ref_type = null, ?string $ref_id = null): ?array {
    if (($change['kind'] ?? 'endorsement') !== 'endorsement' || !empty($billing['skip_billing'])) return null;
    $policy = endFetchPolicy($conn, $company_id, $policy_id);
    return endRecord($conn, $policy, [
        'endorsement_type'     => $type,
        'effective_date'       => $change['effective_date'] ?? date('Y-m-d'),
        'endorsement_number'   => $change['endorsement_number'] ?? null,
        'annual_premium_delta' => $after['premium'] - $before['premium'],
        'sum_insured_delta'    => $after['sum_insured'] - $before['sum_insured'],
        'calc_method'          => $billing['calc_method'] ?? 'pro_rata',
        'premium_delta'        => $billing['premium_delta'] ?? null,
        'materai_amount'       => $billing['materai_amount'] ?? 0,
        'biaya_polis'          => $billing['biaya_polis'] ?? 0,
        'due_date'             => $billing['due_date'] ?? null,
        'description'          => $description,
        'source'               => $source,
        'reference_type'       => $ref_type,
        'reference_id'         => $ref_id,
    ], $username);
}

/** Refuses changes to a cancelled policy. */
function endAssertNotCancelled($conn, string $company_id, string $policy_id): void {
    $s = dbValue($conn, "SELECT renewal_status FROM {app}.policies WHERE policy_id = ? AND company_id = ?", [$policy_id, $company_id]);
    if ($s === 'cancelled') throw ApiException::from('ERR_V21_014');
}
