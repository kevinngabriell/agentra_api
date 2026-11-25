<?php

// Fleet schedule (v2.1.0). Each vehicle is one coverage item of type 'kendaraan' (sum insured, rate,
// premium; so policy totals, deductibles per item and renewal copying work unchanged) plus its
// identification in policy_vehicles. Functions only; routing is in policies/index.php.
//
//   GET    /policies/{id}/vehicles               schedule with totals
//   POST   /policies/{id}/vehicles               add one vehicle (an endorsement unless change_kind = correction)
//   POST   /policies/{id}/vehicles/import        add many (rows[], at most 500), billed as one endorsement
//   PUT    /policies/{id}/vehicles/{vid}         edit identification and/or sum insured / rate
//   DELETE /policies/{id}/vehicles/{vid}         remove (return premium on the endorsement note)
//
// Rates: rate_percent (motor tariffs are quoted in %) or rate_permille.

require_once __DIR__ . '/../helpers/schedules.php';
require_once __DIR__ . '/../helpers/coverage.php';
require_once __DIR__ . '/../helpers/endorsements.php';
require_once __DIR__ . '/../helpers/policy_change.php';
require_once __DIR__ . '/../helpers/policy_log.php';

const FLEET_IMPORT_MAX = 500;

function vehFindPolicy($conn, string $company_id, string $policy_id): array {
    $p = dbOne($conn, "SELECT policy_id, renewal_status FROM {app}.policies WHERE policy_id = ? AND company_id = ?", [$policy_id, $company_id]);
    if (!$p) throw ApiException::from('ERR_POLICY_001');
    return $p;
}

function vehSelect(): string {
    return "SELECT v.*, c.sum_insured, c.rate_permille, c.premium_amount, c.count_in_tsi
        FROM {app}.policy_vehicles v JOIN {app}.policy_coverages c ON c.coverage_id = v.coverage_id";
}

function vehShape(array $r): array {
    foreach (['ext_flood', 'ext_earthquake', 'ext_rsmdcc', 'ext_terrorism', 'count_in_tsi'] as $c) $r[$c] = (bool)(int)$r[$c];
    foreach (['vehicle_year', 'tariff_region', 'tpl_limit', 'pa_driver_limit', 'pa_passenger_limit', 'sum_insured', 'premium_amount'] as $c) {
        if ($r[$c] !== null) $r[$c] = (int)$r[$c];
    }
    $r['rate_permille'] = (float)$r['rate_permille'];
    $r['rate_percent'] = round($r['rate_permille'] / 10, 4);
    return $r;
}

/** Rate from rate_percent or rate_permille, as permille. */
function vehRatePermille(array $in, bool $required): ?float {
    if (isset($in['rate_percent']) && $in['rate_percent'] !== '') return inNumber($in, 'rate_percent', 0, 100, true) * 10;
    return inNumber($in, 'rate_permille', 0, RATE_PERMILLE_MAX, $required);
}

function vehLabel(array $v): string {
    $parts = array_filter([$v['plate_number'] ?? null, trim(($v['vehicle_make'] ?? '') . ' ' . ($v['vehicle_model'] ?? '')) ?: null]);
    return mb_substr($parts ? implode(' · ', $parts) : 'Kendaraan', 0, 100);
}

function vehAssertUniquePlate($conn, string $policy_id, ?string $plate, ?string $except = null): void {
    if ($plate === null) return;
    $norm = str_replace([' ', '-'], '', $plate);
    $dup = dbValue($conn, "SELECT vehicle_id FROM {app}.policy_vehicles WHERE policy_id = ? AND REPLACE(REPLACE(plate_number, ' ', ''), '-', '') = ?" . ($except ? ' AND vehicle_id <> ?' : '') . ' LIMIT 1',
        $except ? [$policy_id, $norm, $except] : [$policy_id, $norm]);
    if ($dup) throw ApiException::from('ERR_V21_011', ['plate' => $plate]);
}

function listVehicles($conn, string $company_id, string $policy_id): void {
    vehFindPolicy($conn, $company_id, $policy_id);
    $rows = array_map('vehShape', dbAll($conn, vehSelect() . " WHERE v.policy_id = ? ORDER BY v.created_at", [$policy_id]));
    jsonResponse(200, 'Vehicles retrieved', [
        'items' => $rows,
        'summary' => [
            'vehicle_count' => count($rows),
            'total_sum_insured' => array_sum(array_column($rows, 'sum_insured')),
            'total_premium' => array_sum(array_column($rows, 'premium_amount')),
            'comprehensive_count' => count(array_filter($rows, fn($r) => $r['motor_coverage_type'] === 'comprehensive')),
            'tlo_count' => count(array_filter($rows, fn($r) => $r['motor_coverage_type'] === 'tlo')),
        ],
    ]);
}

