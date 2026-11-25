<?php

// Policy schedules (v2.1.0): property locations, fleet vehicles, interested parties (banks / leasing),
// multi-year schedules. Shared by their modules, renewal and the policy detail.

require_once __DIR__ . '/db.php';

// Location columns the API reads and writes: column => parser.
function locationParsers(): array {
    $t = fn(string $f, int $max) => fn(array $in) => inText($in, $f, $max);
    $b = fn(string $f) => fn(array $in) => inBool($in, $f) ?? 0;
    return [
        'label' => $t('label', 120), 'address' => $t('address', 2000), 'village' => $t('village', 100),
        'district' => $t('district', 100), 'city' => $t('city', 100), 'province' => $t('province', 100),
        'postal_code' => function (array $in) {
            $v = inText($in, 'postal_code', 10);
            if ($v !== null && !preg_match('/^\d{5}$/', $v)) throw ApiException::from('ERR_V21_001', ['field' => 'postal_code']);
            return $v;
        },
        'latitude' => fn(array $in) => inNumber($in, 'latitude', -90, 90),
        'longitude' => fn(array $in) => inNumber($in, 'longitude', -180, 180),
        'area_name' => $t('area_name', 150), 'occupation_code' => $t('occupation_code', 40),
        'occupation_description' => $t('occupation_description', 200),
        'construction_class' => fn(array $in) => inEnum($in, 'construction_class', ['I', 'II', 'III']),
        'eq_zone' => fn(array $in) => inInt($in, 'eq_zone', 1, 5),
        'flood_zone' => fn(array $in) => inEnum($in, 'flood_zone', ['low', 'medium', 'high']),
        'flood_history' => fn(array $in) => inEnum($in, 'flood_history', ['none', 'once', 'repeated']),
        'last_flood_date' => fn(array $in) => inDate($in, 'last_flood_date'),
        'wall_material' => $t('wall_material', 80), 'roof_material' => $t('roof_material', 80), 'floor_material' => $t('floor_material', 80),
        'storeys' => fn(array $in) => inInt($in, 'storeys', 1, 120),
        'year_built' => fn(array $in) => inInt($in, 'year_built', 1800, 2100),
        'floor_area_m2' => fn(array $in) => inInt($in, 'floor_area_m2', 1, 100_000_000),
        'rebuild_cost_per_m2' => fn(array $in) => inMoney($in, 'rebuild_cost_per_m2'),
        'has_apar' => $b('has_apar'), 'has_hydrant' => $b('has_hydrant'), 'has_sprinkler' => $b('has_sprinkler'),
        'has_detector' => $b('has_detector'), 'has_alarm' => $b('has_alarm'), 'has_cctv' => $b('has_cctv'),
        'has_security_guard' => $b('has_security_guard'),
        'fire_station_distance_km' => fn(array $in) => inNumber($in, 'fire_station_distance_km', 0, 9999),
        'hazardous_goods' => $t('hazardous_goods', 300),
        'rack_height_m' => fn(array $in) => inNumber($in, 'rack_height_m', 0, 999),
        'adjacent_exposure' => $t('adjacent_exposure', 300),
        'housekeeping' => fn(array $in) => inEnum($in, 'housekeeping', ['good', 'fair', 'poor']),
        'stock_ownership' => fn(array $in) => inEnum($in, 'stock_ownership', ['own', 'third_party', 'mixed']),
        'third_party_stock_note' => $t('third_party_stock_note', 300),
        'bailee_liability_covered' => $b('bailee_liability_covered'),
        'occupancy_status' => fn(array $in) => inEnum($in, 'occupancy_status', ['occupied', 'unoccupied', 'renovation', 'partly_rented']),
        'unoccupied_since' => fn(array $in) => inDate($in, 'unoccupied_since'),
        'pml_percent' => fn(array $in) => inNumber($in, 'pml_percent', 0, 100),
        'notes' => $t('notes', 2000),
    ];
}

const LOCATION_BOOL_COLUMNS = ['has_apar', 'has_hydrant', 'has_sprinkler', 'has_detector', 'has_alarm', 'has_cctv', 'has_security_guard', 'bailee_liability_covered'];
const LOCATION_INT_COLUMNS = ['seq', 'eq_zone', 'storeys', 'year_built', 'floor_area_m2', 'rebuild_cost_per_m2'];
const LOCATION_FLOAT_COLUMNS = ['latitude', 'longitude', 'fire_station_distance_km', 'rack_height_m', 'pml_percent'];

