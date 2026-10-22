<?php
// POST /api/v1/subscription/webhook — SumoPod payment events.
// Public on purpose: SumoPod can't send a JWT. Authenticity comes from the Svix signature instead.

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/sumopod.php';
require_once __DIR__ . '/../helpers/subscription.php';

function handleSumoPodWebhook($conn, string $event_id, array $event): void {
    $event_type = $event['event_type'] ?? '';
    $data       = $event['data'] ?? [];
    $order_id   = isset($data['order_id']) ? mysqli_real_escape_string($conn, (string)$data['order_id']) : '';
    $now        = date('Y-m-d H:i:s');

    if ($event_type === 'payment.test') {
        jsonResponse(200, 'Test event received');
    }
    if (!in_array($event_type, ['payment.completed', 'payment.failed', 'payment.expired'], true) || $order_id === '') {
        jsonResponse(200, 'Event ignored');
    }

    mysqli_begin_transaction($conn);
    try {
        $eid = mysqli_real_escape_string($conn, $event_id);
        $etp = mysqli_real_escape_string($conn, $event_type);

        // Svix retries reuse svix-id, so a duplicate PK means this delivery was already handled.
        mysqli_query($conn, "INSERT IGNORE INTO " . APP_SCHEMA . ".payment_webhook_event
            (id, event_type, order_id, received_at) VALUES ('$eid', '$etp', '$order_id', '$now')");
        if (mysqli_affected_rows($conn) === 0) {
            mysqli_rollback($conn);
            jsonResponse(200, 'Event already processed');
        }

        $res     = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".subscription_payment WHERE order_id = '$order_id' LIMIT 1 FOR UPDATE");
        $payment = $res ? mysqli_fetch_assoc($res) : null;
        if (!$payment) {
            mysqli_commit($conn);
            jsonResponse(200, 'Unknown order, ignored');
        }
        if ($payment['status'] !== 'pending') {
            mysqli_commit($conn);
            jsonResponse(200, 'Payment already ' . $payment['status']);
        }

        $payment_id = $payment['id'];

        if ($event_type !== 'payment.completed') {
            $new_status = $event_type === 'payment.failed' ? 'failed' : 'expired';
            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET status = '$new_status', updated_at = '$now' WHERE id = '$payment_id'");
            mysqli_commit($conn);
            jsonResponse(200, 'Payment marked ' . $new_status);
        }

        // Never activate on an amount we didn't ask for — an underpaid link must not buy a full period.
        if ((int)($data['amount'] ?? -1) !== (int)$payment['amount']) {
            error_log("SumoPod webhook amount mismatch for $order_id: expected {$payment['amount']}, got " . ($data['amount'] ?? 'null'));
            mysqli_rollback($conn);
            jsonResponse(409, 'Amount mismatch');
        }

        $paid_ts = !empty($data['paid_at']) ? strtotime($data['paid_at']) : false;
        $paid_at = $paid_ts !== false ? date('Y-m-d H:i:s', $paid_ts) : $now;
        $feeSql  = isset($data['fee']) ? (int)$data['fee'] : 'NULL';
        $netSql  = isset($data['net_amount']) ? (int)$data['net_amount'] : 'NULL';

        $subscription_id = activateSubscriptionFromPayment($conn, $payment, $now);

        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".subscription_payment SET
                status = 'completed', subscription_id = '$subscription_id', fee = $feeSql, net_amount = $netSql,
                paid_at = '$paid_at', activated_at = '$now', updated_at = '$now'
            WHERE id = '$payment_id'");

        mysqli_commit($conn);
        jsonResponse(200, 'Payment completed and subscription activated');
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        error_log('SumoPod webhook processing failed: ' . $e->getMessage());
        jsonResponse(500, 'Webhook processing failed');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method not allowed');
}
if (SUMOPOD_WEBHOOK_SECRET === '') {
    error_log('SumoPod webhook received but SUMOPOD_WEBHOOK_SECRET is not configured');
    jsonResponse(500, 'Webhook is not configured');
}

$raw_body = file_get_contents('php://input');
$svix_id  = $_SERVER['HTTP_SVIX_ID'] ?? '';

if (!verifySumoPodWebhookSignature(
    SUMOPOD_WEBHOOK_SECRET, $svix_id,
    $_SERVER['HTTP_SVIX_TIMESTAMP'] ?? '', $_SERVER['HTTP_SVIX_SIGNATURE'] ?? '', $raw_body
)) {
    jsonResponse(401, 'Invalid signature');
}

$event = json_decode($raw_body, true);
if (!is_array($event)) jsonResponse(400, 'Invalid JSON body');

handleSumoPodWebhook($conn, $svix_id, $event);
