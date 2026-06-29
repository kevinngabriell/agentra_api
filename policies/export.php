<?php

require_once __DIR__ . '/../helpers/xlsx_writer.php';

// ── Formatting helpers ────────────────────────────────────────────────────────

/**
 * Format a TSI (sum_insured) value as Indonesian short notation.
 *   >= 1 Milyar → "X M"  (e.g. 1,200,000,000 → "1,2 M")
 *   <  1 Milyar → "X JT" (e.g.   500,000,000 → "500 JT")
 */
function formatAmount(int $tsi): string {
    if ($tsi >= 1_000_000_000) {
        $val = $tsi / 1_000_000_000;
        $str = fmod($val, 1.0) == 0
            ? (string)(int)$val
            : str_replace('.', ',', rtrim(rtrim(sprintf('%.3f', $val), '0'), '.'));
        return $str . ' M';
    }
    $val = $tsi / 1_000_000;
    $str = fmod($val, 1.0) == 0
        ? (string)(int)$val
        : str_replace('.', ',', rtrim(rtrim(sprintf('%.3f', $val), '0'), '.'));
    return $str . ' JT';
}

/**
 * Format a single coverage cell: "{label}={amount}" or just "{amount}".
 * Returns null when the coverage item is absent or has zero TSI.
 */
function formatCovCell(?array $cov): ?string {
    if (!$cov || !(int)$cov['tsi']) return null;
    $amt = formatAmount((int)$cov['tsi']);
    return $cov['label'] ? $cov['label'] . '=' . $amt : $amt;
}

/**
 * Format a rate_permille value to Indonesian decimal string.
 * e.g. 0.328 → "0,328"  |  2.28 → "2,28"  |  1.0 → "1"
 */
function formatRate(float $rate): string {
    if (fmod($rate, 1.0) == 0.0) return (string)(int)$rate;
    return str_replace('.', ',', rtrim(rtrim(sprintf('%.4f', $rate), '0'), '.'));
}

// ── Indonesian month labels ───────────────────────────────────────────────────

const ID_MONTHS = [
    1  => 'JANUARI', 2  => 'FEBRUARI', 3  => 'MARET',
    4  => 'APRIL',   5  => 'MEI',      6  => 'JUNI',
    7  => 'JULI',    8  => 'AGUSTUS',  9  => 'SEPTEMBER',
    10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER',
];

// ── Main export function ──────────────────────────────────────────────────────

/**
 * PO-013 — Export policies to XLSX.
 *
 * Accepts the same query filters as the list endpoint:
 *   month         YYYY-MM  filter by coverage_start month (primary monthly export)
 *   expiry_month  YYYY-MM  filter by coverage_end month
 *   search        string   policy number or customer name
 *   customer_id
 *   product_type
 *   insurer_id
 *   renewal_status
 *   agent_id
 */
