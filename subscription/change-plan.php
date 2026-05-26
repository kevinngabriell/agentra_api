<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    jsonResponse(405, 'Method not allowed');
}

$authUser = requireAuth();
$userId   = cleanInput($authUser['sub'] ?? '');

$body         = input();
$newPlanId    = cleanInput($body['new_plan_id']   ?? '');
$billingCycle = cleanInput($body['billing_cycle'] ?? '');

if (!$newPlanId || !$billingCycle) {
    jsonResponse(400, 'new_plan_id dan billing_cycle wajib diisi.');
}

if (!in_array($billingCycle, ['monthly', 'yearly'], true)) {
    jsonResponse(400, 'billing_cycle harus monthly atau yearly.');
}

$userResult = mysqli_query($conn, "SELECT company_id FROM " . CORE_SCHEMA . ".app_user
    WHERE user_id = '$userId' LIMIT 1");

if (!$userResult || mysqli_num_rows($userResult) === 0) {
    jsonResponse(401, 'Token tidak valid atau sudah kedaluwarsa.');
}

$user      = mysqli_fetch_assoc($userResult);
$companyId = cleanInput($user['company_id']);

$planResult = mysqli_query($conn, "SELECT service_id, service_name, service_price
    FROM " . CORE_SCHEMA . ".app_services
    WHERE service_id = '$newPlanId' AND is_active = 1
    LIMIT 1");

if (!$planResult || mysqli_num_rows($planResult) === 0) {
    jsonResponse(400, 'Plan yang dipilih tidak valid atau tidak tersedia.');
}

$newPlan = mysqli_fetch_assoc($planResult);

$subResult = mysqli_query($conn, "SELECT subscription_id, service_id, billing_cycle, next_billing_date
    FROM " . CORE_SCHEMA . ".app_subscription
    WHERE app_company_id = '$companyId' AND subscription_status = 'active'
    ORDER BY created_at DESC
    LIMIT 1");

if (!$subResult || mysqli_num_rows($subResult) === 0) {
    jsonResponse(404, 'Tidak ada langganan aktif.');
}

$sub = mysqli_fetch_assoc($subResult);

if ($sub['service_id'] === $newPlanId && $sub['billing_cycle'] === $billingCycle) {
    jsonResponse(409, 'Anda sudah menggunakan plan ini.');
}

$subId = cleanInput($sub['subscription_id']);

mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_subscription
    SET service_id = '$newPlanId', billing_cycle = '$billingCycle'
    WHERE subscription_id = '$subId'");

if (mysqli_errno($conn)) {
    jsonResponse(500, 'Gagal memperbarui langganan: ' . mysqli_error($conn));
}

$effectiveDate  = $sub['next_billing_date'];
$effectiveLabel = date('j F Y', strtotime($effectiveDate));

jsonResponse(200, "Perubahan plan berhasil. Akan berlaku mulai {$effectiveLabel}.", [
    'subscription_id' => $sub['subscription_id'],
    'new_plan_id'     => $newPlan['service_id'],
    'new_plan_name'   => $newPlan['service_name'],
    'billing_cycle'   => $billingCycle,
    'effective_date'  => $effectiveDate,
    'new_price_idr'   => (int)$newPlan['service_price'],
]);
