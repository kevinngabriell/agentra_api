<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method not allowed');
}

$planId = cleanInput($action ?? '');

if (!$planId) {
    jsonResponse(400, 'Plan ID diperlukan');
}

$result = mysqli_query($conn, "SELECT service_id, service_name, service_description, service_price, billing_cycle, is_active
    FROM " . CORE_SCHEMA . ".app_services
    WHERE service_id = '$planId'
    LIMIT 1");

if (!$result || mysqli_num_rows($result) === 0) {
    jsonResponse(404, 'Plan tidak ditemukan');
}

$svc      = mysqli_fetch_assoc($result);
$sid      = cleanInput($svc['service_id']);
$features = [];

$fResult = mysqli_query($conn, "SELECT feature_text, sort_order
    FROM " . CORE_SCHEMA . ".app_service_features
    WHERE service_id = '$sid'
    ORDER BY sort_order ASC");

if ($fResult) {
    while ($f = mysqli_fetch_assoc($fResult)) {
        $features[] = [
            'label'      => $f['feature_text'],
            'sort_order' => (int)$f['sort_order'],
        ];
    }
}

jsonResponse(200, 'OK', [
    'plan_id'       => $svc['service_id'],
    'name'          => $svc['service_name'],
    'tagline'       => $svc['service_description'],
    'price_idr'     => (int)$svc['service_price'],
    'billing_cycle' => $svc['billing_cycle'],
    'is_available'  => (bool)$svc['is_active'],
    'features'      => $features,
]);
