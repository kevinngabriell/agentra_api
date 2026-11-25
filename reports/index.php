<?php
// [NEW v1.12.0] Reports from brief §4.9. Every report answers in one generic table shape
// ({title, columns, rows, totals}) so the web app renders them all with one component, and
// the same call with ?format=xlsx downloads the table as an Excel file.
//
//   GET /api/v1/reports/{report}[?year=YYYY][&month=YYYY-MM][&insurer_id=..][&format=xlsx]
//   payment-consolidation  premium collected per insurer (month optional)
//   lapsed                 lapsed policies expiring in the year
//   portfolio              in-force policies by product and insurer
//   retention              policy and customer retention, year over year
//   commissions            commission summary; group_by = insurer | product | sub_agent | month
//   sub-agent-production   production per sub-agent (and the main agent)
//   claims                 claims and loss ratio; group_by = insurer | product
//   commission-list        every commission row, for the commissions page export
//   [v2.1.0]
//   accumulation           insured value in force per area / city / earthquake zone (group_by), with PML
//   endorsements           endorsement debit / credit notes and their commission (month or year)
//   statement              statement of account for a customer (customer_id) or customer group (group_id)

require_once __DIR__ . '/../helpers/claims.php';
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/xlsx_writer.php';
require_once __DIR__ . '/../helpers/subscription.php';

const REPORT_PRODUCT_LABELS = [
    'fire' => 'Kebakaran', 'motorcycle' => 'Motor', 'car' => 'Mobil',
    'travel' => 'Perjalanan', 'cargo' => 'Kargo', 'other' => 'Lainnya',
];

