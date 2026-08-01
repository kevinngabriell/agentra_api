<?php
// [NEW v1.1] Revenue / production report — how much premium was produced in a
// given month, broken down by week and by agent, so commission can be
// cross-checked manually against insurer statements.

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- RV-001: GET /api/v1/revenue/summary?month=YYYY-MM ---
function getRevenueSummary($conn, $company_id, $month) {
    $cid   = mysqli_real_escape_string($conn, $company_id);
    $where = "p.company_id = '$cid' AND DATE_FORMAT(p.coverage_start, '%Y-%m') = '$month'";

    // ── Month totals ────────────────────────────────────────────────────────
    $totRes = mysqli_query($conn, "SELECT
            COUNT(*) AS policies_count,
            COALESCE(SUM(p.premium_amount), 0)         AS total_premium,
            COALESCE(SUM(p.commission_amount), 0)       AS total_commission_amount,
            COALESCE(SUM(p.net_commission_amount), 0)   AS total_net_commission_amount,
            COALESCE(SUM(p.customer_premium_amount), 0) AS total_customer_premium_amount
        FROM " . APP_SCHEMA . ".policies p
        WHERE $where");
    $totals = $totRes ? mysqli_fetch_assoc($totRes) : [];

    // ── Weekly breakdown (Mon–Sun weeks, clipped to the month) ─────────────
    $monthStart = new DateTime($month . '-01');
    $monthEnd   = (clone $monthStart)->modify('last day of this month');

    $weekly    = [];
    $cursor    = clone $monthStart;
    while ($cursor <= $monthEnd) {
        $weekStart = clone $cursor;
        $weekEnd   = (clone $cursor)->modify('sunday this week');
        if ($weekEnd > $monthEnd) { $weekEnd = clone $monthEnd; }

        $ws = $weekStart->format('Y-m-d');
        $we = $weekEnd->format('Y-m-d');

        $wRes = mysqli_query($conn, "SELECT
                COUNT(*) AS policies_count,
                COALESCE(SUM(p.premium_amount), 0)       AS total_premium,
                COALESCE(SUM(p.commission_amount), 0)     AS total_commission_amount,
                COALESCE(SUM(p.net_commission_amount), 0) AS total_net_commission_amount
            FROM " . APP_SCHEMA . ".policies p
            WHERE p.company_id = '$cid' AND p.coverage_start BETWEEN '$ws' AND '$we'");
        $w = $wRes ? mysqli_fetch_assoc($wRes) : [];

        $weekly[] = [
            'week_start'                  => $ws,
            'week_end'                    => $we,
            'policies_count'              => (int)($w['policies_count'] ?? 0),
            'total_premium'                => (int)($w['total_premium'] ?? 0),
            'total_commission_amount'      => (int)($w['total_commission_amount'] ?? 0),
            'total_net_commission_amount'  => (int)($w['total_net_commission_amount'] ?? 0),
        ];

        $cursor = $weekEnd->modify('+1 day');
    }

    // ── Per-agent breakdown ──────────────────────────────────────────────────
    $agentRes = mysqli_query($conn, "SELECT
            p.issuing_agent_id,
            COALESCE(u.first_name, 'Main Agent') AS agent_name,
            COUNT(*) AS policies_count,
            COALESCE(SUM(p.premium_amount), 0)       AS total_premium,
            COALESCE(SUM(p.commission_amount), 0)     AS total_commission_amount,
            COALESCE(SUM(p.net_commission_amount), 0) AS total_net_commission_amount
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . CORE_SCHEMA . ".app_user u ON u.user_id COLLATE utf8mb4_unicode_ci = p.issuing_agent_id
        WHERE $where
        GROUP BY p.issuing_agent_id, u.first_name
        ORDER BY total_premium DESC");

    $byAgent = [];
    if ($agentRes) {
        foreach (mysqli_fetch_all($agentRes, MYSQLI_ASSOC) as $a) {
            $byAgent[] = [
                'agent_id'                     => $a['issuing_agent_id'],
                'agent_name'                   => $a['agent_name'],
                'policies_count'                => (int)$a['policies_count'],
                'total_premium'                 => (int)$a['total_premium'],
                'total_commission_amount'       => (int)$a['total_commission_amount'],
                'total_net_commission_amount'   => (int)$a['total_net_commission_amount'],
            ];
        }
    }

    jsonResponse(200, 'Revenue summary retrieved', [
        'month' => $month,
        'totals' => [
            'policies_count'                => (int)($totals['policies_count'] ?? 0),
            'total_premium'                  => (int)($totals['total_premium'] ?? 0),
            'total_commission_amount'        => (int)($totals['total_commission_amount'] ?? 0),
            'total_net_commission_amount'    => (int)($totals['total_net_commission_amount'] ?? 0),
            'total_customer_premium_amount'  => (int)($totals['total_customer_premium_amount'] ?? 0),
        ],
        'weekly'   => $weekly,
        'by_agent' => $byAgent,
    ]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

if ($method !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
    exit;
}

$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])
    ? $_GET['month']
    : date('Y-m');

try {
    $conn = getConn();

    if ($action === 'summary') {
        getRevenueSummary($conn, $company_id, $month);
    } else {
        jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
