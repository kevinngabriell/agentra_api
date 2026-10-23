<?php
// CLI-only helper to mint a long-lived service JWT for cron callers
// (e.g. the daily renewal-reminder scheduler hitting POST /api/v1/services/renewal-reminder).
//
// Usage:
//   php mint-cron-token.php [days]     # days defaults to 3650 (~10 years)
//
// Copy the printed token into the cron job's Authorization header:
//   Authorization: Bearer <token>
//
// SECURITY: This script MUST only run from the CLI. Web exposure would let
// anyone mint a ~10-year service token. The guard below enforces this.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'status_code'    => 403,
        'status_message' => 'Forbidden: this script can only be run from the CLI.',
        'data'           => [],
    ]);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/jwt.php';

$days = isset($argv[1]) ? max(1, (int)$argv[1]) : 3650;

$token = JWT::encode(['service' => 'cron', 'sub' => 'cron-service'], $days * 86400);

fwrite(STDOUT, $token . PHP_EOL);
