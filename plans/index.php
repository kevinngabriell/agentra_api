<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method not allowed');
}

$appId = cleanInput($_GET['app_id'] ?? '');

if (!$appId) {
    jsonResponse(400, 'app_id diperlukan');
}

$result = mysqli_query($conn, "SELECT service_id, service_name, service_description, service_price, billing_cycle, is_active
    FROM " . CORE_SCHEMA . ".app_services
    WHERE app_id = '$appId' AND is_active = 1
    ORDER BY service_price ASC");

if (!$result) {
    jsonResponse(500, 'Database error: ' . mysqli_error($conn));
}

$data = [];
while ($svc = mysqli_fetch_assoc($result)) {
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

    $data[] = [
        'plan_id'       => $svc['service_id'],
        'name'          => $svc['service_name'],
        'tagline'       => $svc['service_description'],
        'price_idr'     => (int)$svc['service_price'],
        'billing_cycle' => $svc['billing_cycle'],
        'is_available'  => (bool)$svc['is_active'],
        'features'      => $features,
    ];
}

jsonResponse(200, 'OK', $data);
