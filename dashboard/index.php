<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- DB-001: GET /api/v1/dashboard/stats ---
function getDashboardStats($conn, $company_id) {
    $cid = $company_id;

    $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid' AND renewal_status NOT IN ('lapsed', 'cancelled')");
    $total_active = $r ? (int)mysqli_fetch_assoc($r)['cnt'] : 0;

    $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid'
          AND DATE_FORMAT(coverage_end, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
          AND renewal_status = 'pending'");
    $renewals_this_month = $r ? (int)mysqli_fetch_assoc($r)['cnt'] : 0;

    $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid'
          AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND renewal_status = 'pending'");
    $expiring_this_week = $r ? (int)mysqli_fetch_assoc($r)['cnt'] : 0;

    $pending_commissions = 0;
    $r = mysqli_query($conn, "SELECT SUM(expected_amount) AS total FROM " . APP_SCHEMA . ".commissions
        WHERE company_id = '$cid' AND status = 'pending'");
    if ($r) {
        $row = mysqli_fetch_assoc($r);
        $pending_commissions = (int)($row['total'] ?? 0);
    }

    // Trend: policies created this month vs last month
    $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid' AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')");
    $pol_this = $r ? (int)mysqli_fetch_assoc($r)['cnt'] : 0;

    $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid' AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m')");
    $pol_last = $r ? (int)mysqli_fetch_assoc($r)['cnt'] : 0;

    $pol_diff = $pol_this - $pol_last;
    $pol_pct  = $pol_last > 0 ? round(($pol_diff / $pol_last) * 100, 1) : 0;
    $pol_trend = ($pol_pct >= 0 ? '+' : '') . $pol_pct . '%';

    // Trend: renewals this month vs last month
    $r = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid'
          AND DATE_FORMAT(coverage_end, '%Y-%m') = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m')
          AND renewal_status IN ('renewed', 'pending', 'lapsed')");
    $ren_last = $r ? (int)mysqli_fetch_assoc($r)['cnt'] : 0;

    $ren_diff = $renewals_this_month - $ren_last;
    $ren_trend = ($ren_diff >= 0 ? '+' : '') . $ren_diff . ' polis';

    // Renewal breakdown for current month (policies with coverage_end in this month)
    $r = mysqli_query($conn, "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN renewal_status = 'renewed'   THEN 1 ELSE 0 END) AS renewed,
            SUM(CASE WHEN renewal_status = 'pending'   THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN renewal_status = 'lapsed'    THEN 1 ELSE 0 END) AS lapsed,
            SUM(CASE WHEN renewal_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN renewal_status = 'renewed'   THEN premium_amount ELSE 0 END) AS achieved_omzet
        FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid'
          AND DATE_FORMAT(coverage_end, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')");

    $bd = $r ? mysqli_fetch_assoc($r) : [];
    $bd_total = (int)($bd['total'] ?? 0);
    $renewal_breakdown = [
        'total'          => $bd_total,
        'renewed'        => (int)($bd['renewed']     ?? 0),
        'in_progress'    => (int)($bd['in_progress'] ?? 0),
        'lapsed'         => (int)($bd['lapsed']      ?? 0),
        'cancelled'      => (int)($bd['cancelled']   ?? 0),
        'achieved_omzet' => (int)($bd['achieved_omzet'] ?? 0),
        'renewed_pct'    => $bd_total > 0 ? round(((int)($bd['renewed']     ?? 0) / $bd_total) * 100, 1) : 0,
        'in_progress_pct'=> $bd_total > 0 ? round(((int)($bd['in_progress'] ?? 0) / $bd_total) * 100, 1) : 0,
        'lapsed_pct'     => $bd_total > 0 ? round(((int)($bd['lapsed']      ?? 0) / $bd_total) * 100, 1) : 0,
    ];

    jsonResponse(200, 'OK', [
        'total_active_policies'      => $total_active,
        'renewals_this_month'        => $renewals_this_month,
        'expiring_this_week'         => $expiring_this_week,
        'pending_commissions_amount' => $pending_commissions,
        'renewal_breakdown'          => $renewal_breakdown,
        'trends' => [
            'policies_vs_last_month'  => $pol_trend,
            'renewals_vs_last_month'  => $ren_trend,
        ],
    ]);
}

// --- DB-002: GET /api/v1/dashboard/activity ---
function getDashboardActivity($conn, $company_id, $limit) {
    $items = [];
    $r = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".activity_logs
        WHERE company_id = '$company_id'
        ORDER BY created_at DESC
        LIMIT $limit");
    if ($r) {
        $items = mysqli_fetch_all($r, MYSQLI_ASSOC);
    }

    jsonResponse(200, 'OK', ['items' => $items]);
}

// --- DB-003: GET /api/v1/dashboard/today-actions ---
function getDashboardTodayActions($conn, $company_id, $limit) {
    $r = mysqli_query($conn, "SELECT
            p.policy_id, p.policy_number, p.product_type,
            p.coverage_end, p.renewal_status,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            c.display_name   AS customer_name,
            c.personal_whatsapp AS customer_whatsapp,
            i.short_name     AS insurer_name,
            (SELECT fl.followup_status
             FROM " . APP_SCHEMA . ".follow_up_logs fl
             WHERE fl.policy_id = p.policy_id
             ORDER BY fl.followup_date DESC, fl.created_at DESC
             LIMIT 1) AS last_follow_up_status
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE p.company_id = '$company_id'
          AND p.coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND p.renewal_status = 'pending'
        ORDER BY p.coverage_end ASC
        LIMIT $limit");

    $items = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];

    $rt = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$company_id' AND coverage_end = CURDATE() AND renewal_status = 'pending'");
    $total_today = $rt ? (int)mysqli_fetch_assoc($rt)['cnt'] : 0;

    $rw = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$company_id'
          AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND renewal_status = 'pending'");
    $total_week = $rw ? (int)mysqli_fetch_assoc($rw)['cnt'] : 0;

    jsonResponse(200, 'OK', [
        'items'       => $items,
        'total_today' => $total_today,
        'total_week'  => $total_week,
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

try {
    $conn = getConn();

    switch ($action) {
        case 'stats':
            getDashboardStats($conn, $company_id);
            break;
        case 'activity':
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
            getDashboardActivity($conn, $company_id, $limit);
            break;
        case 'today-actions':
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
            getDashboardTodayActions($conn, $company_id, $limit);
            break;
        default:
            jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
