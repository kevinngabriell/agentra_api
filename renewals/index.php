<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- RN-001: GET /api/v1/renewals?month=YYYY-MM ---
// List of policies expiring in the given month with renewal tracking info
function getRenewalList($conn, $company_id, $month, $renewal_status, $page, $limit) {
    $offset = ($page - 1) * $limit;

    $where = "p.company_id = '$company_id' AND DATE_FORMAT(p.coverage_end, '%Y-%m') = '$month'";
    if ($renewal_status && in_array($renewal_status, ['pending', 'renewed', 'lapsed', 'cancelled'], true)) {
        $where .= " AND p.renewal_status = '$renewal_status'";
    }

    $query = "SELECT
            p.policy_id, p.policy_number, p.product_type,
            p.coverage_end, p.renewal_status, p.payment_status,
            p.premium_amount, p.commission_amount,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            c.display_name   AS customer_name,
            c.personal_whatsapp AS customer_whatsapp,
            i.short_name     AS insurer_name,
            (SELECT fl.followup_status
             FROM " . APP_SCHEMA . ".follow_up_logs fl
             WHERE fl.policy_id = p.policy_id
             ORDER BY fl.followup_date DESC, fl.created_at DESC
             LIMIT 1) AS last_follow_up_status,
            (SELECT fl.followup_date
             FROM " . APP_SCHEMA . ".follow_up_logs fl
             WHERE fl.policy_id = p.policy_id
             ORDER BY fl.followup_date DESC, fl.created_at DESC
             LIMIT 1) AS last_follow_up_date
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE $where
        ORDER BY p.coverage_end ASC
        LIMIT $limit OFFSET $offset";

    $countQuery = "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".policies p WHERE $where";

    $result      = mysqli_query($conn, $query);
    $countResult = mysqli_query($conn, $countQuery);
    $total       = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;
    $data        = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Renewals found', [
        'month' => $month,
        'data'  => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
        ],
    ]);
}

// --- RN-002: GET /api/v1/renewals/stats?month=YYYY-MM ---
// Aggregated renewal stats for the StatusPerpanjangan component
function getRenewalStats($conn, $company_id, $month) {
    $r = mysqli_query($conn, "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN renewal_status = 'renewed'   THEN 1 ELSE 0 END) AS renewed,
            SUM(CASE WHEN renewal_status = 'pending'   THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN renewal_status = 'lapsed'    THEN 1 ELSE 0 END) AS lapsed,
            SUM(CASE WHEN renewal_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN renewal_status = 'renewed'   THEN premium_amount ELSE 0 END) AS achieved_omzet,
            SUM(premium_amount) AS total_omzet_potential
        FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$company_id'
          AND DATE_FORMAT(coverage_end, '%Y-%m') = '$month'");

    if (!$r) {
        jsonResponse(500, 'Failed to fetch renewal stats', ['error' => mysqli_error($conn)]);
        return;
    }

    $row   = mysqli_fetch_assoc($r);
    $total = (int)($row['total'] ?? 0);

    $renewed     = (int)($row['renewed']     ?? 0);
    $in_progress = (int)($row['in_progress'] ?? 0);
    $lapsed      = (int)($row['lapsed']      ?? 0);
    $cancelled   = (int)($row['cancelled']   ?? 0);

    $pct = fn($n) => $total > 0 ? round(($n / $total) * 100, 1) : 0;

    jsonResponse(200, 'Renewal stats retrieved', [
        'month'               => $month,
        'total'               => $total,
        'achieved_omzet'      => (int)($row['achieved_omzet']       ?? 0),
        'total_omzet_potential' => (int)($row['total_omzet_potential'] ?? 0),
        'breakdown' => [
            'renewed'     => ['count' => $renewed,     'pct' => $pct($renewed)],
            'in_progress' => ['count' => $in_progress, 'pct' => $pct($in_progress)],
            'lapsed'      => ['count' => $lapsed,      'pct' => $pct($lapsed)],
            'cancelled'   => ['count' => $cancelled,   'pct' => $pct($cancelled)],
        ],
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

    // GET /api/v1/renewals/stats
    if ($action === 'stats') {
        getRenewalStats($conn, $company_id, $month);

    // GET /api/v1/renewals
    } elseif ($action === '') {
        $renewal_status = isset($_GET['renewal_status'])
            ? mysqli_real_escape_string($conn, $_GET['renewal_status'])
            : '';
        $page  = max(1, (int)($_GET['page']  ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 10)));
        getRenewalList($conn, $company_id, $month, $renewal_status, $page, $limit);

    } else {
        jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