function exportPolicies($conn, $company_id, array $params): void {
    $search         = isset($params['search'])         ? mysqli_real_escape_string($conn, $params['search'])         : '';
    $customer_id    = isset($params['customer_id'])    ? mysqli_real_escape_string($conn, $params['customer_id'])    : '';
    $product_type   = isset($params['product_type'])   ? mysqli_real_escape_string($conn, $params['product_type'])   : '';
    $insurer_id     = isset($params['insurer_id'])     ? mysqli_real_escape_string($conn, $params['insurer_id'])     : '';
    $renewal_status = isset($params['renewal_status']) ? mysqli_real_escape_string($conn, $params['renewal_status']) : '';
    $month          = isset($params['month'])          ? mysqli_real_escape_string($conn, $params['month'])          : '';
    $expiry_month   = isset($params['expiry_month'])   ? mysqli_real_escape_string($conn, $params['expiry_month'])   : '';
    $agent_id       = isset($params['agent_id'])       ? mysqli_real_escape_string($conn, $params['agent_id'])       : '';

    $where = "p.company_id = '$company_id'";

    if ($search) {
        $where .= " AND (p.policy_number LIKE '%$search%' OR c.display_name LIKE '%$search%' OR c.company_legal_name LIKE '%$search%')";
    }
    if ($customer_id) {
        $where .= " AND p.customer_id = '$customer_id'";
    }
    if ($product_type) {
        $where .= " AND p.product_type = '$product_type'";
    }
    if ($insurer_id) {
        $where .= " AND p.insurer_id = '$insurer_id'";
    }
    if ($renewal_status && in_array($renewal_status, ['pending', 'renewed', 'lapsed', 'cancelled'], true)) {
        $where .= " AND p.renewal_status = '$renewal_status'";
    }
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where .= " AND DATE_FORMAT(p.coverage_start, '%Y-%m') = '$month'";
    }
    if ($expiry_month && preg_match('/^\d{4}-\d{2}$/', $expiry_month)) {
        $where .= " AND DATE_FORMAT(p.coverage_end, '%Y-%m') = '$expiry_month'";
    }
    if ($agent_id) {
        $where .= " AND p.issuing_agent_id = '$agent_id'";
    }

    // Fetch all matching policies, ordered by agent then coverage_start.
    // Joins: customer (name, email, phone), issuing agent user (name),
    //        previous policy (policy_number for NO POLIS LAMA column).
    $sql = "
        SELECT
            p.policy_id,
            p.policy_number,
            p.commission_rate,
            p.commission_tax_rate,
            p.object_insured,
            p.coverage_start,
            p.premium_amount,
            p.biaya_polis,
            p.materai_amount,
            p.commission_amount,
            p.commission_tax_amount,
            p.net_commission_amount,
            p.customer_premium_amount,
            p.issuing_agent_id,
            COALESCE(u.first_name, '') AS agent_name,
            c.display_name AS customer_name,
            COALESCE(c.personal_email, c.company_email, c.pic_email, '') AS customer_email,
            COALESCE(c.personal_phone, c.company_phone, c.pic_phone, '') AS customer_phone,
            COALESCE(pp.policy_number, 'BARU') AS prev_policy_number
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c  ON c.customer_id = p.customer_id
        LEFT JOIN " . CORE_SCHEMA . ".app_user u  ON u.user_id     = p.issuing_agent_id
        LEFT JOIN " . APP_SCHEMA . ".policies pp  ON pp.policy_id  = p.previous_policy_id
        WHERE $where
        ORDER BY COALESCE(u.first_name, ''), p.issuing_agent_id, p.coverage_start ASC
    ";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        jsonResponse(500, 'Export query failed', ['error' => mysqli_error($conn)]);
        return;
    }

    $policies = mysqli_fetch_all($result, MYSQLI_ASSOC);
    if (empty($policies)) {
        jsonResponse(404, 'No policies found for export');
        return;
    }

    // Bulk fetch coverage items for all returned policies.
    $policyIds = array_map(fn($p) => "'" . mysqli_real_escape_string($conn, $p['policy_id']) . "'", $policies);
    $idsIn     = implode(',', $policyIds);

    $covSql = "
        SELECT policy_id, coverage_type, coverage_label, sum_insured, rate_permille
        FROM " . APP_SCHEMA . ".policy_coverages
        WHERE policy_id IN ($idsIn)
        ORDER BY FIELD(coverage_type,'bangunan','stok','invenisi','mesin','dll'), created_at ASC
    ";
    $covResult = mysqli_query($conn, $covSql);

    // Build coverage map: policy_id → coverage_type → [ [tsi, label, rate], ... ]
    $covMap = [];
    if ($covResult) {
        while ($cov = mysqli_fetch_assoc($covResult)) {
            $covMap[$cov['policy_id']][$cov['coverage_type']][] = [
                'tsi'   => (int)$cov['sum_insured'],
                'label' => $cov['coverage_label'],
                'rate'  => (float)$cov['rate_permille'],
            ];
        }
    }

    // ── Determine export title / filename ─────────────────────────────────────

    $titleLabel = 'DAFTAR POLIS';
    if ($month && preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
        $monthName  = ID_MONTHS[(int)$m[2]] ?? $m[2];
        $titleLabel = $monthName . ' ' . $m[1];
    } elseif ($expiry_month && preg_match('/^(\d{4})-(\d{2})$/', $expiry_month, $m)) {
        $monthName  = ID_MONTHS[(int)$m[2]] ?? $m[2];
        $titleLabel = $monthName . ' ' . $m[1];
    }

    $filename = $titleLabel . '.xlsx';

    // ── Build XLSX ────────────────────────────────────────────────────────────

    $xlsx = new XlsxWriter();
    $xlsx->setColWidths([
        0  => 22,   // A  NO POLIS LAMA
        1  => 24,   // B  NO POLIS
        2  => 7,    // C  commission rate
        3  => 7,    // D  tax rate
        4  => 32,   // E  AGEN / Tertanggung
        5  => 28,   // F  EMAIL
        6  => 16,   // G  HP
        7  => 42,   // H  LOKASI PERTANGGUNGAN
        8  => 6,    // I  DATE
        9  => 20,   // J  BANGUNAN
        10 => 20,   // K  STOK 1
        11 => 20,   // L  STOK 2
        12 => 20,   // M  INVEN/ISI
        13 => 20,   // N  MESIN
        14 => 20,   // O  DLL
        15 => 16,   // P  RATE
        16 => 14,   // Q  PREMI NETT
        17 => 14,   // R  PREMI
        18 => 14,   // S  KOMISI
        19 => 12,   // T  PAJAK
        20 => 14,   // U  KOMISI NETT
        21 => 18,   // V  PREMI YG HRS DISETOR
    ]);

    // Row 1 — title
    $xlsx->addRow([null, $titleLabel], XlsxWriter::S_BOLD);

    // Row 2 — column headers (C/D intentionally blank per original format)
    $xlsx->addRow([
        'NO POLIS LAMA',
        'NO POLIS',
        null,
        null,
        'AGEN',
        'EMAIL',
        'HP',
        'LOKASI PERTANGGUNGAN',
        'DATE',
        'BANGUNAN',
        'STOK 1',
        'STOK 2',
        'INVEN/ISI',
        'MESIN',
        'DLL',
        'RATE',
        'PREMI NETT',
        'PREMI',
        'KOMISI',
        'PAJAK',
        'KOMISI NETT',
        'PREMI   YG HRS  DISETOR',
    ], XlsxWriter::S_BOLD);

    // Data rows — grouped by issuing agent
    $prevAgentId = null;

    foreach ($policies as $p) {
        $agentId   = $p['issuing_agent_id'];
        $agentName = trim($p['agent_name']) ?: '-';

        // Agent sub-header row whenever the agent changes
        if ($agentId !== $prevAgentId) {
            $xlsx->addRow([
                $agentName,
                null,
                (float)($p['commission_rate'] / 100),
                (float)$p['commission_tax_rate'],
            ], XlsxWriter::S_BOLD);
            $prevAgentId = $agentId;
        }

        // Coverage items for this policy
        $covs = $covMap[$p['policy_id']] ?? [];

        $bangunan = $covs['bangunan'][0] ?? null;
        $stok1    = $covs['stok'][0]     ?? null;
        $stok2    = $covs['stok'][1]     ?? null;
        $invenisi = $covs['invenisi'][0] ?? null;
        $mesin    = $covs['mesin'][0]    ?? null;
        $dll      = $covs['dll'][0]      ?? null;

        // Aggregate all rate_permille values in TSI-type order
        $rateStrs = [];
        foreach (['bangunan', 'stok', 'invenisi', 'mesin', 'dll'] as $type) {
            foreach ($covs[$type] ?? [] as $item) {
                $rateStrs[] = formatRate($item['rate']);
            }
        }
        $rateStr = !empty($rateStrs) ? implode('+', $rateStrs) : null;

        $premi = (int)$p['premium_amount'] + (int)$p['biaya_polis'] + (int)$p['materai_amount'];
        $day   = $p['coverage_start'] ? (int)date('j', strtotime($p['coverage_start'])) : null;

        $xlsx->addRow([
            $p['prev_policy_number'],                         // A  NO POLIS LAMA
            $p['policy_number'],                              // B  NO POLIS
            (float)($p['commission_rate'] / 100),             // C  commission rate
            (float)$p['commission_tax_rate'],                 // D  tax rate
            $p['customer_name'],                              // E  AGEN / Tertanggung
            $p['customer_email'] ?: null,                    // F  EMAIL
            $p['customer_phone'] ?: null,                    // G  HP
            $p['object_insured'] ?: null,                    // H  LOKASI PERTANGGUNGAN
            $day,                                             // I  DATE
            formatCovCell($bangunan),                         // J  BANGUNAN
            formatCovCell($stok1),                            // K  STOK 1
            formatCovCell($stok2),                            // L  STOK 2
            formatCovCell($invenisi),                         // M  INVEN/ISI
            formatCovCell($mesin),                            // N  MESIN
            formatCovCell($dll),                              // O  DLL
            $rateStr,                                         // P  RATE
            (int)$p['premium_amount']         ?: null,       // Q  PREMI NETT
            $premi                            ?: null,        // R  PREMI
            (int)$p['commission_amount']      ?: null,       // S  KOMISI
            (int)$p['commission_tax_amount']  ?: null,       // T  PAJAK
            (int)$p['net_commission_amount']  ?: null,       // U  KOMISI NETT
            (int)$p['customer_premium_amount'] ?: null,      // V  PREMI YG HRS DISETOR
        ]);
    }

    $xlsx->output($filename);
}