function locationShape(array $r): array {
    foreach (LOCATION_BOOL_COLUMNS as $c) if (array_key_exists($c, $r)) $r[$c] = (bool)(int)$r[$c];
    foreach (LOCATION_INT_COLUMNS as $c) if (isset($r[$c])) $r[$c] = (int)$r[$c];
    foreach (LOCATION_FLOAT_COLUMNS as $c) if (isset($r[$c])) $r[$c] = (float)$r[$c];
    return $r;
}

// Vehicle columns of a fleet schedule (policy_vehicles), validated like the single-vehicle risk details.
function vehicleParsers(): array {
    $t = fn(string $f, int $max, bool $upper = false) => function (array $in) use ($f, $max, $upper) {
        $v = inText($in, $f, $max);
        return $v !== null && $upper ? strtoupper(preg_replace('/\s+/', ' ', $v)) : $v;
    };
    $b = fn(string $f) => fn(array $in) => inBool($in, $f) ?? 0;
    return [
        'plate_number' => $t('plate_number', 20, true), 'chassis_number' => $t('chassis_number', 40, true),
        'engine_number' => $t('engine_number', 40, true), 'vehicle_make' => $t('vehicle_make', 60),
        'vehicle_model' => $t('vehicle_model', 80), 'vehicle_year' => fn(array $in) => inInt($in, 'vehicle_year', 1950, 2100),
        'vehicle_color' => $t('vehicle_color', 30),
        'vehicle_usage' => fn(array $in) => inEnum($in, 'vehicle_usage', ['private', 'commercial']),
        'motor_coverage_type' => fn(array $in) => inEnum($in, 'motor_coverage_type', ['comprehensive', 'tlo']),
        'tariff_region' => fn(array $in) => inInt($in, 'tariff_region', 1, 3),
        'ext_flood' => $b('ext_flood'), 'ext_earthquake' => $b('ext_earthquake'), 'ext_rsmdcc' => $b('ext_rsmdcc'), 'ext_terrorism' => $b('ext_terrorism'),
        'tpl_limit' => fn(array $in) => inMoney($in, 'tpl_limit'), 'pa_driver_limit' => fn(array $in) => inMoney($in, 'pa_driver_limit'),
        'pa_passenger_limit' => fn(array $in) => inMoney($in, 'pa_passenger_limit'),
        'inspection_date' => fn(array $in) => inDate($in, 'inspection_date'), 'notes' => $t('notes', 500),
    ];
}

const PARTY_TYPES = ['mortgagee', 'leasing', 'loss_payee', 'other'];
const PARTY_TYPE_LABELS = ['mortgagee' => 'Bank (KPR)', 'leasing' => 'Leasing', 'loss_payee' => 'Loss Payee', 'other' => 'Lainnya'];

function partyParsers(): array {
    return [
        'party_type' => fn(array $in) => inEnum($in, 'party_type', PARTY_TYPES, true),
        'name' => fn(array $in) => inText($in, 'name', 200, true),
        'branch' => fn(array $in) => inText($in, 'branch', 150),
        'reference_number' => fn(array $in) => inText($in, 'reference_number', 100),
        'tenor_end' => fn(array $in) => inDate($in, 'tenor_end'),
        'on_insurer_panel' => fn(array $in) => inBool($in, 'on_insurer_panel'),
        'qq_in_name' => fn(array $in) => inBool($in, 'qq_in_name') ?? 1,
        'notify_renewal' => fn(array $in) => inBool($in, 'notify_renewal') ?? 1,
        'proof_sent_date' => fn(array $in) => inDate($in, 'proof_sent_date'),
        'notes' => fn(array $in) => inText($in, 'notes', 500),
    ];
}

function partyShape(array $r): array {
    foreach (['qq_in_name', 'notify_renewal'] as $c) $r[$c] = (bool)(int)$r[$c];
    $r['on_insurer_panel'] = $r['on_insurer_panel'] === null ? null : (bool)(int)$r['on_insurer_panel'];
    $r['party_type_label'] = PARTY_TYPE_LABELS[$r['party_type']] ?? $r['party_type'];
    return $r;
}

/** "<party> QQ <insured>" when a party is printed in the insured name, else the insured name. */
function policyQqName($conn, string $policy_id, string $insured): string {
    $names = array_column(dbAll($conn, "SELECT name FROM {app}.policy_parties WHERE policy_id = ? AND qq_in_name = 1 ORDER BY created_at", [$policy_id]), 'name');
    return $names ? implode(' QQ ', $names) . ' QQ ' . $insured : $insured;
}

