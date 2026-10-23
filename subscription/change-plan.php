<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    jsonResponse(405, 'Method not allowed');
}

$authUser = requireAuth();
$userId   = mysqli_real_escape_string($conn, $authUser['sub'] ?? '');

// Authorization: changing the company's subscription plan affects billing —
// restrict it to admins/owners. Without this, any agent in the company could
// downgrade the company's plan and lose features at the next billing cycle.
$role = $authUser['role'] ?? '';
if (!in_array($role, ['admin', 'owner', 'manager'], true)) {
    jsonResponse(403, 'Only company administrators may change the subscription plan.');
}

$body         = input();
$newPlanId    = mysqli_real_escape_string($conn, trim($body['new_plan_id']   ?? ''));
$billingCycle = mysqli_real_escape_string($conn, trim($body['billing_cycle'] ?? ''));

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
$companyId = mysqli_real_escape_string($conn, $user['company_id']);

$planResult = mysqli_query($conn, "SELECT service_id, service_name, service_price
    FROM " . CORE_SCHEMA . ".app_services
    WHERE service_id = '$newPlanId' AND is_active = 1
    LIMIT 1");

if (!$planResult || mysqli_num_rows($planResult) === 0) {
    jsonResponse(400, 'Plan yang dipilih tidak valid atau tidak tersedia.');
}

$newPlan = mysqli_fetch_assoc($planResult);

// Paid plans are only activated by a completed payment (subscription/checkout + webhook);
// this endpoint can only move a company down to a free plan.
if ((int)$newPlan['service_price'] > 0) {
    throw ApiException::from('ERR_SUB_008');
}

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

$subId = mysqli_real_escape_string($conn, $sub['subscription_id']);

// Check the UPDATE return value directly — mysqli_errno reflects the LAST
// query on the connection, which may be from a prior unrelated query.
$ok = mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_subscription
    SET service_id = '$newPlanId', billing_cycle = '$billingCycle', payment_status = 'paid'
    WHERE subscription_id = '$subId'");

if (!$ok) {
    error_log('change-plan UPDATE failed: ' . mysqli_error($conn));
    jsonResponse(500, 'Gagal memperbarui langganan');
}

// next_billing_date may be null — guard so the response doesn't tell the user
// the plan change is "effective 1 January 1970".
$effectiveDate = $sub['next_billing_date'];
if (!$effectiveDate) {
    jsonResponse(500, 'Subscription has no next billing date');
}
$effectiveLabel = date('j F Y', strtotime($effectiveDate));

jsonResponse(200, "Perubahan plan berhasil. Akan berlaku mulai {$effectiveLabel}.", [
    'subscription_id' => $sub['subscription_id'],
    'new_plan_id'     => $newPlan['service_id'],
    'new_plan_name'   => $newPlan['service_name'],
    'billing_cycle'   => $billingCycle,
    'effective_date'  => $effectiveDate,
    'new_price_idr'   => (int)$newPlan['service_price'],
]);
