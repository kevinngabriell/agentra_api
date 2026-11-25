<?php

// Property risk locations, survey recommendations / warranties and stock declarations (v2.1.0).
// Functions only; routing is in policies/index.php.
//
//   GET    /policies/{id}/locations                       locations with their coverage items and totals
//   POST   /policies/{id}/locations                       add a location
//   PUT    /policies/{id}/locations/{lid}                 edit
//   DELETE /policies/{id}/locations/{lid}                 remove (refused while coverage items point at it)
//   GET    /policies/{id}/locations/{lid}/nearby          other insured locations within ?radius_km (accumulation)
//   GET|POST /policies/{id}/recommendations   PUT|DELETE /policies/{id}/recommendations/{rid}
//   GET|POST /policies/{id}/stock-declarations   PUT|DELETE /policies/{id}/stock-declarations/{did}
//   POST   /policies/{id}/stock-declarations/adjust       year-end premium adjustment (debit / credit note)

require_once __DIR__ . '/../helpers/schedules.php';
require_once __DIR__ . '/../helpers/endorsements.php';
require_once __DIR__ . '/../helpers/policy_log.php';

const LOCATION_MAX_PER_POLICY = 300;
// Building sum insured below this share of the rebuild value is flagged: the average clause would cut a claim.
const UNDERINSURANCE_WARN_RATIO = 0.9;

function locFindPolicy($conn, string $company_id, string $policy_id): array {
    $p = dbOne($conn, "SELECT policy_id, policy_number, renewal_status, coverage_start, coverage_end, product_type FROM {app}.policies WHERE policy_id = ? AND company_id = ?", [$policy_id, $company_id]);
    if (!$p) throw ApiException::from('ERR_POLICY_001');
    return $p;
}

/** Locations of a policy with their items, totals, rebuild value and an underinsurance hint. */
function locFetchAll($conn, string $policy_id): array {
    $locs = array_map('locationShape', dbAll($conn, "SELECT * FROM {app}.policy_locations WHERE policy_id = ? ORDER BY seq, created_at", [$policy_id]));
    $items = dbAll($conn, "SELECT coverage_id, location_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, count_in_tsi
        FROM {app}.policy_coverages WHERE policy_id = ? AND location_id IS NOT NULL", [$policy_id]);
    foreach ($locs as &$l) {
        $mine = array_values(array_filter($items, fn($i) => $i['location_id'] === $l['location_id']));
        $l['items'] = array_map(fn($i) => ['coverage_id' => $i['coverage_id'], 'coverage_type' => $i['coverage_type'], 'coverage_label' => $i['coverage_label'],
            'sum_insured' => (int)$i['sum_insured'], 'rate_permille' => (float)$i['rate_permille'], 'premium_amount' => (int)$i['premium_amount'],
            'count_in_tsi' => (bool)(int)$i['count_in_tsi']], $mine);
        $l['total_sum_insured'] = array_sum(array_map(fn($i) => (int)$i['count_in_tsi'] ? (int)$i['sum_insured'] : 0, $mine));
        $l['total_premium'] = array_sum(array_map(fn($i) => (int)$i['premium_amount'], $mine));
        $l['pml_amount'] = $l['pml_percent'] !== null ? (int)round($l['total_sum_insured'] * $l['pml_percent'] / 100) : null;
        $building = array_sum(array_map(fn($i) => $i['coverage_type'] === 'bangunan' ? (int)$i['sum_insured'] : 0, $mine));
        $rebuild = ($l['floor_area_m2'] ?? null) && ($l['rebuild_cost_per_m2'] ?? null) ? $l['floor_area_m2'] * $l['rebuild_cost_per_m2'] : null;
        $l['building_sum_insured'] = $building;
        $l['rebuild_value'] = $rebuild;
        $l['underinsured'] = $rebuild !== null && $building > 0 && $building < $rebuild * UNDERINSURANCE_WARN_RATIO;
        $l['insured_ratio'] = $rebuild ? round($building / $rebuild, 3) : null;
    }
    unset($l);
    return $locs;
}

function listLocations($conn, string $company_id, string $policy_id): void {
    locFindPolicy($conn, $company_id, $policy_id);
    $locs = locFetchAll($conn, $policy_id);
    $unassigned = dbOne($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN count_in_tsi = 1 THEN sum_insured ELSE 0 END), 0) AS si
        FROM {app}.policy_coverages WHERE policy_id = ? AND location_id IS NULL AND coverage_type <> 'kendaraan'", [$policy_id]);
    jsonResponse(200, 'Locations retrieved', [
        'items' => $locs,
        'summary' => [
            'location_count' => count($locs),
            'total_sum_insured' => array_sum(array_column($locs, 'total_sum_insured')),
            'max_location_sum_insured' => $locs ? max(array_column($locs, 'total_sum_insured')) : 0,
            'total_pml' => array_sum(array_map(fn($l) => $l['pml_amount'] ?? 0, $locs)),
            'unassigned_item_count' => (int)$unassigned['n'],
            'unassigned_sum_insured' => (int)$unassigned['si'],
        ],
    ]);
}

