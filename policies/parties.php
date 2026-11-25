<?php

// Interested parties (banks, leasing companies, loss payees) and multi-year schedules (v2.1.0).
// Functions only; routing is in policies/index.php.
//
//   GET|POST /policies/{id}/parties              PUT|DELETE /policies/{id}/parties/{party_id}
//   GET      /policies/{id}/years                multi-year schedule
//   POST     /policies/{id}/years/generate       (re)build it: years, depreciation_percent per year
//   PUT      /policies/{id}/years/{year_id}      edit a year (sum insured, premium, due / paid date)
//   DELETE   /policies/{id}/years                clear the schedule

require_once __DIR__ . '/../helpers/schedules.php';
require_once __DIR__ . '/../helpers/policy_log.php';

function ptyFindPolicy($conn, string $company_id, string $policy_id): array {
    $p = dbOne($conn, "SELECT p.policy_id, p.policy_number, p.coverage_start, p.coverage_end, p.sum_insured, p.premium_amount,
            COALESCE(p.insured_name, c.display_name) AS insured
        FROM {app}.policies p JOIN {app}.customers c ON c.customer_id = p.customer_id WHERE p.policy_id = ? AND p.company_id = ?", [$policy_id, $company_id]);
    if (!$p) throw ApiException::from('ERR_POLICY_001');
    return $p;
}

function listParties($conn, string $company_id, string $policy_id): void {
    $p = ptyFindPolicy($conn, $company_id, $policy_id);
    $rows = array_map('partyShape', dbAll($conn, "SELECT * FROM {app}.policy_parties WHERE policy_id = ? ORDER BY created_at", [$policy_id]));
    jsonResponse(200, 'Parties retrieved', ['items' => $rows, 'qq_name' => policyQqName($conn, $policy_id, $p['insured'])]);
}

function saveParty($conn, string $company_id, string $policy_id, ?string $party_id, array $in, string $username): void {
    $p = ptyFindPolicy($conn, $company_id, $policy_id);
    $parsers = partyParsers();
    if ($party_id === null) {
        $set = array_map(fn($f) => $f($in), $parsers);
        $party_id = dbNewId('pty');
        dbInsert($conn, 'policy_parties', $set + ['party_id' => $party_id, 'company_id' => $company_id, 'policy_id' => $policy_id, 'created_by' => $username]);
        insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', (PARTY_TYPE_LABELS[$set['party_type']] ?? 'Pihak') . ' ditambahkan: ' . $set['name'],
            $username, null, null, 'policy_parties', $party_id);
        $status = 201;
    } else {
        if (!dbOne($conn, "SELECT 1 FROM {app}.policy_parties WHERE party_id = ? AND policy_id = ?", [$party_id, $policy_id])) throw ApiException::from('ERR_V21_004');
        unset($parsers['qq_in_name'], $parsers['notify_renewal']);
        $set = inPresent($in, $parsers);
        foreach (['qq_in_name', 'notify_renewal'] as $b) if (array_key_exists($b, $in)) $set[$b] = inBool($in, $b) ?? 0;
        if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
        dbUpdate($conn, 'policy_parties', $set + ['updated_by' => $username], ['party_id' => $party_id]);
        if (!empty($set['proof_sent_date'])) {
            insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Bukti polis dikirim ke ' . dbValue($conn, "SELECT name FROM {app}.policy_parties WHERE party_id = ?", [$party_id]) . ' pada ' . $set['proof_sent_date'],
                $username, null, null, 'policy_parties', $party_id);
        }
        $status = 200;
    }
    $row = partyShape(dbOne($conn, "SELECT * FROM {app}.policy_parties WHERE party_id = ?", [$party_id]));
    jsonResponse($status, 'Party saved', ['party' => $row, 'qq_name' => policyQqName($conn, $policy_id, $p['insured'])]);
}

function deleteParty($conn, string $company_id, string $policy_id, string $party_id, string $username): void {
    ptyFindPolicy($conn, $company_id, $policy_id);
    $name = dbValue($conn, "SELECT name FROM {app}.policy_parties WHERE party_id = ? AND policy_id = ?", [$party_id, $policy_id]);
    if ($name === null) throw ApiException::from('ERR_V21_004');
    dbExec($conn, "DELETE FROM {app}.policy_parties WHERE party_id = ?", [$party_id]);
    insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Pihak dihapus: ' . $name, $username, null, null, 'policy_parties', $party_id);
    jsonResponse(200, 'Party deleted');
}

// ── Multi-year schedule ──────────────────────────────────────────────────────

function yearShape(array $r): array {
    foreach (['year_no', 'sum_insured', 'premium_amount'] as $c) $r[$c] = (int)$r[$c];
    $r['is_paid'] = $r['paid_date'] !== null;
    return $r;
}

function listYears($conn, string $company_id, string $policy_id): void {
    ptyFindPolicy($conn, $company_id, $policy_id);
    $rows = array_map('yearShape', dbAll($conn, "SELECT * FROM {app}.policy_year_schedule WHERE policy_id = ? ORDER BY year_no", [$policy_id]));
    jsonResponse(200, 'Year schedule retrieved', [
        'items' => $rows,
        'total_premium' => array_sum(array_column($rows, 'premium_amount')),
        'unpaid_premium' => array_sum(array_map(fn($r) => $r['is_paid'] ? 0 : $r['premium_amount'], $rows)),
    ]);
}