/** Inserts the coverage item and vehicle row for one parsed vehicle. Caller owns the transaction and the totals. */
function vehInsertOne($conn, string $company_id, string $policy_id, array $in, string $username): array {
    $v = array_map(fn($p) => $p($in), vehicleParsers());
    $si = inMoney($in, 'sum_insured', true);
    $rate = vehRatePermille($in, true);
    vehAssertUniquePlate($conn, $policy_id, $v['plate_number']);
    $parsed = parseCoverageInput(['coverage_type' => 'kendaraan', 'coverage_label' => vehLabel($v), 'sum_insured' => $si, 'rate_permille' => $rate]);
    if (isset($parsed['error'])) throw ApiException::from('ERR_V21_001', ['field' => 'sum_insured']);
    $coverage_id = insertCoverageRow($conn, $policy_id, $parsed['row'], $username, date('Y-m-d H:i:s'));
    if ($coverage_id === null) throw new RuntimeException('vehicle coverage insert failed');
    $vehicle_id = dbNewId('veh');
    dbInsert($conn, 'policy_vehicles', $v + ['vehicle_id' => $vehicle_id, 'company_id' => $company_id, 'policy_id' => $policy_id,
        'coverage_id' => $coverage_id, 'created_by' => $username]);
    return ['vehicle_id' => $vehicle_id, 'coverage_id' => $coverage_id, 'label' => $parsed['row']['coverage_label'], 'premium' => $parsed['row']['premium_amount']];
}

function addVehicle($conn, string $company_id, string $policy_id, array $in, string $username): void {
    $p = vehFindPolicy($conn, $company_id, $policy_id);
    if ($p['renewal_status'] === 'cancelled') throw ApiException::from('ERR_V21_014');
    $change = policyChangeParse($in);
    policyChangeGuard($conn, $policy_id, $company_id, $change);
    $billing = endBillingParse($in);
    $before = policyContractTotals($conn, $policy_id);

    $created = dbTransaction($conn, fn() => vehInsertOne($conn, $company_id, $policy_id, $in, $username));
    syncPolicyTotals($conn, $policy_id, $change['kind']);
    $ledger = endFromContractChange($conn, $company_id, $policy_id, $change, $billing, $before, policyContractTotals($conn, $policy_id),
        'add_item', 'Tambah kendaraan ' . $created['label'], 'vehicle', $username, 'policy_vehicles', $created['vehicle_id']);
    policyChangeLog($conn, $policy_id, $company_id, $change, 'kendaraan ditambahkan — ' . $created['label'], $username,
        'policy_vehicles', $created['vehicle_id'], ['premium_amount' => $created['premium']]);

    $row = vehShape(dbOne($conn, vehSelect() . " WHERE v.vehicle_id = ?", [$created['vehicle_id']]));
    jsonResponse(201, 'Vehicle added', ['vehicle' => $row, 'endorsement' => $ledger]);
}

function importVehicles($conn, string $company_id, string $policy_id, array $in, string $username): void {
    $p = vehFindPolicy($conn, $company_id, $policy_id);
    if ($p['renewal_status'] === 'cancelled') throw ApiException::from('ERR_V21_014');
    $rows = $in['rows'] ?? null;
    if (!is_array($rows) || !$rows) throw ApiException::from('ERR_V21_002', ['field' => 'rows']);
    if (count($rows) > FLEET_IMPORT_MAX) throw ApiException::from('ERR_V21_012', ['max' => FLEET_IMPORT_MAX]);
    $change = policyChangeParse($in);
    policyChangeGuard($conn, $policy_id, $company_id, $change);
    $billing = endBillingParse($in);
    $before = policyContractTotals($conn, $policy_id);

    // All-or-nothing: the first bad row stops the import and names its row number.
    $created = dbTransaction($conn, function () use ($conn, $company_id, $policy_id, $rows, $username) {
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            try {
                $out[] = vehInsertOne($conn, $company_id, $policy_id, is_array($r) ? $r : [], $username);
            } catch (ApiException $e) {
                throw new ApiException($e->errCode, $e->httpStatus, 'Baris ' . ($i + 1) . ': ' . $e->getMessage(), $e->details + ['row' => $i + 1]);
            }
        }
        return $out;
    });
    syncPolicyTotals($conn, $policy_id, $change['kind']);
    $ledger = endFromContractChange($conn, $company_id, $policy_id, $change, $billing, $before, policyContractTotals($conn, $policy_id),
        'add_item', 'Tambah ' . count($created) . ' kendaraan (impor)', 'vehicle', $username, 'policies', $policy_id);
    policyChangeLog($conn, $policy_id, $company_id, $change, count($created) . ' kendaraan diimpor ke jadwal armada', $username,
        'policy_vehicles', null, ['count' => count($created)]);
    jsonResponse(201, 'Vehicles imported', ['imported' => count($created), 'endorsement' => $ledger]);
}

