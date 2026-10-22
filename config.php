<?php
function loadEnv(string $path): void {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        // Support `export KEY=value` shellspec-style lines.
        if (str_starts_with(strtolower($line), 'export ')) {
            $line = trim(substr($line, 7));
        }
        // Skip lines without `=` (avoids undefined-index notice + null value).
        if (!str_contains($line, '=')) continue;

        [$key, $value] = array_map('trim', explode('=', $line, 2));

        // Strip inline ` # comment` only when the `#` is preceded by whitespace
        // and not inside a quoted value.
        if ($value !== '' && !in_array($value[0], ['"', "'"], true)) {
            // Remove ` #...` trailing comment.
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
        }

        // Strip a single matched pair of surrounding quotes ("..." or '...').
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        } elseif (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/.env');

define('APP_ENV',           $_ENV['APP_ENV']           ?? 'development');
define('CORE_SCHEMA',       $_ENV['CORE_SCHEMA']       ?? 'movira_core_dev');
define('APP_SCHEMA',        $_ENV['APP_SCHEMA']        ?? 'agentra_dev');
define('MOVIRA_CORE_SCHEMA', $_ENV['MOVIRA_CORE_SCHEMA'] ?? 'movira_core_dev');

// Agentra's app_id in the movira_core app table — shared by login, register, and SSO.
define('AGENTRA_APP_ID', '7c2e6a2f-b254-4bbb-87f2-c6ece0f88db2');

// app_role_id (FK to app_role) given to every self-registered account. Must exist in app_role.
define('DEFAULT_REGISTER_ROLE', $_ENV['DEFAULT_REGISTER_ROLE'] ?? '');

// SumoPod payment gateway (subscription checkout). Live and sandbox use separate key/secret pairs.
define('SUMOPOD_BASE_URL',       $_ENV['SUMOPOD_BASE_URL']       ?? 'https://api-pay.sumopod.com');
define('SUMOPOD_API_KEY',        $_ENV['SUMOPOD_API_KEY']        ?? '');
define('SUMOPOD_WEBHOOK_SECRET', $_ENV['SUMOPOD_WEBHOOK_SECRET'] ?? '');
define('SUMOPOD_SUCCESS_URL',    $_ENV['SUMOPOD_SUCCESS_URL']    ?? '');
define('SUMOPOD_CANCEL_URL',     $_ENV['SUMOPOD_CANCEL_URL']     ?? '');

define('WAHA_BASE_URL', $_ENV['WAHA_BASE_URL'] ?? '');
define('WAHA_SESSION',  $_ENV['WAHA_SESSION']  ?? '');
define('WAHA_API_KEY',  $_ENV['WAHA_API_KEY']  ?? '');

// Fail fast if JWT_SECRET is missing or too short — a silently-empty secret
// would let `hash_hmac('sha256', $data, '')` produce valid HMACs with a
// publicly known key, allowing anyone to forge tokens.
$__jwtSecret = $_ENV['JWT_SECRET'] ?? '';
if (strlen($__jwtSecret) < 32) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status_code'    => 500,
        'status_message' => 'Server misconfiguration: JWT_SECRET must be set to a value of at least 32 characters.',
        'data'           => [],
    ]);
    exit;
}
define('JWT_SECRET', $__jwtSecret);

// Validate APP_ENV against the allowed set so a stale `.env` can't silently
// flip production into development (which would enable wildcard CORS).
if (!in_array(APP_ENV, ['development', 'staging', 'production'], true)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status_code'    => 500,
        'status_message' => 'Server misconfiguration: APP_ENV must be one of development, staging, production.',
        'data'           => [],
    ]);
    exit;
}
