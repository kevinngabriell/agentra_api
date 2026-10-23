<?php

// Customer 360 and cross-sell (migration_customer_360.sql). Pure SQL and rules, used by
// customers/overview.php and customer-groups/index.php.

const POLICY_MAX_OVERVIEW = 500;

/** In force today. A cancelled policy never counts. */
const POLICY_IN_FORCE_SQL = "(p.coverage_start <= CURDATE() AND p.coverage_end >= CURDATE() AND p.renewal_status <> 'cancelled')";

/**
 * Product family used by the cross-sell rules. Product codes are free-form per company, so the
 * family comes from the risk group of the code first, then from the product name.
 */
function crossSellFamily(string $code, string $name, ?string $master_type = null): ?string {
    $n = strtolower($name);
    if (str_contains($n, 'tanggung gugat') || str_contains($n, 'liability')) return 'liability';
    $group = riskGroup($code, '', $master_type);
    if ($group) return $group;
    foreach ([
        'motor'  => ['kendaraan', 'bermotor', 'mobil'],
        'fire'   => ['kebakaran', 'properti', 'property'],
        'cargo'  => ['cargo', 'kargo', 'pengangkutan', 'marine'],
        'travel' => ['perjalanan', 'travel'],
        'pa'     => ['kecelakaan'],
    ] as $family => $keywords) {
        foreach ($keywords as $kw) if (str_contains($n, $kw)) return $family;
    }
    return null;
}

// "has" -> "missing" rules. customer_type limits a rule to individuals or companies.
const CROSS_SELL_RULES = [
    ['has' => 'motor',  'missing' => 'fire',      'customer_type' => null,         'reason' => 'Memiliki asuransi kendaraan, belum memiliki asuransi properti / kebakaran'],
    ['has' => 'motor',  'missing' => 'pa',        'customer_type' => 'individual', 'reason' => 'Memiliki asuransi kendaraan, pengemudi dan keluarga belum dilindungi kecelakaan diri'],
    ['has' => 'fire',   'missing' => 'cargo',     'customer_type' => null,         'reason' => 'Memiliki asuransi kebakaran, barang dalam pengiriman belum diasuransikan (cargo)'],
    ['has' => 'fire',   'missing' => 'liability', 'customer_type' => 'company',    'reason' => 'Memiliki asuransi kebakaran, tanggung gugat pihak ketiga belum dilindungi'],
    ['has' => 'fire',   'missing' => 'pa',        'customer_type' => 'individual', 'reason' => 'Memiliki asuransi properti, penghuni belum dilindungi kecelakaan diri'],
    ['has' => 'cargo',  'missing' => 'fire',      'customer_type' => null,         'reason' => 'Memiliki asuransi cargo, gudang / stok belum diasuransikan kebakaran'],
    ['has' => 'cargo',  'missing' => 'liability', 'customer_type' => 'company',    'reason' => 'Memiliki asuransi cargo, tanggung gugat belum dilindungi'],
    ['has' => 'travel', 'missing' => 'pa',        'customer_type' => null,         'reason' => 'Memiliki asuransi perjalanan, belum memiliki perlindungan kecelakaan diri'],
    ['has' => 'liability', 'missing' => 'fire',   'customer_type' => 'company',    'reason' => 'Memiliki tanggung gugat, aset usaha belum diasuransikan kebakaran'],
];

/** Customer ids of the scope: the customer alone, or its whole group when scope = group. */
function c360ScopeIds($conn, string $company_id, array $customer, string $scope): array {
    if ($scope === 'group' && !empty($customer['group_id'])) {
        $gid = mysqli_real_escape_string($conn, $customer['group_id']);
        $res = mysqli_query($conn, "SELECT customer_id FROM " . APP_SCHEMA . ".customers WHERE group_id = '$gid' AND company_id = '$company_id'");
        $ids = $res ? array_column(mysqli_fetch_all($res, MYSQLI_ASSOC), 'customer_id') : [];
        if ($ids) return $ids;
    }
    return [$customer['customer_id']];
}