function saveLocation($conn, string $company_id, string $policy_id, ?string $location_id, array $in, string $username): void {
    $policy = locFindPolicy($conn, $company_id, $policy_id);
    if ($policy['renewal_status'] === 'cancelled') throw ApiException::from('ERR_V21_014');
    $set = inPresent($in, locationParsers());
    if (array_key_exists('latitude', $set) xor array_key_exists('longitude', $set)) throw ApiException::from('ERR_POLICY_013');

    if ($location_id === null) {
        $n = (int)dbValue($conn, "SELECT COUNT(*) FROM {app}.policy_locations WHERE policy_id = ?", [$policy_id]);
        if ($n >= LOCATION_MAX_PER_POLICY) throw ApiException::from('ERR_V21_012', ['max' => LOCATION_MAX_PER_POLICY]);
        $location_id = dbNewId('loc');
        dbInsert($conn, 'policy_locations', $set + [
            'location_id' => $location_id, 'company_id' => $company_id, 'policy_id' => $policy_id,
            'seq' => $n + 1, 'created_by' => $username,
        ]);
        insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Lokasi risiko ditambahkan: ' . ($set['label'] ?? $set['address'] ?? "Lokasi " . ($n + 1)),
            $username, null, null, 'policy_locations', $location_id);
        $status = 201;
    } else {
        if (!dbOne($conn, "SELECT 1 FROM {app}.policy_locations WHERE location_id = ? AND policy_id = ?", [$location_id, $policy_id])) throw ApiException::from('ERR_V21_004');
        if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
        dbUpdate($conn, 'policy_locations', $set + ['updated_by' => $username], ['location_id' => $location_id, 'policy_id' => $policy_id]);
        insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Lokasi risiko diperbarui: ' . implode(', ', array_keys($set)),
            $username, null, null, 'policy_locations', $location_id);
        $status = 200;
    }
    $all = locFetchAll($conn, $policy_id);
    $row = array_values(array_filter($all, fn($l) => $l['location_id'] === $location_id))[0] ?? null;
    jsonResponse($status, 'Location saved', $row);
}

function deleteLocation($conn, string $company_id, string $policy_id, string $location_id, string $username): void {
    locFindPolicy($conn, $company_id, $policy_id);
    $loc = dbOne($conn, "SELECT label, address FROM {app}.policy_locations WHERE location_id = ? AND policy_id = ?", [$location_id, $policy_id]);
    if (!$loc) throw ApiException::from('ERR_V21_004');
    $used = (int)dbValue($conn, "SELECT COUNT(*) FROM {app}.policy_coverages WHERE location_id = ?", [$location_id]);
    if ($used > 0) throw ApiException::from('ERR_V21_008', ['count' => $used]);
    dbExec($conn, "DELETE FROM {app}.policy_locations WHERE location_id = ? AND policy_id = ?", [$location_id, $policy_id]);
    dbExec($conn, "UPDATE {app}.policy_risk_recommendations SET location_id = NULL WHERE location_id = ?", [$location_id]);
    insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Lokasi risiko dihapus: ' . ($loc['label'] ?? $loc['address'] ?? $location_id),
        $username, null, null, 'policy_locations', $location_id);
    jsonResponse(200, 'Location deleted');
}

/** Great-circle distance in km. */
function geoKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}

