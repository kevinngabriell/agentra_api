<?php
// GET /api/v1/subscription/payments/{payment_id} — one checkout's status (polled after the user pays).

require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method not allowed');
}

$authUser   = requireAuth();
$company_id = $authUser['company_id'] ?? null;
if (!$company_id) jsonResponse(400, 'company_id is required');
$company_id = mysqli_real_escape_string($conn, $company_id);
$payment_id = mysqli_real_escape_string($conn, (string)($parts[4] ?? ''));
if ($payment_id === '') throw ApiException::from('ERR_SUB_010');

mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = 'expired'
    WHERE company_id = '$company_id' AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()");

$res = mysqli_query($conn, "SELECT id AS payment_id, order_id, service_id, subscription_id, amount, billing_cycle,
        status, payment_link_url, expires_at, paid_at, activated_at, created_at
    FROM " . APP_SCHEMA . ".subscription_payment WHERE id = '$payment_id' AND company_id = '$company_id' LIMIT 1");
$payment = $res ? mysqli_fetch_assoc($res) : null;
if (!$payment) throw ApiException::from('ERR_SUB_010');

$payment['amount']       = (int)$payment['amount'];
$payment['is_activated'] = $payment['activated_at'] !== null;
if ($payment['status'] !== 'pending') $payment['payment_link_url'] = null;

jsonResponse(200, 'Payment found', $payment);
