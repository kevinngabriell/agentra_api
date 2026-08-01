<?php
// [NEW v1.1] One-off CLI helper to mint a long-lived service JWT for cron callers
// (e.g. the daily renewal-reminder scheduler hitting POST /api/v1/services/renewal-reminder).
//
// Usage:
//   php mint-cron-token.php [days]     # days defaults to 3650 (~10 years)
//
// Copy the printed token into the cron job's Authorization header:
//   Authorization: Bearer <token>
//
// This reuses the existing JWT signing/verification path (helpers/jwt.php) —
// no new secret or auth mechanism. requireAuth() accepts it like any other
// access token; endpoints that need to recognize a service caller check for
// `service === 'cron'` in the decoded payload (no company_id is embedded,
// since cron jobs act across all companies).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers/jwt.php';

$days = isset($argv[1]) ? max(1, (int)$argv[1]) : 3650;

$token = JWT::encode(['service' => 'cron', 'sub' => 'cron-service'], $days * 86400);

fwrite(STDOUT, $token . PHP_EOL);