function updateVehicle($conn, string $company_id, string $policy_id, string $vehicle_id, array $in, string $username): void {
    $p = vehFindPolicy($conn, $company_id, $policy_id);
    if ($p['renewal_status'] === 'cancelled') throw ApiException::from('ERR_V21_014');
    $cur = dbOne($conn, vehSelect() . " WHERE v.vehicle_id = ? AND v.policy_id = ?", [$vehicle_id, $policy_id]);
    if (!$cur) throw ApiException::from('ERR_V21_004');
    $set = inPresent($in, vehicleParsers());
    if (array_key_exists('plate_number', $set)) vehAssertUniquePlate($conn, $policy_id, $set['plate_number'], $vehicle_id);
    $si = inMoney($in, 'sum_insured');
    $rate = vehRatePermille($in, false);
    $moneyChanged = ($si !== null && $si !== (int)$cur['sum_insured']) || ($rate !== null && abs($rate - (float)$cur['rate_permille']) > 0.00001);

    // Identification edits (colour, engine number...) are data maintenance; only money changes are classified.
    $change = $moneyChanged
        ? policyChangeParse($in)
        : ['kind' => 'correction', 'reason' => 'Perbarui data kendaraan', 'endorsement_number' => null, 'effective_date' => null];
    if ($moneyChanged) policyChangeGuard($conn, $policy_id, $company_id, $change);
    $billing = endBillingParse($in);
    $before = policyContractTotals($conn, $policy_id);

    dbTransaction($conn, function () use ($conn, $set, $si, $rate, $cur, $vehicle_id, $username) {
        if ($set) dbUpdate($conn, 'policy_vehicles', $set + ['updated_by' => $username], ['vehicle_id' => $vehicle_id]);
        $merged = array_merge($cur, $set);
        $finalSi = $si ?? (int)$cur['sum_insured'];
        $finalRate = $rate ?? (float)$cur['rate_permille'];
        dbExec($conn, "UPDATE {app}.policy_coverages SET coverage_label = ?, sum_insured = ?, rate_permille = ?, premium_amount = ?, updated_by = ? WHERE coverage_id = ?",
            [vehLabel($merged), $finalSi, round($finalRate, 4), (int)round($finalSi * $finalRate / 1000), $username, $cur['coverage_id']]);
    });

    $ledger = null;
    if ($moneyChanged) {
        syncPolicyTotals($conn, $policy_id, $change['kind']);
        $ledger = endFromContractChange($conn, $company_id, $policy_id, $change, $billing, $before, policyContractTotals($conn, $policy_id),
            'sum_insured_change', 'Perubahan kendaraan ' . vehLabel(array_merge($cur, $set)), 'vehicle', $username, 'policy_vehicles', $vehicle_id);
    }
    policyChangeLog($conn, $policy_id, $company_id, $change, 'kendaraan diperbarui — ' . vehLabel(array_merge($cur, $set)), $username,
        'policy_vehicles', $vehicle_id, ['fields' => array_keys($set), 'sum_insured' => $si, 'rate_permille' => $rate]);
    jsonResponse(200, 'Vehicle updated', ['vehicle' => vehShape(dbOne($conn, vehSelect() . " WHERE v.vehicle_id = ?", [$vehicle_id])), 'endorsement' => $ledger]);
}

function deleteVehicle($conn, string $company_id, string $policy_id, string $vehicle_id, array $in, string $username): void {
    $p = vehFindPolicy($conn, $company_id, $policy_id);
    if ($p['renewal_status'] === 'cancelled') throw ApiException::from('ERR_V21_014');
    $cur = dbOne($conn, vehSelect() . " WHERE v.vehicle_id = ? AND v.policy_id = ?", [$vehicle_id, $policy_id]);
    if (!$cur) throw ApiException::from('ERR_V21_004');
    $change = policyChangeParse($in);
    policyChangeGuard($conn, $policy_id, $company_id, $change);
    $billing = endBillingParse($in);
    $before = policyContractTotals($conn, $policy_id);

    dbExec($conn, "DELETE FROM {app}.policy_coverages WHERE coverage_id = ? AND policy_id = ?", [$cur['coverage_id'], $policy_id]); // cascades the vehicle row
    syncPolicyTotals($conn, $policy_id, $change['kind']);
    $label = vehLabel($cur);
    $ledger = endFromContractChange($conn, $company_id, $policy_id, $change, $billing, $before, policyContractTotals($conn, $policy_id),
        'remove_item', 'Hapus kendaraan ' . $label, 'vehicle', $username, 'policy_vehicles', $vehicle_id);
    policyChangeLog($conn, $policy_id, $company_id, $change, 'kendaraan dihapus — ' . $label, $username, 'policy_vehicles', $vehicle_id,
        ['sum_insured' => (int)$cur['sum_insured'], 'premium_amount' => (int)$cur['premium_amount']]);
    jsonResponse(200, 'Vehicle removed', ['endorsement' => $ledger]);
}
