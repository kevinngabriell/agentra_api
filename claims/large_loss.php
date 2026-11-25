<?php

// [v2.1.0] Large-loss claim handling: reserve history, interim / final payments, salvage & subrogation
// recoveries, the settlement calculation (deductible from the policy terms, average, salvage), the
// co-insurance split, deadlines, adjuster and motor-repair fields. Functions only; routing in claims/index.php.
//
//   GET    /claims/deductible-preview?policy_id&coverage_id&estimated_loss   deductible the policy terms give
//   POST   /claims/{id}/reserves                {reserve_date, amount, source, note}
//   POST   /claims/{id}/payments                {payment_date, amount, payment_type: interim|final, payer_name, reference, note}
//   DELETE /claims/{id}/payments/{payment_id}
//   POST   /claims/{id}/recoveries              {recovery_type, party, amount, recovery_date, returned_to_insured, note}
//   DELETE /claims/{id}/recoveries/{recovery_id}

const CLAIM_RESERVE_SOURCES = ['initial', 'insured', 'adjuster_prelim', 'adjuster_final', 'insurer', 'agent'];
const CLAIM_RESERVE_LABELS = ['initial' => 'Estimasi awal', 'insured' => 'Tertanggung', 'adjuster_prelim' => 'Laporan awal adjuster',
    'adjuster_final' => 'Laporan akhir adjuster', 'insurer' => 'Asuransi', 'agent' => 'Agen'];

/** Fields of the claim form beyond the original set: parsed when present. */
function claimExtraParsers(): array {
    $d = fn(string $f) => fn($i) => inDate($i, $f);
    $t = fn(string $f, int $m) => fn($i) => inText($i, $f, $m);
    return [
        'claim_type' => fn($i) => inEnum($i, 'claim_type', ['partial', 'total_loss']) ?? 'partial',
        'value_at_risk' => fn($i) => inMoney($i, 'value_at_risk'),
        'salvage_amount' => fn($i) => inMoney($i, 'salvage_amount') ?? 0,
        'estimated_loss_fc' => fn($i) => inNumber($i, 'estimated_loss_fc', 0, 99_999_999_999_999),
        'adjuster_firm' => $t('adjuster_firm', 150), 'adjuster_name' => $t('adjuster_name', 150),
        'adjuster_appointed_date' => $d('adjuster_appointed_date'), 'prelim_report_date' => $d('prelim_report_date'),
        'final_report_date' => $d('final_report_date'),
        'workshop_name' => $t('workshop_name', 150), 'spk_date' => $d('spk_date'), 'own_risk_paid_date' => $d('own_risk_paid_date'),
        'repair_status' => fn($i) => inEnum($i, 'repair_status', ['not_started', 'in_repair', 'ready', 'handed_over']),
        'vehicle_handover_date' => $d('vehicle_handover_date'), 'bpkb_handover_date' => $d('bpkb_handover_date'),
        'notice_deadline' => $d('notice_deadline'), 'docs_deadline' => $d('docs_deadline'),
        'decision_due' => $d('decision_due'), 'payment_due' => $d('payment_due'),
    ];
}

const CLAIM_EXTRA_LABELS = [
    'claim_type' => 'Jenis klaim', 'value_at_risk' => 'Nilai sebenarnya saat kejadian', 'salvage_amount' => 'Nilai salvage',
    'estimated_loss_fc' => 'Estimasi (valas)', 'currency' => 'Mata uang', 'exchange_rate' => 'Kurs',
    'adjuster_firm' => 'Perusahaan adjuster', 'adjuster_name' => 'Nama adjuster', 'adjuster_appointed_date' => 'Tanggal penunjukan adjuster',
    'prelim_report_date' => 'Laporan awal adjuster', 'final_report_date' => 'Laporan akhir adjuster', 'workshop_name' => 'Bengkel',
    'spk_date' => 'Tanggal SPK', 'own_risk_paid_date' => 'Risiko sendiri dibayar', 'repair_status' => 'Status perbaikan',
    'vehicle_handover_date' => 'Serah terima kendaraan', 'bpkb_handover_date' => 'Serah BPKB', 'notice_deadline' => 'Batas lapor',
    'docs_deadline' => 'Batas dokumen', 'decision_due' => 'Batas keputusan', 'payment_due' => 'Batas pembayaran', 'coverage_id' => 'Item yang terdampak',
];

