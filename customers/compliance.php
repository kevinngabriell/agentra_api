<?php

// [v2.1.0] APU-PPT compliance for large / corporate premiums. Functions only; routing in customers/index.php.
//
//   GET  /customers/{id}/compliance                       risk rating, PEP, screening, beneficial owners, EDD flag
//   PUT  /customers/{id}/compliance                       {risk_rating, is_pep, screening_status, screened_at, screening_lists, screening_note}
//   POST /customers/{id}/beneficial-owners                add an owner
//   PUT  /customers/{id}/beneficial-owners/{owner_id}     edit
//   DELETE /customers/{id}/beneficial-owners/{owner_id}
//
// The app records the result of a screening the agent ran (DTTOT / PEP lists); it does not query those lists.

require_once __DIR__ . '/../helpers/db.php';

const CUSTOMER_SCREENING_STATUSES = ['not_screened', 'clear', 'potential_match', 'confirmed_match'];
// Annual premium above which a customer is flagged for enhanced due diligence when not yet rated low.
const EDD_PREMIUM_THRESHOLD = 1_000_000_000;

function complianceFind($conn, string $company_id, string $customer_id): array {
    $c = dbOne($conn, "SELECT customer_id, customer_type, display_name, risk_rating, is_pep, screening_status, screened_at, screening_lists, screening_note
        FROM {app}.customers WHERE customer_id = ? AND company_id = ?", [$customer_id, $company_id]);
    if (!$c) throw ApiException::from('ERR_CUST_001');
    return $c;
}

/** Why the customer needs enhanced due diligence (empty = not required). */
function complianceEddReasons(array $c, array $owners, int $annual_premium): array {
    $why = [];
    if ($c['risk_rating'] === 'high') $why[] = 'Profil risiko tinggi';
    if ((int)$c['is_pep'] === 1) $why[] = 'Nasabah adalah PEP';
    foreach ($owners as $o) if ((int)$o['is_pep'] === 1) { $why[] = 'Pemilik manfaat ' . $o['full_name'] . ' adalah PEP'; break; }
    if (in_array($c['screening_status'], ['potential_match', 'confirmed_match'], true)) $why[] = 'Hasil screening DTTOT/PEP: kemungkinan cocok';
    if ($c['customer_type'] === 'company' && !$owners) $why[] = 'Pemilik manfaat (beneficial owner) belum dicatat';
    if ($annual_premium >= EDD_PREMIUM_THRESHOLD && $c['risk_rating'] !== 'low') $why[] = 'Premi tahunan di atas Rp ' . number_format(EDD_PREMIUM_THRESHOLD, 0, ',', '.');
    if ($c['screening_status'] === 'not_screened') $why[] = 'Belum pernah di-screening';
    return $why;
}

function complianceShape($conn, string $company_id, array $c): array {
    $owners = dbAll($conn, "SELECT owner_id, full_name, id_number, nationality, ownership_percent, role, is_pep, notes, created_at
        FROM {app}.customer_beneficial_owners WHERE customer_id = ? AND company_id = ? ORDER BY ownership_percent DESC, created_at", [$c['customer_id'], $company_id]);
    $premium = (int)dbValue($conn, "SELECT COALESCE(SUM(premium_amount), 0) FROM {app}.policies
        WHERE customer_id = ? AND company_id = ? AND renewal_status IN ('pending', 'renewed') AND coverage_end >= CURDATE()", [$c['customer_id'], $company_id]);
    $reasons = complianceEddReasons($c, $owners, $premium);
    foreach ($owners as &$o) {
        $o['is_pep'] = (bool)(int)$o['is_pep'];
        $o['ownership_percent'] = $o['ownership_percent'] === null ? null : (float)$o['ownership_percent'];
    }
    unset($o);
    return [
        'customer_id' => $c['customer_id'], 'risk_rating' => $c['risk_rating'], 'is_pep' => (bool)(int)$c['is_pep'],
        'screening_status' => $c['screening_status'], 'screened_at' => $c['screened_at'],
        'screening_lists' => $c['screening_lists'], 'screening_note' => $c['screening_note'],
        'beneficial_owners' => $owners, 'in_force_premium' => $premium,
        // Only "not screened" alone is a to-do, not EDD: EDD is for the real risk signals.
        'edd_required' => (bool)array_filter($reasons, fn($r) => $r !== 'Belum pernah di-screening'),
        'edd_reasons' => $reasons,
        'screening_stale' => $c['screened_at'] !== null && $c['screened_at'] < date('Y-m-d', strtotime('-12 months')),
    ];
}

function getCustomerCompliance($conn, string $company_id, string $customer_id): void {
    jsonResponse(200, 'Compliance retrieved', complianceShape($conn, $company_id, complianceFind($conn, $company_id, $customer_id)));
}

function updateCustomerCompliance($conn, string $company_id, string $customer_id, array $in, string $username): void {
    complianceFind($conn, $company_id, $customer_id);
    $set = inPresent($in, [
        'risk_rating' => fn($i) => inEnum($i, 'risk_rating', ['low', 'medium', 'high']),
        'is_pep' => fn($i) => inBool($i, 'is_pep') ?? 0,
        'screening_status' => fn($i) => inEnum($i, 'screening_status', CUSTOMER_SCREENING_STATUSES, true),
        'screened_at' => fn($i) => inDate($i, 'screened_at'),
        'screening_lists' => fn($i) => inText($i, 'screening_lists', 200),
        'screening_note' => fn($i) => inText($i, 'screening_note', 500),
    ]);
    if (isset($set['screening_status']) && $set['screening_status'] !== 'not_screened' && empty($set['screened_at'])) $set['screened_at'] = date('Y-m-d');
    if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
    dbUpdate($conn, 'customers', $set + ['updated_by' => $username], ['customer_id' => $customer_id, 'company_id' => $company_id]);
    getCustomerCompliance($conn, $company_id, $customer_id);
}

function saveBeneficialOwner($conn, string $company_id, string $customer_id, ?string $owner_id, array $in, string $username): void {
    complianceFind($conn, $company_id, $customer_id);
    $parsers = [
        'full_name' => fn($i) => inText($i, 'full_name', 200, true),
        'id_number' => fn($i) => inText($i, 'id_number', 40),
        'nationality' => fn($i) => inText($i, 'nationality', 60),
        'ownership_percent' => fn($i) => inNumber($i, 'ownership_percent', 0, 100),
        'role' => fn($i) => inText($i, 'role', 100),
        'is_pep' => fn($i) => inBool($i, 'is_pep') ?? 0,
        'notes' => fn($i) => inText($i, 'notes', 300),
    ];
    dbTransaction($conn, function () use ($conn, $parsers, $in, $owner_id, $company_id, $customer_id, $username) {
        if ($owner_id === null) {
            dbInsert($conn, 'customer_beneficial_owners', array_map(fn($p) => $p($in), $parsers)
                + ['owner_id' => dbNewId('cbo'), 'company_id' => $company_id, 'customer_id' => $customer_id, 'created_by' => $username]);
        } else {
            if (!dbOne($conn, "SELECT 1 FROM {app}.customer_beneficial_owners WHERE owner_id = ? AND customer_id = ?", [$owner_id, $customer_id])) {
                throw ApiException::from('ERR_V21_004');
            }
            $set = inPresent($in, $parsers);
            if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
            dbUpdate($conn, 'customer_beneficial_owners', $set, ['owner_id' => $owner_id, 'customer_id' => $customer_id]);
        }
        $total = (float)dbValue($conn, "SELECT COALESCE(SUM(ownership_percent), 0) FROM {app}.customer_beneficial_owners WHERE customer_id = ?", [$customer_id]);
        if ($total > 100.001) throw ApiException::from('ERR_V21_001', ['field' => 'ownership_percent (total > 100%)']);
    });
    getCustomerCompliance($conn, $company_id, $customer_id);
}

function deleteBeneficialOwner($conn, string $company_id, string $customer_id, string $owner_id): void {
    complianceFind($conn, $company_id, $customer_id);
    if (dbExec($conn, "DELETE FROM {app}.customer_beneficial_owners WHERE owner_id = ? AND customer_id = ?", [$owner_id, $customer_id]) === 0) {
        throw ApiException::from('ERR_V21_004');
    }
    getCustomerCompliance($conn, $company_id, $customer_id);
}
