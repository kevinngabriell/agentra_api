<?php
// POST /api/v1/subscription/checkout  { "service_id": "<plan_id>" }
// Creates a pending QRIS payment through SumoPod. The subscription is only activated by the
// signed webhook (subscription/webhook.php), never by this call.

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/sumopod.php';
require_once __DIR__ . '/../helpers/subscription.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method not allowed');
}

$authUser   = requireAuth();
$company_id = $authUser['company_id'] ?? null;
if (!$company_id) jsonResponse(400, 'company_id is required');

// Self-registered owners hold the plain 'user' role, so the role can't gate this; a sub-agent login
// (read-only portal) must never be able to buy or change the company's plan.
require_once __DIR__ . '/../helpers/access.php';
if (findSubAgentLogin($conn, $authUser) !== null) {
    throw ApiException::from('ERR_SUB_013');
}

$company_id = mysqli_real_escape_string($conn, $company_id);
$username   = mysqli_real_escape_string($conn, (string)($authUser['sub'] ?? ''));
$input      = input();
$service_id = mysqli_real_escape_string($conn, trim((string)($input['service_id'] ?? '')));
if ($service_id === '') throw ApiException::from('ERR_COMMON_001', ['field' => 'service_id']);

$svcRes  = mysqli_query($conn, "SELECT service_id, service_name, service_price, billing_cycle, pricing_model
    FROM " . CORE_SCHEMA . ".app_services
    WHERE service_id = '$service_id' AND app_id = '" . AGENTRA_APP_ID . "' AND is_active = 1 LIMIT 1");
$service = $svcRes ? mysqli_fetch_assoc($svcRes) : null;
if (!$service) throw ApiException::from('ERR_SUB_004');

$amount = (int)$service['service_price'];
if (($service['pricing_model'] ?? 'fixed') !== 'fixed' || !in_array($service['billing_cycle'], ['monthly', 'yearly'], true) || $amount <= 0) {
    throw ApiException::from('ERR_SUB_011');
}

mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'expired'
    WHERE company_id = '$company_id' AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()");

$now           = date('Y-m-d H:i:s');
$payment_id    = 'spy_' . bin2hex(random_bytes(8));
$order_id      = 'AGT-SUB-' . strtoupper(bin2hex(random_bytes(6)));
$billing_cycle = $service['billing_cycle'];

mysqli_begin_transaction($conn);
try {
    $dup = mysqli_query($conn, "SELECT id AS payment_id, order_id, payment_link_url, expires_at
        FROM " . APP_SCHEMA . ".subscription_payment WHERE company_id = '$company_id' AND status = 'pending' LIMIT 1 FOR UPDATE");
    $existing = $dup ? mysqli_fetch_assoc($dup) : null;
    if ($existing) {
        mysqli_rollback($conn);
        throw ApiException::from('ERR_SUB_009', $existing);
    }
    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".subscription_payment
        (id, company_id, service_id, order_id, amount, billing_cycle, status, created_by, created_at, updated_at)
        VALUES ('$payment_id', '$company_id', '$service_id', '$order_id', $amount, '$billing_cycle', 'pending', '$username', '$now', '$now')");
    mysqli_commit($conn);
} catch (mysqli_sql_exception $e) {
    mysqli_rollback($conn);
    // The unique index on pending_company_key rejects a concurrent second pending payment.
    if ($e->getCode() === 1062) throw ApiException::from('ERR_SUB_009');
    throw $e;
}

$gatewayPayload = [
    'order_id'                 => $order_id,
    'amount'                   => $amount,
    'currency'                 => 'IDR',
    'expires_in_hours'         => SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS,
    'payment_method_type_code' => 'QRIS',
];
if (SUMOPOD_SUCCESS_URL !== '') $gatewayPayload['success_return_url'] = SUMOPOD_SUCCESS_URL;
if (SUMOPOD_CANCEL_URL !== '')  $gatewayPayload['cancel_return_url']  = SUMOPOD_CANCEL_URL;

$gateway = createSumoPodPayment($gatewayPayload);
$gd      = $gateway['data'] ?? [];

if (!$gateway['success'] || empty($gd['payment_id']) || empty($gd['payment_link_url'])) {
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'failed', updated_at = '$now' WHERE id = '$payment_id'");
    throw ApiException::from('ERR_SUB_012');
}

$gwId      = mysqli_real_escape_string($conn, $gd['payment_id']);
$link      = mysqli_real_escape_string($conn, $gd['payment_link_url']);
$feeSql    = isset($gd['fee']) ? (int)$gd['fee'] : 'NULL';
$netSql    = isset($gd['net_amount']) ? (int)$gd['net_amount'] : 'NULL';
$expiresAt = !empty($gd['expires_at']) && strtotime($gd['expires_at']) !== false
    ? date('Y-m-d H:i:s', strtotime($gd['expires_at']))
    : date('Y-m-d H:i:s', time() + SUBSCRIPTION_PAYMENT_EXPIRES_IN_HOURS * 3600);

mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET
        gateway_payment_id = '$gwId', payment_link_url = '$link', fee = $feeSql, net_amount = $netSql,
        expires_at = '$expiresAt', updated_at = '$now'
    WHERE id = '$payment_id'");

jsonResponse(201, 'Payment created', [
    'payment_id'       => $payment_id,
    'order_id'         => $order_id,
    'service_id'       => $service['service_id'],
    'service_name'     => $service['service_name'],
    'amount'           => $amount,
    'status'           => 'pending',
    'payment_link_url' => $gd['payment_link_url'],
    'expires_at'       => $expiresAt,
]);