// ── Renewal ──────────────────────────────────────────────────────────────────

/** Copies locations to the renewed policy. Returns old location_id => new location_id. */
function scheduleCopyLocations($conn, string $from, string $to, string $company_id, string $username): array {
    $map = [];
    foreach (dbAll($conn, "SELECT * FROM {app}.policy_locations WHERE policy_id = ? ORDER BY seq", [$from]) as $loc) {
        $new = dbNewId('loc');
        $map[$loc['location_id']] = $new;
        unset($loc['created_at'], $loc['updated_at'], $loc['updated_by']);
        $loc['location_id'] = $new;
        $loc['policy_id'] = $to;
        $loc['company_id'] = $company_id;
        $loc['created_by'] = $username;
        dbInsert($conn, 'policy_locations', $loc);
    }
    return $map;
}

/** Copies fleet vehicles onto the renewed policy's copied coverage items. */
function scheduleCopyVehicles($conn, string $from, string $to, string $company_id, string $username, array $coverage_map): void {
    foreach (dbAll($conn, "SELECT * FROM {app}.policy_vehicles WHERE policy_id = ?", [$from]) as $v) {
        if (!isset($coverage_map[$v['coverage_id']])) continue;
        unset($v['created_at'], $v['updated_at'], $v['updated_by']);
        $v['vehicle_id'] = dbNewId('veh');
        $v['policy_id'] = $to;
        $v['company_id'] = $company_id;
        $v['coverage_id'] = $coverage_map[$v['coverage_id']];
        $v['created_by'] = $username;
        dbInsert($conn, 'policy_vehicles', $v);
    }
}

/** Banks and leasing companies stay on the renewed policy (proof date cleared: the new policy still has to be sent). */
function scheduleCopyParties($conn, string $from, string $to, string $company_id, string $username): void {
    foreach (dbAll($conn, "SELECT * FROM {app}.policy_parties WHERE policy_id = ?", [$from]) as $p) {
        if ($p['tenor_end'] !== null && $p['tenor_end'] < date('Y-m-d')) continue; // financing finished
        unset($p['created_at'], $p['updated_at'], $p['updated_by']);
        $p['party_id'] = dbNewId('pty');
        $p['policy_id'] = $to;
        $p['company_id'] = $company_id;
        $p['proof_sent_date'] = null;
        $p['created_by'] = $username;
        dbInsert($conn, 'policy_parties', $p);
    }
}

/** Copies the co-insurance panel; the amount columns are recomputed from the new premium. */
function scheduleCopyCoassurance($conn, string $from, string $to, string $username): void {
    $rows = dbAll($conn, "SELECT * FROM {app}.policy_coassurance WHERE policy_id = ?", [$from]);
    if (!$rows) return;
    $p = dbOne($conn, "SELECT sum_insured, premium_amount FROM {app}.policies WHERE policy_id = ?", [$to]);
    foreach ($rows as $c) {
        $share = (float)$c['share_percent'];
        $premium = (int)round((int)$p['premium_amount'] * $share / 100);
        dbInsert($conn, 'policy_coassurance', [
            'coassurance_id' => dbNewId('coa'), 'policy_id' => $to, 'co_insurer_id' => $c['co_insurer_id'],
            'co_insurer_name' => $c['co_insurer_name'], 'is_leader' => (int)$c['is_leader'], 'share_percent' => $share,
            'sum_insured_share' => (int)round((int)$p['sum_insured'] * $share / 100), 'premium_share' => $premium,
            'commission_rate' => (float)$c['commission_rate'], 'commission_amount' => (int)round($premium * (float)$c['commission_rate'] / 100),
            'notes' => $c['notes'], 'created_by' => $username,
        ]);
    }
    dbExec($conn, "UPDATE {app}.policies SET is_coassurance = 1 WHERE policy_id = ?", [$to]);
}

/** Total share % of a policy's co-insurance panel, or null when it has none. */
function coassuranceTotal($conn, string $policy_id): ?float {
    $r = dbOne($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(share_percent), 0) AS total FROM {app}.policy_coassurance WHERE policy_id = ?", [$policy_id]);
    return (int)$r['n'] === 0 ? null : round((float)$r['total'], 2);
}
