<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method not allowed');
}

$authUser = requireAuth();
$userId   = cleanInput($authUser['sub'] ?? '');

$userResult = mysqli_query($conn, "SELECT company_id FROM " . CORE_SCHEMA . ".app_user
    WHERE user_id = '$userId' LIMIT 1");

if (!$userResult || mysqli_num_rows($userResult) === 0) {
    jsonResponse(401, 'Token tidak valid atau sudah kedaluwarsa.');
}

$user      = mysqli_fetch_assoc($userResult);
$companyId = cleanInput($user['company_id']);

$subResult = mysqli_query($conn, "SELECT s.subscription_id, s.service_id, s.billing_cycle, s.subscription_status,
        s.start_date, s.next_billing_date, s.payment_status,
        svc.service_name, svc.service_price
    FROM " . CORE_SCHEMA . ".app_subscription s
    JOIN " . CORE_SCHEMA . ".app_services svc ON s.service_id = svc.service_id
    WHERE s.app_company_id = '$companyId' AND s.subscription_status = 'active'
    ORDER BY s.created_at DESC
    LIMIT 1");

if (!$subResult || mysqli_num_rows($subResult) === 0) {
    jsonResponse(404, 'Tidak ada langganan aktif.');
}

$sub = mysqli_fetch_assoc($subResult);

$activePolicyCount = 0;
$policyResult = mysqli_query($conn, "SELECT COUNT(*) AS cnt
    FROM " . APP_SCHEMA . ".app_policy
    WHERE company_id = '$companyId' AND status = 'active'");
if ($policyResult) {
    $pRow = mysqli_fetch_assoc($policyResult);
    $activePolicyCount = (int)($pRow['cnt'] ?? 0);
}

$periodEnd = date('Y-m-d', strtotime($sub['next_billing_date'] . ' -1 day'));

jsonResponse(200, 'OK', [
    'subscription_id'      => $sub['subscription_id'],
    'plan'                 => [
        'plan_id'   => $sub['service_id'],
        'name'      => $sub['service_name'],
        'price_idr' => (int)$sub['service_price'],
    ],
    'billing_cycle'        => $sub['billing_cycle'],
    'status'               => $sub['subscription_status'],
    'payment_status'       => $sub['payment_status'],
    'current_period_start' => $sub['start_date'],
    'current_period_end'   => $periodEnd,
    'next_billing_date'    => $sub['next_billing_date'],
    'usage'                => [
        'active_policy_count' => $activePolicyCount,
    ],
]);