function c360IdList($conn, array $ids): string {
    return implode(',', array_map(fn($id) => "'" . mysqli_real_escape_string($conn, $id) . "'", $ids));
}

function c360FindCustomer($conn, string $company_id, string $customer_id): array {
    $cid = mysqli_real_escape_string($conn, $customer_id);
    $res = mysqli_query($conn, "SELECT c.customer_id, c.display_name, c.customer_type, c.group_id, g.name AS group_name
        FROM " . APP_SCHEMA . ".customers c
        LEFT JOIN " . APP_SCHEMA . ".customer_groups g ON g.group_id = c.group_id
        WHERE c.customer_id = '$cid' AND c.company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_CGRP_004');
    return mysqli_fetch_assoc($res);
}

/** Every policy of the given customers with its product name, insurer and standing today. */
function c360Policies($conn, string $company_id, array $ids): array {
    $in  = c360IdList($conn, $ids);
    $res = mysqli_query($conn, "SELECT p.policy_id, p.policy_number, p.product_type,
            COALESCE(mp.product_name, p.product_type) AS product_name,
            p.insurer_id, i.short_name AS insurer_name, p.customer_id, c.display_name AS customer_name,
            p.coverage_start, p.coverage_end, p.renewal_status, p.payment_status,
            p.sum_insured, p.premium_amount,
            CASE WHEN p.renewal_status = 'cancelled' THEN 'cancelled'
                 WHEN p.coverage_end < CURDATE() THEN 'expired'
                 WHEN p.coverage_start > CURDATE() THEN 'upcoming'
                 ELSE 'in_force' END AS standing,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".master_products mp ON mp.company_id = p.company_id AND mp.product_code = p.product_type
        WHERE p.company_id = '$company_id' AND p.customer_id IN ($in)
        ORDER BY (p.coverage_end >= CURDATE()) DESC, p.coverage_end ASC
        LIMIT " . POLICY_MAX_OVERVIEW);
    if (!$res) throw new RuntimeException('c360Policies: ' . mysqli_error($conn));
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        foreach (['sum_insured', 'premium_amount', 'days_until_expiry'] as $k) $r[$k] = (int)$r[$k];
    }
    unset($r);
    return $rows;
}

/** Totals per product and per insurer over in-force and upcoming policies. */
function c360Summaries(array $policies): array {
    $byProduct = [];
    $byInsurer = [];
    $held = ['policies' => 0, 'premium' => 0, 'sum_insured' => 0];
    foreach ($policies as $p) {
        if (!in_array($p['standing'], ['in_force', 'upcoming'], true)) continue;
        $held['policies']++;
        $held['premium']     += $p['premium_amount'];
        $held['sum_insured'] += $p['sum_insured'];

        $k = $p['product_type'];
        $byProduct[$k] ??= ['product_type' => $k, 'product_name' => $p['product_name'], 'policies' => 0, 'premium' => 0, 'sum_insured' => 0, 'insurers' => []];
        $byProduct[$k]['policies']++;
        $byProduct[$k]['premium']     += $p['premium_amount'];
        $byProduct[$k]['sum_insured'] += $p['sum_insured'];
        if ($p['insurer_name'] && !in_array($p['insurer_name'], $byProduct[$k]['insurers'], true)) $byProduct[$k]['insurers'][] = $p['insurer_name'];

        $i = $p['insurer_id'];
        $byInsurer[$i] ??= ['insurer_id' => $i, 'insurer_name' => $p['insurer_name'], 'policies' => 0, 'premium' => 0, 'products' => []];
        $byInsurer[$i]['policies']++;
        $byInsurer[$i]['premium'] += $p['premium_amount'];
        if (!in_array($p['product_name'], $byInsurer[$i]['products'], true)) $byInsurer[$i]['products'][] = $p['product_name'];
    }
    $byProduct = array_values($byProduct);
    $byInsurer = array_values($byInsurer);
    usort($byProduct, fn($a, $b) => $b['premium'] <=> $a['premium']);
    usort($byInsurer, fn($a, $b) => $b['premium'] <=> $a['premium']);
    return ['held' => $held, 'by_product' => $byProduct, 'by_insurer' => $byInsurer];
}

function c360GroupMembers($conn, string $company_id, ?string $group_id): array {
    if (!$group_id) return [];
    $gid = mysqli_real_escape_string($conn, $group_id);
    $res = mysqli_query($conn, "SELECT c.customer_id, c.display_name, c.customer_type,
            COUNT(CASE WHEN " . POLICY_IN_FORCE_SQL . " THEN 1 END) AS in_force_policies,
            COALESCE(SUM(CASE WHEN " . POLICY_IN_FORCE_SQL . " THEN p.premium_amount END), 0) AS premium
        FROM " . APP_SCHEMA . ".customers c
        LEFT JOIN " . APP_SCHEMA . ".policies p ON p.customer_id = c.customer_id AND p.company_id = c.company_id
        WHERE c.group_id = '$gid' AND c.company_id = '$company_id'
        GROUP BY c.customer_id, c.display_name, c.customer_type
        ORDER BY c.display_name");
    if (!$res) throw new RuntimeException('c360GroupMembers: ' . mysqli_error($conn));
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    foreach ($rows as &$r) { $r['in_force_policies'] = (int)$r['in_force_policies']; $r['premium'] = (int)$r['premium']; }
    unset($r);
    return $rows;
}

/** Suggestions for the policies held: a rule fires when the family is held and its target is not. */
function c360CrossSell($conn, string $company_id, array $customer, array $policies): array {
    $res = mysqli_query($conn, "SELECT product_code, product_name, product_type FROM " . APP_SCHEMA . ".master_products
        WHERE company_id = '$company_id' AND is_active = 1 ORDER BY product_name");
    $catalog = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    $nameByCode = array_column($catalog, 'product_name', 'product_code');
    $typeByCode = array_column($catalog, 'product_type', 'product_code');

    $heldFamilies = [];
    foreach ($policies as $p) {
        if (!in_array($p['standing'], ['in_force', 'upcoming'], true)) continue;
        $fam = crossSellFamily($p['product_type'], $nameByCode[$p['product_type']] ?? $p['product_name'], $typeByCode[$p['product_type']] ?? null);
        if ($fam) $heldFamilies[$fam] ??= $p['product_name'];
    }
    // Policies in the family also count under their own product code, so a catalog product the customer already holds is never offered.
    $heldCodes = [];
    foreach ($policies as $p) if (in_array($p['standing'], ['in_force', 'upcoming'], true)) $heldCodes[$p['product_type']] = true;

    $out = [];
    $seen = [];
    foreach (CROSS_SELL_RULES as $rule) {
        if ($rule['customer_type'] !== null && $rule['customer_type'] !== $customer['customer_type']) continue;
        if (!isset($heldFamilies[$rule['has']]) || isset($heldFamilies[$rule['missing']]) || isset($seen[$rule['missing']])) continue;

        $targets = [];
        foreach ($catalog as $prod) {
            if (crossSellFamily($prod['product_code'], $prod['product_name'], $prod['product_type']) === $rule['missing'] && empty($heldCodes[$prod['product_code']])) {
                $targets[] = ['product_code' => $prod['product_code'], 'product_name' => $prod['product_name']];
            }
        }
        if (!$targets) continue;
        $seen[$rule['missing']] = true;
        $out[] = [
            'family'        => $rule['missing'],
            'because'       => $heldFamilies[$rule['has']],
            'reason'        => $rule['reason'],
            'products'      => $targets,
        ];
    }
    return $out;
}