/**
 * Insured locations in force for a company: every policy_locations row (its TSI from its items) plus
 * policies without a location schedule that carry a single risk address with coordinates (their TSI).
 */
function accumulationPoints($conn, string $company_id): array {
    $today = date('Y-m-d');
    return dbAll($conn, "SELECT 'location' AS kind, l.location_id AS ref_id, p.policy_id, p.policy_number, c.display_name AS customer_name,
            COALESCE(l.label, l.address) AS label, l.area_name, l.city, l.district, l.eq_zone, l.latitude, l.longitude, l.pml_percent,
            COALESCE((SELECT SUM(pc.sum_insured) FROM {app}.policy_coverages pc WHERE pc.location_id = l.location_id AND pc.count_in_tsi = 1), 0) AS sum_insured
        FROM {app}.policy_locations l
        JOIN {app}.policies p ON p.policy_id = l.policy_id
        JOIN {app}.customers c ON c.customer_id = p.customer_id
        WHERE l.company_id = ? AND p.renewal_status IN ('pending', 'renewed') AND p.coverage_end >= ? AND p.coverage_start <= ?
        UNION ALL
        SELECT 'policy', p.policy_id, p.policy_id, p.policy_number, c.display_name, p.risk_address, NULL, p.risk_city, p.risk_district,
            NULL, p.risk_latitude, p.risk_longitude, NULL, p.sum_insured
        FROM {app}.policies p
        JOIN {app}.customers c ON c.customer_id = p.customer_id
        WHERE p.company_id = ? AND p.renewal_status IN ('pending', 'renewed') AND p.coverage_end >= ? AND p.coverage_start <= ?
          AND (p.risk_latitude IS NOT NULL OR p.risk_city IS NOT NULL)
          AND NOT EXISTS (SELECT 1 FROM {app}.policy_locations l2 WHERE l2.policy_id = p.policy_id)",
        [$company_id, $today, $today, $company_id, $today, $today]);
}

function nearbyLocations($conn, string $company_id, string $policy_id, string $location_id, array $q): void {
    locFindPolicy($conn, $company_id, $policy_id);
    $loc = dbOne($conn, "SELECT latitude, longitude, area_name, label FROM {app}.policy_locations WHERE location_id = ? AND policy_id = ?", [$location_id, $policy_id]);
    if (!$loc) throw ApiException::from('ERR_V21_004');
    $radius = min(50.0, max(0.05, (float)($q['radius_km'] ?? 1)));
    $points = accumulationPoints($conn, $company_id);
    $out = [];
    foreach ($points as $pt) {
        if ($pt['ref_id'] === $location_id) continue;
        $dist = null;
        if ($loc['latitude'] !== null && $pt['latitude'] !== null) {
            $dist = geoKm((float)$loc['latitude'], (float)$loc['longitude'], (float)$pt['latitude'], (float)$pt['longitude']);
        }
        $sameArea = $loc['area_name'] && $pt['area_name'] && strcasecmp(trim($loc['area_name']), trim($pt['area_name'])) === 0;
        if (($dist !== null && $dist <= $radius) || $sameArea) {
            $out[] = ['kind' => $pt['kind'], 'policy_id' => $pt['policy_id'], 'policy_number' => $pt['policy_number'],
                'customer_name' => $pt['customer_name'], 'label' => $pt['label'], 'area_name' => $pt['area_name'],
                'distance_km' => $dist !== null ? round($dist, 3) : null, 'same_area' => $sameArea, 'sum_insured' => (int)$pt['sum_insured']];
        }
    }
    usort($out, fn($a, $b) => ($a['distance_km'] ?? 999) <=> ($b['distance_km'] ?? 999));
    jsonResponse(200, 'Nearby locations', [
        'radius_km' => $radius, 'has_coordinates' => $loc['latitude'] !== null,
        'items' => $out, 'total_sum_insured' => array_sum(array_column($out, 'sum_insured')),
    ]);
}

// ── Recommendations / warranties ─────────────────────────────────────────────