/**
 * Builds the yearly rows from the policy: year 1 = the policy's sum insured and premium; each next year the
 * sum insured falls by depreciation_percent (vehicle / leasing schedules) and the premium keeps the same rate.
 * Pure function for the maths; dates are yearly anniversaries of coverage_start, the last one ending on coverage_end.
 */
function yearScheduleRows(string $start, string $end, int $sum_insured, int $premium, int $years, float $dep_percent): array {
    $rate = $sum_insured > 0 ? $premium / $sum_insured : 0;
    $rows = [];
    $si = $sum_insured;
    for ($y = 1; $y <= $years; $y++) {
        $ps = date('Y-m-d', strtotime($start . ' +' . ($y - 1) . ' year'));
        // Yearly anniversaries; the last year ends on the policy end date when that falls near the anniversary
        // (a policy period of 3 years and a few days), never before the year starts.
        $pe = date('Y-m-d', strtotime($start . " +$y year"));
        if ($y === $years && $end > $ps && abs((strtotime($end) - strtotime($pe)) / 86400) <= 31) $pe = $end;
        if ($y > 1) $si = (int)round($si * (1 - $dep_percent / 100));
        $rows[] = ['year_no' => $y, 'period_start' => $ps, 'period_end' => $pe, 'sum_insured' => $si,
                   'premium_amount' => $y === 1 ? $premium : (int)round($si * $rate), 'due_date' => $ps];
    }
    return $rows;
}

function generateYears($conn, string $company_id, string $policy_id, array $in, string $username): void {
    $p = ptyFindPolicy($conn, $company_id, $policy_id);
    $periodYears = max(1, (int)ceil(max(1, (strtotime($p['coverage_end']) - strtotime($p['coverage_start'])) / 86400 - 5) / 365));
    $years = inInt($in, 'years', 2, 10) ?? $periodYears;
    $dep = inNumber($in, 'depreciation_percent', 0, 50) ?? 0.0;
    $rows = yearScheduleRows($p['coverage_start'], $p['coverage_end'], (int)$p['sum_insured'], (int)$p['premium_amount'], $years, $dep);
    dbTransaction($conn, function () use ($conn, $rows, $policy_id, $company_id, $username) {
        dbExec($conn, "DELETE FROM {app}.policy_year_schedule WHERE policy_id = ? AND paid_date IS NULL", [$policy_id]);
        $paid = array_map('intval', array_column(dbAll($conn, "SELECT year_no FROM {app}.policy_year_schedule WHERE policy_id = ?", [$policy_id]), 'year_no'));
        foreach ($rows as $r) {
            if (in_array($r['year_no'], $paid, true)) continue; // a paid year is history and stays as recorded
            dbInsert($conn, 'policy_year_schedule', $r + ['year_id' => dbNewId('pyr'), 'company_id' => $company_id, 'policy_id' => $policy_id, 'created_by' => $username]);
        }
    });
    insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', "Jadwal multi-tahun dibuat: $years tahun, penyusutan $dep% per tahun", $username);
    listYears($conn, $company_id, $policy_id);
}

function updateYear($conn, string $company_id, string $policy_id, string $year_id, array $in, string $username): void {
    ptyFindPolicy($conn, $company_id, $policy_id);
    if (!dbOne($conn, "SELECT 1 FROM {app}.policy_year_schedule WHERE year_id = ? AND policy_id = ?", [$year_id, $policy_id])) throw ApiException::from('ERR_V21_004');
    $set = inPresent($in, [
        'sum_insured' => fn($i) => inMoney($i, 'sum_insured', true),
        'premium_amount' => fn($i) => inMoney($i, 'premium_amount', true),
        'due_date' => fn($i) => inDate($i, 'due_date'),
        'paid_date' => fn($i) => inDate($i, 'paid_date'),
        'payment_reference' => fn($i) => inText($i, 'payment_reference', 100),
        'notes' => fn($i) => inText($i, 'notes', 300),
    ]);
    if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
    dbUpdate($conn, 'policy_year_schedule', $set, ['year_id' => $year_id]);
    if (array_key_exists('paid_date', $set)) {
        $no = dbValue($conn, "SELECT year_no FROM {app}.policy_year_schedule WHERE year_id = ?", [$year_id]);
        insertPolicyLog($conn, $policy_id, $company_id, 'payment_status_changed', "Premi tahun ke-$no " . ($set['paid_date'] ? 'dibayar ' . $set['paid_date'] : 'ditandai belum dibayar'), $username);
    }
    listYears($conn, $company_id, $policy_id);
}

function clearYears($conn, string $company_id, string $policy_id, string $username): void {
    ptyFindPolicy($conn, $company_id, $policy_id);
    dbExec($conn, "DELETE FROM {app}.policy_year_schedule WHERE policy_id = ? AND paid_date IS NULL", [$policy_id]);
    insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Jadwal multi-tahun dihapus (tahun yang sudah dibayar tetap tersimpan)', $username);
    listYears($conn, $company_id, $policy_id);
}