/** Coverage item of the claim's policy, or throws. Null input clears. */
function claimResolveCoverage($conn, string $policy_id, mixed $coverage_id): ?string {
    if ($coverage_id === null || $coverage_id === '') return null;
    $ok = dbOne($conn, "SELECT 1 FROM {app}.policy_coverages WHERE coverage_id = ? AND policy_id = ?", [(string)$coverage_id, $policy_id]);
    if (!$ok) throw ApiException::from('ERR_V21_018');
    return (string)$coverage_id;
}

/** Policy terms that apply to a loss on $coverage_id (whole-policy terms plus that item's). */
function claimApplicableTerms($conn, string $policy_id, ?string $coverage_id): array {
    return dbAll($conn, "SELECT term_type, clause_code, title, deductible_type, deductible_amount, deductible_percent
        FROM {app}.policy_terms WHERE policy_id = ? AND (coverage_id IS NULL OR coverage_id = ?)", [$policy_id, $coverage_id ?? '']);
}

/** Whether the average (underinsurance) clause applies: a cl_average clause, or a property policy (standard wording). */
function claimAverageApplies($conn, array $terms, string $product_type): bool {
    foreach ($terms as $t) if (($t['clause_code'] ?? null) === 'cl_average') return true;
    return claimProductGroup($product_type) === 'fire';  // $product_type is the canonical product (claimCanonicalProduct)
}

function claimSumInsuredFor(array $claim): int {
    return $claim['coverage_sum_insured'] ?? (int)$claim['sum_insured'];
}

function claimDeductiblePreview($conn, string $company_id, array $q): void {
    $policy_id = (string)($q['policy_id'] ?? '');
    $p = dbOne($conn, "SELECT policy_id, sum_insured, product_type FROM {app}.policies WHERE policy_id = ? AND company_id = ?", [$policy_id, $company_id]);
    if (!$p) throw ApiException::from('ERR_CLAIM_002');
    $coverage_id = claimResolveCoverage($conn, $policy_id, $q['coverage_id'] ?? null);
    $si = $coverage_id ? (int)dbValue($conn, "SELECT sum_insured FROM {app}.policy_coverages WHERE coverage_id = ?", [$coverage_id]) : (int)$p['sum_insured'];
    $loss = max(0, (int)($q['estimated_loss'] ?? 0));
    $terms = claimApplicableTerms($conn, $policy_id, $coverage_id);
    [$amount, $term] = claimDeductibleFromTerms($terms, $loss, $si);
    jsonResponse(200, 'Deductible preview', [
        'deductible' => $amount, 'term_title' => $term['title'] ?? null, 'sum_insured' => $si,
        'average_applies' => claimAverageApplies($conn, $terms, claimCanonicalProduct($conn, $company_id, $p['product_type'])),
        'deductible_terms' => array_values(array_filter($terms, fn($t) => $t['term_type'] === 'deductible')),
    ]);
}

/** Reserves, payments, recoveries, settlement, co-insurance split and deadlines for the claim detail. */
function claimLargeLossDetail($conn, string $company_id, array $claim): array {
    $id = $claim['claim_id'];
    $reserves = dbAll($conn, "SELECT reserve_id, reserve_date, amount, source, note, created_at FROM {app}.claim_reserves WHERE claim_id = ? ORDER BY reserve_date, created_at", [$id]);
    foreach ($reserves as &$r) { $r['amount'] = (int)$r['amount']; $r['source_label'] = CLAIM_RESERVE_LABELS[$r['source']] ?? $r['source']; }
    unset($r);
    $payments = dbAll($conn, "SELECT payment_id, payment_date, amount, payment_type, payer_name, reference, note, created_at FROM {app}.claim_payments WHERE claim_id = ? ORDER BY payment_date, created_at", [$id]);
    foreach ($payments as &$r) $r['amount'] = (int)$r['amount'];
    unset($r);
    $recoveries = dbAll($conn, "SELECT recovery_id, recovery_type, party, amount, recovery_date, returned_to_insured, note, created_at FROM {app}.claim_recoveries WHERE claim_id = ? ORDER BY created_at", [$id]);
    foreach ($recoveries as &$r) { $r['amount'] = (int)$r['amount']; $r['returned_to_insured'] = (int)$r['returned_to_insured']; }
    unset($r);

    // The agent's own check of the insurer's figure: start from the assessed loss (latest reserve), apply average,
    // deductible and salvage, and compare the result with the amount the insurer approved (already net).
    $terms = claimApplicableTerms($conn, $claim['policy_id'], $claim['coverage_id']);
    $settlement = claimSettlement((int)$claim['estimated_loss'], claimSumInsuredFor($claim), $claim['value_at_risk'],
        claimAverageApplies($conn, $terms, claimCanonicalProduct($conn, $company_id, $claim['product_type'])), $terms, (int)$claim['deductible'], (int)$claim['salvage_amount']);
    $paid = array_sum(array_column($payments, 'amount'));
    $settlement['basis'] = 'assessed_loss';
    $settlement['deductible_on_claim'] = (int)$claim['deductible'];
    $settlement['deductible_differs'] = (int)$claim['deductible'] > 0 && (int)$claim['deductible'] !== $settlement['deductible_suggested'];
    $settlement['approved_amount'] = $claim['approved_amount'];
    $settlement['difference_vs_approved'] = $claim['approved_amount'] !== null ? $claim['approved_amount'] - $settlement['net_payable'] : null;
    $settlement['paid_to_date'] = $paid;
    $settlement['outstanding'] = max(0, ($claim['approved_amount'] ?? $settlement['net_payable']) - $paid);

    // Co-insurance: each insurer's share of the agreed amount and what it has paid (matched by payer name).
    $split = [];
    foreach (dbAll($conn, "SELECT co_insurer_name, share_percent, is_leader FROM {app}.policy_coassurance WHERE policy_id = ? ORDER BY is_leader DESC, share_percent DESC", [$claim['policy_id']]) as $c) {
        $share = (float)$c['share_percent'];
        $due = (int)round(($claim['approved_amount'] ?? $settlement['net_payable']) * $share / 100);
        $byPayer = array_sum(array_map(fn($p) => $p['payer_name'] !== null && strcasecmp(trim($p['payer_name']), trim($c['co_insurer_name'])) === 0 ? $p['amount'] : 0, $payments));
        $split[] = ['co_insurer_name' => $c['co_insurer_name'], 'share_percent' => $share, 'is_leader' => (bool)(int)$c['is_leader'],
                    'share_amount' => $due, 'paid_amount' => $byPayer, 'outstanding' => max(0, $due - $byPayer)];
    }

    return [
        'reserves' => $reserves, 'payments' => $payments, 'recoveries' => $recoveries,
        'recovered_total' => array_sum(array_column($recoveries, 'amount')),
        'settlement' => $settlement, 'coinsurance_split' => $split, 'deadlines' => claimDeadlines($claim),
    ];
}

/** Columns for the new fields present in a create / update request. */
function claimExtraSet($conn, array $in, string $policy_id): array {
    $set = inPresent($in, claimExtraParsers());
    $set += inCurrency($in);
    if (array_key_exists('coverage_id', $in)) $set['coverage_id'] = claimResolveCoverage($conn, $policy_id, $in['coverage_id']);
    return $set;
}

function addClaimReserve($conn, string $company_id, string $claim_id, array $in, string $username): void {
    $claim = findClaim($conn, $claim_id, $company_id);
    $date = inDate($in, 'reserve_date') ?? date('Y-m-d');
    $amount = inMoney($in, 'amount', true);
    $source = inEnum($in, 'source', CLAIM_RESERVE_SOURCES) ?? 'agent';
    $note = inText($in, 'note', 500);
    dbTransaction($conn, function () use ($conn, $claim, $claim_id, $company_id, $date, $amount, $source, $note, $username) {
        dbInsert($conn, 'claim_reserves', ['reserve_id' => dbNewId('crs'), 'claim_id' => $claim_id, 'company_id' => $company_id,
            'reserve_date' => $date, 'amount' => $amount, 'source' => $source, 'note' => $note, 'created_by' => $username]);
        dbExec($conn, "UPDATE {app}.claims SET estimated_loss = ?, updated_by = ? WHERE claim_id = ?", [$amount, $username, $claim_id]);
        if ($source === 'adjuster_prelim') dbExec($conn, "UPDATE {app}.claims SET prelim_report_date = COALESCE(prelim_report_date, ?) WHERE claim_id = ?", [$date, $claim_id]);
        if ($source === 'adjuster_final') dbExec($conn, "UPDATE {app}.claims SET final_report_date = COALESCE(final_report_date, ?) WHERE claim_id = ?", [$date, $claim_id]);
        insertClaimEvent($conn, $claim_id, $company_id, 'updated', 'Estimasi kerugian (' . CLAIM_RESERVE_LABELS[$source] . '): '
            . claimIdr((int)$claim['estimated_loss']) . ' → ' . claimIdr($amount) . ($note ? ". $note" : ''), $username, null, null, null, $date);
    });
    getClaimDetail($conn, $company_id, $claim_id);
}

/** Re-derives settlement_amount / payment_date (and the paid status) from the payment rows. */
function claimSyncPayments($conn, string $claim_id): array {
    $t = dbOne($conn, "SELECT COALESCE(SUM(amount), 0) AS total, MAX(payment_date) AS last_date, COALESCE(SUM(payment_type = 'final'), 0) AS finals, COUNT(*) AS n
        FROM {app}.claim_payments WHERE claim_id = ?", [$claim_id]);
    dbExec($conn, "UPDATE {app}.claims SET settlement_amount = ?, payment_date = ? WHERE claim_id = ?",
        [(int)$t['n'] > 0 ? (int)$t['total'] : null, $t['last_date'], $claim_id]);
    return ['total' => (int)$t['total'], 'has_final' => (int)$t['finals'] > 0, 'count' => (int)$t['n']];
}

function addClaimPayment($conn, string $company_id, string $claim_id, array $in, string $username): void {
    $claim = findClaim($conn, $claim_id, $company_id);
    if (!in_array($claim['status'], ['approved', 'partially_paid'], true)) {
        throw ApiException::from('ERR_V21_015', ['why' => 'klaim harus berstatus Disetujui atau Dibayar Sebagian']);
    }
    $date = inDate($in, 'payment_date', true);
    if ($date > date('Y-m-d')) throw ApiException::from('ERR_CLAIM_004', ['field' => 'payment_date']);
    if ($date < $claim['date_of_loss']) throw ApiException::from('ERR_CLAIM_016');
    $amount = inMoney($in, 'amount', true);
    if ($amount <= 0) throw ApiException::from('ERR_V21_001', ['field' => 'amount']);
    $type = inEnum($in, 'payment_type', ['interim', 'final']) ?? 'interim';
    $paid = (int)dbValue($conn, "SELECT COALESCE(SUM(amount), 0) FROM {app}.claim_payments WHERE claim_id = ?", [$claim_id]);
    if ($claim['approved_amount'] !== null && $paid + $amount > $claim['approved_amount']) throw ApiException::from('ERR_V21_016');

    dbTransaction($conn, function () use ($conn, $claim, $claim_id, $company_id, $date, $amount, $type, $in, $username) {
        dbInsert($conn, 'claim_payments', ['payment_id' => dbNewId('cpy'), 'claim_id' => $claim_id, 'company_id' => $company_id,
            'payment_date' => $date, 'amount' => $amount, 'payment_type' => $type, 'payer_name' => inText($in, 'payer_name', 200),
            'reference' => inText($in, 'reference', 100), 'note' => inText($in, 'note', 500), 'created_by' => $username]);
        $sum = claimSyncPayments($conn, $claim_id);
        $full = $sum['has_final'] || ($claim['approved_amount'] !== null && $sum['total'] >= $claim['approved_amount']);
        $to = $full ? 'paid' : 'partially_paid';
        if ($to !== $claim['status']) {
            dbExec($conn, "UPDATE {app}.claims SET status = ?, status_changed_at = NOW(), updated_by = ? WHERE claim_id = ?", [$to, $username, $claim_id]);
            insertClaimEvent($conn, $claim_id, $company_id, 'status_changed', CLAIM_STATUS_LABELS[$claim['status']] . ' → ' . CLAIM_STATUS_LABELS[$to],
                $username, $claim['status'], $to, null, $date);
        }
        insertClaimEvent($conn, $claim_id, $company_id, 'updated', ($type === 'final' ? 'Pembayaran akhir ' : 'Pembayaran sebagian ') . claimIdr($amount)
            . (($p = inText($in, 'payer_name', 200)) ? " dari $p" : '') . '. Total dibayar ' . claimIdr($sum['total']), $username, null, null, null, $date);
        insertPolicyLog($conn, $claim['policy_id'], $company_id, 'claim_status_changed', "Klaim {$claim['claim_number']}: pembayaran " . claimIdr($amount),
            $username, $claim['status'], $to, 'claims', $claim_id);
    });
    getClaimDetail($conn, $company_id, $claim_id);
}

function deleteClaimPayment($conn, string $company_id, string $claim_id, string $payment_id, string $username): void {
    $claim = findClaim($conn, $claim_id, $company_id);
    $pay = dbOne($conn, "SELECT amount, payment_date FROM {app}.claim_payments WHERE payment_id = ? AND claim_id = ?", [$payment_id, $claim_id]);
    if (!$pay) throw ApiException::from('ERR_V21_004');
    dbTransaction($conn, function () use ($conn, $claim, $claim_id, $company_id, $payment_id, $pay, $username) {
        dbExec($conn, "DELETE FROM {app}.claim_payments WHERE payment_id = ?", [$payment_id]);
        $sum = claimSyncPayments($conn, $claim_id);
        $to = $sum['count'] === 0 ? 'approved' : ($sum['has_final'] ? 'paid' : 'partially_paid');
        if (in_array($claim['status'], ['partially_paid', 'paid'], true) && $to !== $claim['status']) {
            dbExec($conn, "UPDATE {app}.claims SET status = ?, status_changed_at = NOW() WHERE claim_id = ?", [$to, $claim_id]);
            insertClaimEvent($conn, $claim_id, $company_id, 'status_changed', CLAIM_STATUS_LABELS[$claim['status']] . ' → ' . CLAIM_STATUS_LABELS[$to] . ' (pembayaran dihapus)',
                $username, $claim['status'], $to);
        }
        insertClaimEvent($conn, $claim_id, $company_id, 'updated', 'Pembayaran ' . claimIdr((int)$pay['amount']) . ' tanggal ' . $pay['payment_date'] . ' dihapus', $username);
    });
    getClaimDetail($conn, $company_id, $claim_id);
}

function addClaimRecovery($conn, string $company_id, string $claim_id, array $in, string $username): void {
    $claim = findClaim($conn, $claim_id, $company_id);
    $row = [
        'recovery_type' => inEnum($in, 'recovery_type', ['salvage', 'subrogation', 'other'], true),
        'party' => inText($in, 'party', 200), 'amount' => inMoney($in, 'amount', true),
        'recovery_date' => inDate($in, 'recovery_date'), 'returned_to_insured' => inMoney($in, 'returned_to_insured') ?? 0,
        'note' => inText($in, 'note', 500),
    ];
    if ($row['returned_to_insured'] > $row['amount']) throw ApiException::from('ERR_V21_001', ['field' => 'returned_to_insured']);
    $labels = ['salvage' => 'Salvage', 'subrogation' => 'Subrogasi', 'other' => 'Pemulihan'];
    dbInsert($conn, 'claim_recoveries', $row + ['recovery_id' => dbNewId('crc'), 'claim_id' => $claim_id, 'company_id' => $company_id, 'created_by' => $username]);
    insertClaimEvent($conn, $claim_id, $company_id, 'note', $labels[$row['recovery_type']] . ' ' . claimIdr($row['amount'])
        . ($row['party'] ? ' dari ' . $row['party'] : '') . ($row['returned_to_insured'] ? ', dikembalikan ke tertanggung ' . claimIdr($row['returned_to_insured']) : ''),
        $username, null, null, null, $row['recovery_date']);
    getClaimDetail($conn, $company_id, $claim['claim_id']);
}

function deleteClaimRecovery($conn, string $company_id, string $claim_id, string $recovery_id, string $username): void {
    findClaim($conn, $claim_id, $company_id);
    if (dbExec($conn, "DELETE FROM {app}.claim_recoveries WHERE recovery_id = ? AND claim_id = ?", [$recovery_id, $claim_id]) === 0) throw ApiException::from('ERR_V21_004');
    insertClaimEvent($conn, $claim_id, $company_id, 'note', 'Catatan pemulihan dihapus', $username);
    getClaimDetail($conn, $company_id, $claim_id);
}