function recParsers(): array {
    return [
        'description' => fn($i) => inText($i, 'description', 1000, true),
        'location_id' => fn($i) => inText($i, 'location_id', 50),
        'priority' => fn($i) => inEnum($i, 'priority', ['low', 'medium', 'high']) ?? 'medium',
        'is_warranty' => fn($i) => inBool($i, 'is_warranty') ?? 0,
        'due_date' => fn($i) => inDate($i, 'due_date'),
        'status' => fn($i) => inEnum($i, 'status', ['open', 'done', 'waived']) ?? 'open',
        'completed_date' => fn($i) => inDate($i, 'completed_date'),
        'source' => fn($i) => inText($i, 'source', 150),
        'notes' => fn($i) => inText($i, 'notes', 500),
    ];
}

function recShape(array $r): array {
    $r['is_warranty'] = (bool)(int)$r['is_warranty'];
    $r['is_overdue'] = $r['status'] === 'open' && $r['due_date'] !== null && $r['due_date'] < date('Y-m-d');
    return $r;
}

function listRecommendations($conn, string $company_id, string $policy_id): void {
    locFindPolicy($conn, $company_id, $policy_id);
    $rows = dbAll($conn, "SELECT r.*, COALESCE(l.label, l.address) AS location_label FROM {app}.policy_risk_recommendations r
        LEFT JOIN {app}.policy_locations l ON l.location_id = r.location_id
        WHERE r.policy_id = ? ORDER BY r.status = 'open' DESC, r.is_warranty DESC, r.due_date IS NULL, r.due_date", [$policy_id]);
    jsonResponse(200, 'Recommendations retrieved', ['items' => array_map('recShape', $rows)]);
}

function saveRecommendation($conn, string $company_id, string $policy_id, ?string $rec_id, array $in, string $username): void {
    locFindPolicy($conn, $company_id, $policy_id);
    $parsers = recParsers();
    $set = $rec_id === null ? array_map(fn($p) => $p($in), $parsers) : inPresent($in, $parsers);
    if (!empty($set['location_id']) && !dbOne($conn, "SELECT 1 FROM {app}.policy_locations WHERE location_id = ? AND policy_id = ?", [$set['location_id'], $policy_id])) {
        throw ApiException::from('ERR_V21_001', ['field' => 'location_id']);
    }
    if (($set['status'] ?? null) === 'done' && empty($set['completed_date'])) $set['completed_date'] = date('Y-m-d');
    if ($rec_id === null) {
        $rec_id = dbNewId('rec');
        dbInsert($conn, 'policy_risk_recommendations', $set + ['recommendation_id' => $rec_id, 'company_id' => $company_id, 'policy_id' => $policy_id, 'created_by' => $username]);
        insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', ($set['is_warranty'] ? 'Warranty' : 'Rekomendasi survei') . ' ditambahkan: ' . mb_substr($set['description'], 0, 200),
            $username, null, null, 'policy_risk_recommendations', $rec_id);
        $status = 201;
    } else {
        if (!dbOne($conn, "SELECT 1 FROM {app}.policy_risk_recommendations WHERE recommendation_id = ? AND policy_id = ?", [$rec_id, $policy_id])) throw ApiException::from('ERR_V21_004');
        if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
        dbUpdate($conn, 'policy_risk_recommendations', $set + ['updated_by' => $username], ['recommendation_id' => $rec_id]);
        if (isset($set['status'])) {
            insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Rekomendasi survei: status ' . $set['status'], $username, null, null, 'policy_risk_recommendations', $rec_id);
        }
        $status = 200;
    }
    $row = dbOne($conn, "SELECT r.*, COALESCE(l.label, l.address) AS location_label FROM {app}.policy_risk_recommendations r
        LEFT JOIN {app}.policy_locations l ON l.location_id = r.location_id WHERE r.recommendation_id = ?", [$rec_id]);
    jsonResponse($status, 'Recommendation saved', recShape($row));
}

function deleteRecommendation($conn, string $company_id, string $policy_id, string $rec_id): void {
    locFindPolicy($conn, $company_id, $policy_id);
    if (dbExec($conn, "DELETE FROM {app}.policy_risk_recommendations WHERE recommendation_id = ? AND policy_id = ?", [$rec_id, $policy_id]) === 0) {
        throw ApiException::from('ERR_V21_004');
    }
    jsonResponse(200, 'Recommendation deleted');
}

// ── Stock declarations ───────────────────────────────────────────────────────

/**
 * Adjustment of a stock declaration policy. The deposit premium is charged on the maximum sum insured;
 * at the end the premium is re-computed on the AVERAGE declared value. A higher figure is billed, a lower
 * one refunded up to the refund cap (default 50% of the deposit, the usual wording; editable per policy).
 */
function stockAdjustment(array $settings, array $declarations): array {
    $values = array_map(fn($d) => (int)$d['declared_value'], $declarations);
    $max_si = (int)($settings['stock_max_sum_insured'] ?? 0);
    $rate = (float)($settings['stock_rate_permille'] ?? 0);
    $deposit = (int)($settings['stock_deposit_premium'] ?? 0);
    $cap = $settings['stock_refund_cap_percent'] !== null ? (float)$settings['stock_refund_cap_percent'] : 50.0;
    $avg = $values ? (int)round(array_sum($values) / count($values)) : 0;
    $earned = (int)round($avg * $rate / 1000);
    $diff = $earned - $deposit;
    $max_refund = (int)round($deposit * $cap / 100);
    $adjust = $diff < 0 ? -min(-$diff, $max_refund) : $diff;
    return [
        'months_declared' => count($values), 'average_declared' => $avg, 'peak_declared' => $values ? max($values) : 0,
        'max_sum_insured' => $max_si, 'rate_permille' => $rate, 'deposit_premium' => $deposit,
        'earned_premium' => $earned, 'refund_cap_percent' => $cap, 'adjustment' => $adjust,
        'months_over_max' => count(array_filter($values, fn($v) => $max_si > 0 && $v > $max_si)),
    ];
}

function stockSettings($conn, string $policy_id): array {
    $s = dbOne($conn, "SELECT is_stock_declaration, stock_max_sum_insured, stock_rate_permille, stock_deposit_premium, stock_refund_cap_percent
        FROM {app}.policy_risk_details WHERE policy_id = ?", [$policy_id]);
    if (!$s || !(int)$s['is_stock_declaration']) throw ApiException::from('ERR_V21_009');
    return $s;
}

function listStockDeclarations($conn, string $company_id, string $policy_id): void {
    locFindPolicy($conn, $company_id, $policy_id);
    $settings = stockSettings($conn, $policy_id);
    $rows = dbAll($conn, "SELECT d.*, COALESCE(l.label, l.address) AS location_label FROM {app}.policy_stock_declarations d
        LEFT JOIN {app}.policy_locations l ON l.location_id = d.location_id WHERE d.policy_id = ? ORDER BY d.period_month, d.created_at", [$policy_id]);
    foreach ($rows as &$r) $r['declared_value'] = (int)$r['declared_value'];
    unset($r);
    // Per month: the declared value is the sum over locations.
    $byMonth = [];
    foreach ($rows as $r) $byMonth[$r['period_month']] = ($byMonth[$r['period_month']] ?? 0) + $r['declared_value'];
    $monthly = array_map(fn($m, $v) => ['period_month' => $m, 'declared_value' => $v], array_keys($byMonth), $byMonth);
    $adjusted = dbOne($conn, "SELECT endorsement_id, note_number, premium_delta FROM {app}.policy_endorsements WHERE policy_id = ? AND endorsement_type = 'stock_adjustment' AND is_void = 0 ORDER BY created_at DESC LIMIT 1", [$policy_id]);
    jsonResponse(200, 'Stock declarations retrieved', [
        'items' => $rows, 'monthly' => $monthly,
        'calculation' => stockAdjustment($settings, $monthly),
        'adjustment_note' => $adjusted ? ['endorsement_id' => $adjusted['endorsement_id'], 'note_number' => $adjusted['note_number'], 'premium_delta' => (int)$adjusted['premium_delta']] : null,
    ]);
}

function saveStockDeclaration($conn, string $company_id, string $policy_id, ?string $decl_id, array $in, string $username): void {
    locFindPolicy($conn, $company_id, $policy_id);
    stockSettings($conn, $policy_id);
    $parsers = [
        'period_month' => function ($i) {
            $v = inText($i, 'period_month', 7, true);
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) throw ApiException::from('ERR_V21_001', ['field' => 'period_month']);
            return $v;
        },
        'declared_value' => fn($i) => inMoney($i, 'declared_value', true),
        'location_id' => fn($i) => inText($i, 'location_id', 50),
        'notes' => fn($i) => inText($i, 'notes', 300),
    ];
    $set = $decl_id === null ? array_map(fn($p) => $p($in), $parsers) : inPresent($in, $parsers);
    if (!empty($set['location_id']) && !dbOne($conn, "SELECT 1 FROM {app}.policy_locations WHERE location_id = ? AND policy_id = ?", [$set['location_id'], $policy_id])) {
        throw ApiException::from('ERR_V21_001', ['field' => 'location_id']);
    }
    if ($decl_id === null) {
        $decl_id = dbNewId('sdc');
        dbInsert($conn, 'policy_stock_declarations', $set + ['declaration_id' => $decl_id, 'company_id' => $company_id, 'policy_id' => $policy_id, 'created_by' => $username]);
    } else {
        if (!dbOne($conn, "SELECT 1 FROM {app}.policy_stock_declarations WHERE declaration_id = ? AND policy_id = ?", [$decl_id, $policy_id])) {
            throw ApiException::from('ERR_V21_004');
        }
        if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
        dbUpdate($conn, 'policy_stock_declarations', $set, ['declaration_id' => $decl_id]);
    }
    insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', 'Deklarasi stok ' . ($set['period_month'] ?? '') . ' disimpan'
        . (isset($set['declared_value']) ? ': Rp ' . number_format($set['declared_value'], 0, ',', '.') : ''), $username, null, null, 'policy_stock_declarations', $decl_id);
    listStockDeclarations($conn, $company_id, $policy_id);
}

function deleteStockDeclaration($conn, string $company_id, string $policy_id, string $decl_id): void {
    locFindPolicy($conn, $company_id, $policy_id);
    if (dbExec($conn, "DELETE FROM {app}.policy_stock_declarations WHERE declaration_id = ? AND policy_id = ?", [$decl_id, $policy_id]) === 0) {
        throw ApiException::from('ERR_V21_004');
    }
    listStockDeclarations($conn, $company_id, $policy_id);
}

function adjustStockDeclarations($conn, string $company_id, string $policy_id, array $in, string $username): void {
    locFindPolicy($conn, $company_id, $policy_id);
    $settings = stockSettings($conn, $policy_id);
    $monthly = dbAll($conn, "SELECT period_month, SUM(declared_value) AS declared_value FROM {app}.policy_stock_declarations WHERE policy_id = ? GROUP BY period_month", [$policy_id]);
    if (!$monthly) throw ApiException::from('ERR_V21_010');
    $calc = stockAdjustment($settings, $monthly);
    $policy = endFetchPolicy($conn, $company_id, $policy_id);
    $row = dbTransaction($conn, function () use ($conn, $policy, $calc, $in, $username, $policy_id, $company_id) {
        $row = endRecord($conn, $policy, [
            'endorsement_type' => 'stock_adjustment', 'effective_date' => inDate($in, 'effective_date') ?? min(date('Y-m-d'), $policy['coverage_end']),
            'endorsement_number' => inText($in, 'endorsement_number', 60), 'calc_method' => 'manual', 'premium_delta' => $calc['adjustment'],
            'description' => 'Penyesuaian premi stok deklarasi: rata-rata Rp ' . number_format($calc['average_declared'], 0, ',', '.')
                . ' × ' . $calc['rate_permille'] . '‰ = Rp ' . number_format($calc['earned_premium'], 0, ',', '.')
                . ' vs deposit Rp ' . number_format($calc['deposit_premium'], 0, ',', '.'),
            'source' => 'stock_adjustment', 'force' => true,
        ], $username);
        insertPolicyLog($conn, $policy_id, $company_id, 'endorsement', $row['description'], $username, null, null, 'policy_endorsements', $row['endorsement_id'],
            ['kind' => 'endorsement', 'premium_delta' => $row['premium_delta'], 'note_number' => $row['note_number']]);
        return $row;
    });
    jsonResponse(201, 'Stock adjustment recorded', ['endorsement' => $row, 'calculation' => $calc]);
}
