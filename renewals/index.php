<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/policy_log.php';
require_once __DIR__ . '/../helpers/renewal_message.php';
require_once __DIR__ . '/../notification/notification.php';

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

// --- RN-003: POST /api/v1/renewals/{policy_id}/send-whatsapp [NEW v1.1] ---
// Sends (or previews) a renewal follow-up WhatsApp message to the customer.
// Body: { message?: string } — overrides the saved default template for this send only.
function sendRenewalWhatsApp($conn, $policy_id, $company_id, $input, $username) {
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $result = mysqli_query($conn, "SELECT
            p.policy_id, p.policy_number, p.product_type, p.coverage_end,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            c.customer_type, c.display_name AS customer_name,
            c.personal_whatsapp, c.pic_whatsapp,
            i.short_name AS insurer_name
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE p.policy_id = '$policy_id' AND p.company_id = '$company_id'
        LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $row = mysqli_fetch_assoc($result);

    $waNumber = $row['customer_type'] === 'company' ? $row['pic_whatsapp'] : $row['personal_whatsapp'];
    if (!$waNumber) {
        jsonResponse(400, 'Customer has no WhatsApp number on file');
        return;
    }

    $template = DEFAULT_RENEWAL_WA_TEMPLATE;
    $settingsRes = mysqli_query($conn, "SELECT renewal_wa_message_template FROM " . APP_SCHEMA . ".notification_settings WHERE company_id = '$company_id' LIMIT 1");
    if ($settingsRes && mysqli_num_rows($settingsRes) > 0) {
        $saved = mysqli_fetch_assoc($settingsRes)['renewal_wa_message_template'];
        if ($saved) { $template = $saved; }
    }

    // Per-send override always wins, but is still run through placeholder substitution.
    if (!empty($input['message']) && trim($input['message']) !== '') {
        $template = trim($input['message']);
    }

    $message = renderRenewalMessage($template, $row);
    $chatId  = toWaChatId($waNumber);
    if (!$chatId) {
        jsonResponse(400, 'Customer WhatsApp number is invalid');
        return;
    }

    $sendResult = sendWhatsAppText($chatId, $message);
    if (empty($sendResult['success'])) {
        $error = $sendResult['error'] ?? $sendResult;
        insertPolicyLog($conn, $policy_id, $company_id, 'whatsapp_send_failed',
            'Gagal mengirim WhatsApp renewal reminder', $username,
            null, null, null, null, ['chatId' => $chatId, 'error' => $error]);
        jsonResponse(502, 'Failed to send WhatsApp message', ['error' => $error]);
        return;
    }

    // Log as a follow-up so it shows on the policy timeline, consistent with manual follow-up entries.
    $followup_id = 'fu_' . uniqid();
    $now         = date('Y-m-d H:i:s');
    $notes       = mysqli_real_escape_string($conn, $message);
    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".follow_up_logs
        (followup_id, policy_id, followup_status, channel, notes, followup_date, created_by, created_at)
        VALUES ('$followup_id', '$policy_id', 'contacted', 'whatsapp', '$notes', CURDATE(), '$username', '$now')");

    insertPolicyLog($conn, $policy_id, $company_id, 'followup_logged',
        'Follow-up dicatat via whatsapp: contacted', $username,
        null, null, 'follow_up_logs', $followup_id, ['message' => $message]);

    jsonResponse(200, 'WhatsApp message sent', ['message' => $message, 'followup_id' => $followup_id]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['user_id']    ?? $authUser['sub'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

// $action     = $parts[3] — 'stats', a policy_id, or '' (list)
// $sub_action = $parts[4] — 'send-whatsapp'
$sub_action = $parts[4] ?? '';

try {
    $conn = getConn();

    // POST /api/v1/renewals/{policy_id}/send-whatsapp
    if ($action !== '' && $action !== 'stats' && $sub_action === 'send-whatsapp') {
        if ($method !== 'POST') { jsonResponse(405, 'Method Not Allowed'); }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        sendRenewalWhatsApp($conn, $action, $company_id, $input, $username);

    // GET /api/v1/renewals/stats
    } elseif ($action === 'stats') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
        getRenewalStats($conn, $company_id, $month);

    // GET /api/v1/renewals
    } elseif ($action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
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