function reportRows($conn, string $sql): array {
    $res = mysqli_query($conn, $sql);
    if (!$res) throw new RuntimeException('report query: ' . mysqli_error($conn));
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

function reportProduct(?string $type): string {
    return REPORT_PRODUCT_LABELS[$type ?? ''] ?? ($type ?: '-');
}

function reportPct(float|int $num, float|int $den): float {
    return $den > 0 ? round($num / $den * 100, 1) : 0.0;
}

/** Sums the named numeric columns over $rows into a totals row. */
function reportTotals(array $rows, array $keys): array {
    $t = [];
    foreach ($keys as $k) $t[$k] = array_sum(array_map(static fn($r) => $r[$k] ?? 0, $rows));
    return $t;
}

function reportInsurerFilter($conn, array $q, string $col): string {
    return !empty($q['insurer_id']) ? " AND $col = '" . mysqli_real_escape_string($conn, $q['insurer_id']) . "'" : '';
}

// ── Reports ───────────────────────────────────────────────────────────────────

function reportPaymentConsolidation($conn, string $cid, array $q, int $year): array {
    $month = (!empty($q['month']) && preg_match('/^\d{4}-\d{2}$/', $q['month'])) ? $q['month'] : '';
    $when  = $month !== '' ? "DATE_FORMAT(p.coverage_start, '%Y-%m') = '$month'" : "YEAR(p.coverage_start) = $year";

    $rows = reportRows($conn, "SELECT i.short_name AS insurer, i.name AS insurer_full,
            COUNT(*) AS policies,
            COALESCE(SUM(p.premium_amount), 0) AS premium,
            COALESCE(SUM(CASE WHEN p.payment_status = 'unpaid'    THEN p.premium_amount END), 0) AS unpaid,
            COALESCE(SUM(CASE WHEN p.payment_status = 'paid'      THEN p.premium_amount END), 0) AS paid,
            COALESCE(SUM(CASE WHEN p.payment_status = 'confirmed' THEN p.premium_amount END), 0) AS confirmed,
            COALESCE(SUM(p.commission_amount), 0) AS commission
        FROM " . APP_SCHEMA . ".policies p
        JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        WHERE p.company_id = '$cid' AND $when" . reportInsurerFilter($conn, $q, 'p.insurer_id') . "
        GROUP BY p.insurer_id, i.short_name, i.name
        ORDER BY premium DESC");
    foreach ($rows as &$r) {
        foreach (['policies', 'premium', 'unpaid', 'paid', 'confirmed', 'commission'] as $k) $r[$k] = (int)$r[$k];
        $r['collected_pct'] = reportPct($r['paid'] + $r['confirmed'], $r['premium']);
    }
    unset($r);

    $totals = reportTotals($rows, ['policies', 'premium', 'unpaid', 'paid', 'confirmed', 'commission']);
    $totals['collected_pct'] = reportPct($totals['paid'] + $totals['confirmed'], $totals['premium']);

    return [
        'title'   => 'Konsolidasi Pembayaran per Insurer — ' . ($month !== '' ? $month : $year),
        'columns' => [
            ['key' => 'insurer', 'label' => 'Insurer', 'type' => 'text'],
            ['key' => 'policies', 'label' => 'Polis', 'type' => 'int'],
            ['key' => 'premium', 'label' => 'Total Premi', 'type' => 'money'],
            ['key' => 'unpaid', 'label' => 'Belum Dibayar', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Dibayar', 'type' => 'money'],
            ['key' => 'confirmed', 'label' => 'Terkonfirmasi', 'type' => 'money'],
            ['key' => 'collected_pct', 'label' => '% Terbayar', 'type' => 'pct'],
            ['key' => 'commission', 'label' => 'Komisi', 'type' => 'money'],
        ],
        'rows' => $rows, 'totals' => $totals,
    ];
}

function reportLapsed($conn, string $cid, array $q, int $year): array {
    $rows = reportRows($conn, "SELECT p.policy_number, c.display_name AS customer, i.short_name AS insurer,
            p.product_type, p.coverage_end, p.premium_amount AS premium,
            COALESCE(sa.full_name, 'Agen utama') AS agent
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        LEFT JOIN " . APP_SCHEMA . ".sub_agents sa ON sa.sub_agent_id = p.issuing_agent_id
        WHERE p.company_id = '$cid' AND p.renewal_status = 'lapsed' AND YEAR(p.coverage_end) = $year"
        . reportInsurerFilter($conn, $q, 'p.insurer_id') . "
        ORDER BY p.coverage_end DESC LIMIT 2000");
    foreach ($rows as &$r) {
        $r['product'] = reportProduct($r['product_type']);
        $r['premium'] = (int)$r['premium'];
    }
    unset($r);

    return [
        'title'   => "Polis Lapsed — $year",
        'columns' => [
            ['key' => 'policy_number', 'label' => 'No. Polis', 'type' => 'text'],
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'],
            ['key' => 'insurer', 'label' => 'Insurer', 'type' => 'text'],
            ['key' => 'product', 'label' => 'Produk', 'type' => 'text'],
            ['key' => 'agent', 'label' => 'Agen', 'type' => 'text'],
            ['key' => 'coverage_end', 'label' => 'Berakhir', 'type' => 'date'],
            ['key' => 'premium', 'label' => 'Premi', 'type' => 'money'],
        ],
        'rows' => $rows, 'totals' => ['premium' => array_sum(array_column($rows, 'premium'))],
    ];
}

function reportPortfolio($conn, string $cid, array $q): array {
    $rows = reportRows($conn, "SELECT p.product_type, i.short_name AS insurer,
            COUNT(*) AS policies, COUNT(DISTINCT p.customer_id) AS customers,
            COALESCE(SUM(p.sum_insured), 0) AS sum_insured,
            COALESCE(SUM(p.premium_amount), 0) AS premium,
            COALESCE(SUM(p.commission_amount), 0) AS commission
        FROM " . APP_SCHEMA . ".policies p
        JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        WHERE p.company_id = '$cid' AND p.renewal_status NOT IN ('lapsed', 'cancelled') AND p.coverage_end >= CURDATE()"
        . reportInsurerFilter($conn, $q, 'p.insurer_id') . "
        GROUP BY p.product_type, p.insurer_id, i.short_name
        ORDER BY p.product_type, premium DESC");
    foreach ($rows as &$r) {
        $r['product'] = reportProduct($r['product_type']);
        foreach (['policies', 'customers', 'sum_insured', 'premium', 'commission'] as $k) $r[$k] = (int)$r[$k];
    }
    unset($r);
    $totals = reportTotals($rows, ['policies', 'sum_insured', 'premium', 'commission']);
    foreach ($rows as &$r) $r['share_pct'] = reportPct($r['premium'], $totals['premium']);
    unset($r);
    $totals['share_pct'] = $totals['premium'] > 0 ? 100.0 : 0.0;

    return [
        'title'   => 'Portofolio Polis Aktif — ' . date('d/m/Y'),
        'columns' => [
            ['key' => 'product', 'label' => 'Produk', 'type' => 'text'],
            ['key' => 'insurer', 'label' => 'Insurer', 'type' => 'text'],
            ['key' => 'policies', 'label' => 'Polis', 'type' => 'int'],
            ['key' => 'customers', 'label' => 'Customer', 'type' => 'int'],
            ['key' => 'sum_insured', 'label' => 'Nilai Pertanggungan', 'type' => 'money'],
            ['key' => 'premium', 'label' => 'Premi', 'type' => 'money'],
            ['key' => 'share_pct', 'label' => '% Premi', 'type' => 'pct'],
            ['key' => 'commission', 'label' => 'Komisi', 'type' => 'money'],
        ],
        'rows' => $rows, 'totals' => $totals,
    ];
}

function reportRetention($conn, string $cid, array $q, int $year): array {
    $ins = reportInsurerFilter($conn, $q, 'p.insurer_id');
    $out = [];
    foreach ([$year - 2, $year - 1, $year] as $y) {
        // Policy retention: of the policies that ended in the year, how many were renewed.
        $p = reportRows($conn, "SELECT COUNT(*) AS expiring,
                SUM(p.renewal_status = 'renewed') AS renewed,
                SUM(p.renewal_status = 'lapsed') AS lapsed,
                SUM(p.renewal_status = 'cancelled') AS cancelled
            FROM " . APP_SCHEMA . ".policies p
            WHERE p.company_id = '$cid' AND YEAR(p.coverage_end) = $y$ins")[0];

        // Customer retention: customers covered at some point in the previous year who are also covered in this one.
        $pyStart = ($y - 1) . '-01-01'; $pyEnd = ($y - 1) . '-12-31';
        $cyStart = $y . '-01-01';       $cyEnd = $y . '-12-31';
        $c = reportRows($conn, "SELECT COUNT(DISTINCT a.customer_id) AS base,
                COUNT(DISTINCT b.customer_id) AS retained
            FROM " . APP_SCHEMA . ".policies a
            LEFT JOIN " . APP_SCHEMA . ".policies b ON b.customer_id = a.customer_id AND b.company_id = a.company_id
                AND b.renewal_status <> 'cancelled' AND b.coverage_start <= '$cyEnd' AND b.coverage_end >= '$cyStart'
            WHERE a.company_id = '$cid' AND a.renewal_status <> 'cancelled'
              AND a.coverage_start <= '$pyEnd' AND a.coverage_end >= '$pyStart'" . str_replace('p.insurer_id', 'a.insurer_id', $ins))[0];

        $expiring = (int)$p['expiring']; $renewed = (int)$p['renewed']; $cancelled = (int)$p['cancelled'];
        $out[] = [
            'year'             => (string)$y,
            'expiring'         => $expiring,
            'renewed'          => $renewed,
            'lapsed'           => (int)$p['lapsed'],
            'cancelled'        => $cancelled,
            'policy_retention' => reportPct($renewed, $expiring - $cancelled),
            'customers_base'   => (int)$c['base'],
            'customers_kept'   => (int)$c['retained'],
            'customer_retention' => reportPct((int)$c['retained'], (int)$c['base']),
        ];
    }

    return [
        'title'   => "Retensi Tahunan — $year",
        'columns' => [
            ['key' => 'year', 'label' => 'Tahun', 'type' => 'text'],
            ['key' => 'expiring', 'label' => 'Polis Berakhir', 'type' => 'int'],
            ['key' => 'renewed', 'label' => 'Diperpanjang', 'type' => 'int'],
            ['key' => 'lapsed', 'label' => 'Lapsed', 'type' => 'int'],
            ['key' => 'cancelled', 'label' => 'Batal', 'type' => 'int'],
            ['key' => 'policy_retention', 'label' => 'Retensi Polis', 'type' => 'pct'],
            ['key' => 'customers_base', 'label' => 'Customer Tahun Lalu', 'type' => 'int'],
            ['key' => 'customers_kept', 'label' => 'Customer Bertahan', 'type' => 'int'],
            ['key' => 'customer_retention', 'label' => 'Retensi Customer', 'type' => 'pct'],
        ],
        'rows' => $out,
    ];
}

function reportCommissions($conn, string $cid, array $q, int $year): array {
    $groups = [
        'insurer'   => ['i.short_name', 'i.short_name', 'Insurer'],
        'product'   => ['p.product_type', 'p.product_type', 'Produk'],
        'sub_agent' => ["COALESCE(sa.full_name, 'Agen utama')", "cm.sub_agent_id, sa.full_name", 'Sub-agen'],
        'month'     => ["DATE_FORMAT(p.coverage_start, '%Y-%m')", "DATE_FORMAT(p.coverage_start, '%Y-%m')", 'Bulan'],
    ];
    $key = isset($groups[$q['group_by'] ?? '']) ? $q['group_by'] : 'insurer';
    [$label, $groupBy, $head] = $groups[$key];

    $rows = reportRows($conn, "SELECT $label AS grp,
            COUNT(*) AS items,
            COALESCE(SUM(cm.premium_amount), 0) AS premium,
            COALESCE(SUM(cm.expected_amount), 0) AS expected,
            COALESCE(SUM(CASE WHEN cm.status IN ('received', 'discrepancy') THEN cm.received_amount END), 0) AS received,
            COALESCE(SUM(CASE WHEN cm.status = 'pending' THEN cm.expected_amount END), 0) AS pending,
            COALESCE(SUM(cm.sub_agent_amount), 0) AS sub_agent_amount
        FROM " . APP_SCHEMA . ".commissions cm
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cm.policy_id
        JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = cm.insurer_id
        LEFT JOIN " . APP_SCHEMA . ".sub_agents sa ON sa.sub_agent_id = cm.sub_agent_id
        WHERE cm.company_id = '$cid' AND cm.status <> 'cancelled' AND YEAR(p.coverage_start) = $year"
        . reportInsurerFilter($conn, $q, 'cm.insurer_id') . "
        GROUP BY $groupBy ORDER BY expected DESC");
    foreach ($rows as &$r) {
        if ($key === 'product') $r['grp'] = reportProduct($r['grp']);
        foreach (['items', 'premium', 'expected', 'received', 'pending', 'sub_agent_amount'] as $k) $r[$k] = (int)$r[$k];
        $r['net_agent'] = $r['expected'] - $r['sub_agent_amount'];
    }
    unset($r);

    return [
        'title'   => "Ringkasan Komisi per $head — $year",
        'columns' => [
            ['key' => 'grp', 'label' => $head, 'type' => 'text'],
            ['key' => 'items', 'label' => 'Polis', 'type' => 'int'],
            ['key' => 'premium', 'label' => 'Premi', 'type' => 'money'],
            ['key' => 'expected', 'label' => 'Komisi Seharusnya', 'type' => 'money'],
            ['key' => 'received', 'label' => 'Sudah Diterima', 'type' => 'money'],
            ['key' => 'pending', 'label' => 'Belum Diterima', 'type' => 'money'],
            ['key' => 'sub_agent_amount', 'label' => 'Bagian Sub-agen', 'type' => 'money'],
            ['key' => 'net_agent', 'label' => 'Bagian Agen Utama', 'type' => 'money'],
        ],
        'rows' => $rows,
        'totals' => reportTotals($rows, ['items', 'premium', 'expected', 'received', 'pending', 'sub_agent_amount', 'net_agent']),
    ];
}

function reportSubAgentProduction($conn, string $cid, array $q, int $year): array {
    $rows = reportRows($conn, "SELECT COALESCE(sa.full_name, 'Agen utama') AS agent,
            COUNT(*) AS policies, COUNT(DISTINCT p.customer_id) AS customers,
            COALESCE(SUM(p.premium_amount), 0) AS premium,
            COALESCE(SUM(p.commission_amount), 0) AS commission,
            COALESCE(SUM(cm.sub_agent_amount), 0) AS sub_agent_amount,
            SUM(p.renewal_status = 'renewed') AS renewed,
            SUM(p.renewal_status = 'lapsed') AS lapsed
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".sub_agents sa ON sa.sub_agent_id = p.issuing_agent_id
        LEFT JOIN " . APP_SCHEMA . ".commissions cm ON cm.policy_id = p.policy_id AND cm.status <> 'cancelled'
        WHERE p.company_id = '$cid' AND p.renewal_status <> 'cancelled' AND YEAR(p.coverage_start) = $year"
        . reportInsurerFilter($conn, $q, 'p.insurer_id') . "
        GROUP BY p.issuing_agent_id, sa.full_name ORDER BY premium DESC");
    foreach ($rows as &$r) {
        foreach (['policies', 'customers', 'premium', 'commission', 'sub_agent_amount', 'renewed', 'lapsed'] as $k) $r[$k] = (int)$r[$k];
        $r['renewal_rate'] = reportPct($r['renewed'], $r['renewed'] + $r['lapsed']);
    }
    unset($r);

    return [
        'title'   => "Produksi Sub-agen — $year",
        'columns' => [
            ['key' => 'agent', 'label' => 'Agen', 'type' => 'text'],
            ['key' => 'policies', 'label' => 'Polis', 'type' => 'int'],
            ['key' => 'customers', 'label' => 'Customer', 'type' => 'int'],
            ['key' => 'premium', 'label' => 'Premi', 'type' => 'money'],
            ['key' => 'commission', 'label' => 'Komisi', 'type' => 'money'],
            ['key' => 'sub_agent_amount', 'label' => 'Bagian Sub-agen', 'type' => 'money'],
            ['key' => 'renewal_rate', 'label' => '% Perpanjang', 'type' => 'pct'],
        ],
        'rows' => $rows,
        'totals' => reportTotals($rows, ['policies', 'premium', 'commission', 'sub_agent_amount']),
    ];
}

function reportClaims($conn, string $cid, array $q, int $year): array {
    $key = ($q['group_by'] ?? '') === 'product' ? 'product' : 'insurer';
    $col = $key === 'product' ? 'p.product_type' : 'i.short_name';
    $head = $key === 'product' ? 'Produk' : 'Insurer';
    $ins = reportInsurerFilter($conn, $q, 'p.insurer_id');

    $premium = [];
    foreach (reportRows($conn, "SELECT $col AS grp, COALESCE(SUM(p.premium_amount), 0) AS premium
        FROM " . APP_SCHEMA . ".policies p JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        WHERE p.company_id = '$cid' AND p.renewal_status <> 'cancelled' AND YEAR(p.coverage_start) = $year$ins
        GROUP BY $col") as $r) $premium[$r['grp']] = (int)$r['premium'];

    $claims = [];
    foreach (reportRows($conn, "SELECT $col AS grp, COUNT(*) AS claims,
            SUM(cl.status IN ('reported', 'survey', 'documents_complete', 'appeal')) AS open_claims,
            SUM(cl.status = 'rejected') AS rejected,
            COALESCE(SUM(cl.estimated_loss), 0) AS estimated,
            COALESCE(SUM(" . claimIncurredSql('cl') . "), 0) AS incurred
        FROM " . APP_SCHEMA . ".claims cl
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cl.policy_id
        JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        WHERE cl.company_id = '$cid' AND cl.is_deleted = 0 AND YEAR(cl.date_of_loss) = $year$ins
        GROUP BY $col") as $r) $claims[$r['grp']] = $r;

    $rows = [];
    foreach (array_unique(array_merge(array_keys($premium), array_keys($claims))) as $g) {
        $c = $claims[$g] ?? [];
        $incurred = (int)($c['incurred'] ?? 0);
        $prem = $premium[$g] ?? 0;
        $rows[] = [
            'grp'         => $key === 'product' ? reportProduct((string)$g) : $g,
            'premium'     => $prem,
            'claims'      => (int)($c['claims'] ?? 0),
            'open_claims' => (int)($c['open_claims'] ?? 0),
            'rejected'    => (int)($c['rejected'] ?? 0),
            'estimated'   => (int)($c['estimated'] ?? 0),
            'incurred'    => $incurred,
            'loss_ratio'  => reportPct($incurred, $prem),
        ];
    }
    usort($rows, static fn($a, $b) => $b['incurred'] <=> $a['incurred'] ?: $b['premium'] <=> $a['premium']);
    $totals = reportTotals($rows, ['premium', 'claims', 'open_claims', 'rejected', 'estimated', 'incurred']);
    $totals['loss_ratio'] = reportPct($totals['incurred'], $totals['premium']);

    return [
        'title'   => "Laporan Klaim & Loss Ratio per $head — $year",
        'columns' => [
            ['key' => 'grp', 'label' => $head, 'type' => 'text'],
            ['key' => 'premium', 'label' => 'Premi', 'type' => 'money'],
            ['key' => 'claims', 'label' => 'Klaim', 'type' => 'int'],
            ['key' => 'open_claims', 'label' => 'Berjalan', 'type' => 'int'],
            ['key' => 'rejected', 'label' => 'Ditolak', 'type' => 'int'],
            ['key' => 'estimated', 'label' => 'Estimasi Kerugian', 'type' => 'money'],
            ['key' => 'incurred', 'label' => 'Klaim Disetujui/Dibayar', 'type' => 'money'],
            ['key' => 'loss_ratio', 'label' => 'Loss Ratio', 'type' => 'pct'],
        ],
        'rows' => $rows, 'totals' => $totals,
    ];
}

function reportCommissionList($conn, string $cid, array $q): array {
    $where = "cm.company_id = '$cid'" . reportInsurerFilter($conn, $q, 'cm.insurer_id');
    if (!empty($q['status']) && in_array($q['status'], ['pending', 'received', 'discrepancy', 'cancelled'], true)) {
        $where .= " AND cm.status = '" . $q['status'] . "'";
    }
    if (!empty($q['commission_type']) && in_array($q['commission_type'], ['direct', 'override'], true)) {
        $where .= " AND cm.commission_type = '" . $q['commission_type'] . "'";
    }
    if (!empty($q['month']) && preg_match('/^\d{4}-\d{2}$/', $q['month'])) {
        $where .= " AND DATE_FORMAT(p.coverage_start, '%Y-%m') = '" . $q['month'] . "'";
    }
    if (!empty($q['product_type'])) {
        $where .= " AND p.product_type = '" . mysqli_real_escape_string($conn, $q['product_type']) . "'";
    }
    $rows = reportRows($conn, "SELECT p.policy_number, c.display_name AS customer, i.short_name AS insurer,
            p.product_type, p.coverage_start, cm.premium_amount AS premium, cm.commission_rate AS rate,
            cm.expected_amount AS expected, cm.received_amount AS received, cm.status,
            cm.received_date, cm.reference_number
        FROM " . APP_SCHEMA . ".commissions cm
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cm.policy_id
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = cm.insurer_id
        WHERE $where ORDER BY cm.created_at DESC LIMIT 5000");
    foreach ($rows as &$r) {
        $r['product'] = reportProduct($r['product_type']);
        foreach (['premium', 'expected', 'received'] as $k) $r[$k] = (int)$r[$k];
        $r['rate'] = (float)$r['rate'];
    }
    unset($r);

    return [
        'title'   => 'Daftar Komisi',
        'columns' => [
            ['key' => 'policy_number', 'label' => 'No. Polis', 'type' => 'text'],
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'],
            ['key' => 'insurer', 'label' => 'Insurer', 'type' => 'text'],
            ['key' => 'product', 'label' => 'Produk', 'type' => 'text'],
            ['key' => 'coverage_start', 'label' => 'Mulai', 'type' => 'date'],
            ['key' => 'premium', 'label' => 'Premi', 'type' => 'money'],
            ['key' => 'rate', 'label' => 'Rate %', 'type' => 'pct'],
            ['key' => 'expected', 'label' => 'Seharusnya', 'type' => 'money'],
            ['key' => 'received', 'label' => 'Diterima', 'type' => 'money'],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
            ['key' => 'received_date', 'label' => 'Tgl Diterima', 'type' => 'date'],
            ['key' => 'reference_number', 'label' => 'Referensi', 'type' => 'text'],
        ],
        'rows' => $rows, 'totals' => reportTotals($rows, ['premium', 'expected', 'received']),
    ];
}

// ── [v2.1.0] Accumulation, endorsement ledger, statement of account ───────────

function reportAccumulation($conn, string $cid, array $q): array {
    require_once __DIR__ . '/../helpers/db.php';
    require_once __DIR__ . '/../policies/locations.php';
    $by = in_array($q['group_by'] ?? '', ['city', 'eq_zone', 'district'], true) ? $q['group_by'] : 'area';
    $head = ['area' => 'Kawasan / Area', 'city' => 'Kota / Kabupaten', 'eq_zone' => 'Zona Gempa', 'district' => 'Kecamatan'][$by];
    $groups = [];
    foreach (accumulationPoints($conn, $cid) as $pt) {
        $key = match ($by) {
            'area' => $pt['area_name'] ?: ($pt['city'] ? $pt['city'] . ' (tanpa nama kawasan)' : 'Tanpa lokasi'),
            'city' => $pt['city'] ?: 'Tanpa kota',
            'district' => trim(($pt['district'] ?: '-') . ', ' . ($pt['city'] ?: '-')),
            'eq_zone' => $pt['eq_zone'] ? 'Zona ' . $pt['eq_zone'] : 'Zona belum diisi',
        };
        $g = &$groups[$key];
        $g ??= ['grp' => $key, 'locations' => 0, 'policies' => [], 'customers' => [], 'sum_insured' => 0, 'pml' => 0, 'largest' => 0];
        $si = (int)$pt['sum_insured'];
        $g['locations']++;
        $g['policies'][$pt['policy_id']] = true;
        $g['customers'][$pt['customer_name']] = true;
        $g['sum_insured'] += $si;
        $g['pml'] += $pt['pml_percent'] !== null ? (int)round($si * (float)$pt['pml_percent'] / 100) : $si;
        $g['largest'] = max($g['largest'], $si);
        unset($g);
    }
    $total = array_sum(array_column($groups, 'sum_insured'));
    $rows = array_map(fn($g) => ['grp' => $g['grp'], 'locations' => $g['locations'], 'policies' => count($g['policies']),
        'customers' => count($g['customers']), 'sum_insured' => $g['sum_insured'], 'pml' => $g['pml'], 'largest' => $g['largest'],
        'share_pct' => reportPct($g['sum_insured'], $total)], array_values($groups));
    usort($rows, fn($a, $b) => $b['sum_insured'] <=> $a['sum_insured']);
    return [
        'title'   => "Akumulasi Nilai Pertanggungan per $head — " . date('d/m/Y'),
        'columns' => [
            ['key' => 'grp', 'label' => $head, 'type' => 'text'],
            ['key' => 'locations', 'label' => 'Lokasi', 'type' => 'int'],
            ['key' => 'policies', 'label' => 'Polis', 'type' => 'int'],
            ['key' => 'customers', 'label' => 'Customer', 'type' => 'int'],
            ['key' => 'sum_insured', 'label' => 'Total Nilai Pertanggungan', 'type' => 'money'],
            ['key' => 'pml', 'label' => 'PML (atau TSI bila PML kosong)', 'type' => 'money'],
            ['key' => 'largest', 'label' => 'Lokasi Terbesar', 'type' => 'money'],
            ['key' => 'share_pct', 'label' => '% dari Total', 'type' => 'pct'],
        ],
        'rows' => $rows, 'totals' => reportTotals($rows, ['locations', 'policies', 'customers', 'sum_insured', 'pml']),
    ];
}

function reportEndorsements($conn, string $cid, array $q, int $year): array {
    $where = "e.company_id = '$cid' AND e.is_void = 0" . reportInsurerFilter($conn, $q, 'p.insurer_id');
    $period = (string)$year;
    if (!empty($q['month']) && preg_match('/^\d{4}-\d{2}$/', $q['month'])) {
        $where .= " AND DATE_FORMAT(e.effective_date, '%Y-%m') = '" . $q['month'] . "'";
        $period = $q['month'];
    } else {
        $where .= " AND YEAR(e.effective_date) = $year";
    }
    $types = ['sum_insured_change' => 'Perubahan nilai', 'add_item' => 'Tambah item', 'remove_item' => 'Hapus item', 'extension' => 'Perluasan',
              'insured_change' => 'Ganti tertanggung', 'period_change' => 'Perubahan periode', 'cancellation' => 'Pembatalan',
              'stock_adjustment' => 'Penyesuaian stok', 'other' => 'Lainnya'];
    $rows = reportRows($conn, "SELECT e.note_number, e.note_type, e.endorsement_number, e.endorsement_type, e.effective_date,
            p.policy_number, c.display_name AS customer, i.short_name AS insurer,
            e.premium_delta AS premium, (e.materai_amount + e.biaya_polis) AS fees, e.commission_delta AS commission,
            e.due_date, e.settled_date, e.commission_status
        FROM " . APP_SCHEMA . ".policy_endorsements e
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = e.policy_id
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        WHERE $where ORDER BY e.effective_date, e.created_at LIMIT 5000");
    foreach ($rows as &$r) {
        foreach (['premium', 'fees', 'commission'] as $k) $r[$k] = (int)$r[$k];
        $r['type'] = $types[$r['endorsement_type']] ?? $r['endorsement_type'];
        $r['note'] = $r['note_number'] ?? '-';
        $r['settled'] = $r['settled_date'] ? 'Lunas ' . $r['settled_date'] : ($r['note_type'] === 'none' ? '-' : 'Belum');
    }
    unset($r);
    return [
        'title'   => "Endorsemen & Nota Debit/Kredit — $period",
        'columns' => [
            ['key' => 'note', 'label' => 'No. Nota', 'type' => 'text'],
            ['key' => 'effective_date', 'label' => 'Berlaku', 'type' => 'date'],
            ['key' => 'policy_number', 'label' => 'No. Polis', 'type' => 'text'],
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'],
            ['key' => 'insurer', 'label' => 'Insurer', 'type' => 'text'],
            ['key' => 'type', 'label' => 'Jenis', 'type' => 'text'],
            ['key' => 'premium', 'label' => 'Premi (+/-)', 'type' => 'money'],
            ['key' => 'fees', 'label' => 'Biaya', 'type' => 'money'],
            ['key' => 'commission', 'label' => 'Komisi (+/-)', 'type' => 'money'],
            ['key' => 'settled', 'label' => 'Pelunasan', 'type' => 'text'],
            ['key' => 'commission_status', 'label' => 'Status Komisi', 'type' => 'text'],
        ],
        'rows' => $rows, 'totals' => reportTotals($rows, ['premium', 'fees', 'commission']),
    ];
}

/** Every note billed to a customer (or customer group): original debit notes, endorsement notes, multi-year premiums. */
function reportStatement($conn, string $cid, array $q): array {
    require_once __DIR__ . '/../helpers/db.php';
    $group = trim((string)($q['group_id'] ?? ''));
    $customer = trim((string)($q['customer_id'] ?? ''));
    if ($group === '' && $customer === '') Response::validationError(['customer_id' => 'customer_id atau group_id wajib diisi']);
    $scopeSql = $group !== '' ? 'c.group_id = ?' : 'c.customer_id = ?';
    $scope = $group !== '' ? $group : $customer;
    $name = $group !== '' ? dbValue($conn, "SELECT name FROM {app}.customer_groups WHERE group_id = ? AND company_id = ?", [$group, $cid])
                          : dbValue($conn, "SELECT display_name FROM {app}.customers WHERE customer_id = ? AND company_id = ?", [$customer, $cid]);
    if ($name === null) throw ApiException::from('ERR_V21_004');

    $rows = dbAll($conn, "SELECT p.coverage_start AS doc_date, COALESCE(p.debit_note_number, '-') AS note, 'Nota debit polis' AS kind,
            p.policy_number, c.display_name AS customer,
            COALESCE(p.issued_customer_premium, p.customer_premium_amount) AS amount,
            IF(p.payment_status IN ('paid', 'confirmed'), COALESCE(p.issued_customer_premium, p.customer_premium_amount), 0) AS paid,
            p.payment_date AS paid_date
        FROM {app}.policies p JOIN {app}.customers c ON c.customer_id = p.customer_id
        WHERE p.company_id = ? AND $scopeSql AND p.coverage_end >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        UNION ALL
        SELECT e.effective_date, COALESCE(e.note_number, '-'), IF(e.note_type = 'credit', 'Nota kredit endorsemen', 'Nota debit endorsemen'),
            p.policy_number, c.display_name, (e.premium_delta + e.materai_amount + e.biaya_polis),
            IF(e.settled_date IS NOT NULL, e.premium_delta + e.materai_amount + e.biaya_polis, 0), e.settled_date
        FROM {app}.policy_endorsements e JOIN {app}.policies p ON p.policy_id = e.policy_id JOIN {app}.customers c ON c.customer_id = p.customer_id
        WHERE e.company_id = ? AND $scopeSql AND e.is_void = 0 AND e.note_type <> 'none'
        UNION ALL
        SELECT y.period_start, CONCAT('Tahun ke-', y.year_no), 'Premi multi-tahun', p.policy_number, c.display_name, y.premium_amount,
            IF(y.paid_date IS NOT NULL, y.premium_amount, 0), y.paid_date
        FROM {app}.policy_year_schedule y JOIN {app}.policies p ON p.policy_id = y.policy_id JOIN {app}.customers c ON c.customer_id = p.customer_id
        WHERE y.company_id = ? AND $scopeSql AND y.year_no > 1
        ORDER BY doc_date", [$cid, $scope, $cid, $scope, $cid, $scope]);
    foreach ($rows as &$r) {
        $r['amount'] = (int)$r['amount'];
        $r['paid'] = (int)$r['paid'];
        $r['outstanding'] = $r['amount'] - $r['paid'];
    }
    unset($r);
    return [
        'title'   => 'Rekening Koran (Statement of Account) — ' . $name . ' — ' . date('d/m/Y'),
        'columns' => [
            ['key' => 'doc_date', 'label' => 'Tanggal', 'type' => 'date'],
            ['key' => 'note', 'label' => 'No. Nota', 'type' => 'text'],
            ['key' => 'kind', 'label' => 'Jenis', 'type' => 'text'],
            ['key' => 'policy_number', 'label' => 'No. Polis', 'type' => 'text'],
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Tagihan', 'type' => 'money'],
            ['key' => 'paid', 'label' => 'Dibayar', 'type' => 'money'],
            ['key' => 'outstanding', 'label' => 'Sisa', 'type' => 'money'],
            ['key' => 'paid_date', 'label' => 'Tgl Bayar', 'type' => 'date'],
        ],
        'rows' => $rows, 'totals' => reportTotals($rows, ['amount', 'paid', 'outstanding']),
    ];
}

// ── Excel export ──────────────────────────────────────────────────────────────

function reportExportXlsx(array $report, string $slug): void {
    $cols = $report['columns'];
    $xlsx = new XlsxWriter();
    $xlsx->setColWidths(array_map(static fn($c) => $c['type'] === 'text' ? 28 : 18, $cols));
    $xlsx->addRow([$report['title']], XlsxWriter::S_BOLD);
    $xlsx->addRow([]);
    $xlsx->addRow(array_column($cols, 'label'), XlsxWriter::S_BOLD);

    $cell = static function (array $c, $v) {
        if ($v === null || $v === '') return null;
        return match ($c['type']) {
            'int', 'money' => (int)$v,
            'pct'          => (float)$v,
            default        => (string)$v,
        };
    };
    foreach ($report['rows'] as $row) {
        $xlsx->addRow(array_map(static fn($c) => $cell($c, $row[$c['key']] ?? null), $cols));
    }
    if (!empty($report['totals'])) {
        $first = true;
        $xlsx->addRow(array_map(static function ($c) use ($report, &$first, $cell) {
            if ($first) { $first = false; return 'TOTAL'; }
            return isset($report['totals'][$c['key']]) ? $cell($c, $report['totals'][$c['key']]) : null;
        }, $cols), XlsxWriter::S_BOLD);
    }
    $xlsx->output("laporan-$slug-" . date('Ymd') . '.xlsx');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) { jsonResponse(400, 'company_id is required'); exit; }
if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); exit; }

try {
    $conn = getConn();
    $cid  = mysqli_real_escape_string($conn, $company_id);
    $year = isset($_GET['year']) && preg_match('/^\d{4}$/', $_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

    $report = match ($action) {
        'payment-consolidation' => reportPaymentConsolidation($conn, $cid, $_GET, $year),
        'lapsed'                => reportLapsed($conn, $cid, $_GET, $year),
        'portfolio'             => reportPortfolio($conn, $cid, $_GET),
        'retention'             => reportRetention($conn, $cid, $_GET, $year),
        'commissions'           => reportCommissions($conn, $cid, $_GET, $year),
        'sub-agent-production'  => reportSubAgentProduction($conn, $cid, $_GET, $year),
        'claims'                => reportClaims($conn, $cid, $_GET, $year),
        'commission-list'       => reportCommissionList($conn, $cid, $_GET),
        'accumulation'          => reportAccumulation($conn, $cid, $_GET),
        'endorsements'          => reportEndorsements($conn, $cid, $_GET, $year),
        'statement'             => reportStatement($conn, $cid, $_GET),
        default                 => null,
    };
    if ($report === null) { jsonResponse(404, 'Route not found'); exit; }

    if (($_GET['format'] ?? '') === 'xlsx') {
        requirePlanFeature($conn, $cid, 'export');
        reportExportXlsx($report, $action);
        exit;
    }
    jsonResponse(200, 'Report retrieved', $report);

} catch (ApiException $e) {
    throw $e;
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log($e->getMessage());
    Response::internalError();
}
