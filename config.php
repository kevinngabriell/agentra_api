<?php
function loadEnv(string $path): void {
    if (!file_exists($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, '"\'');
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

loadEnv(__DIR__ . '/.env');

define('APP_ENV',     $_ENV['APP_ENV']     ?? 'development');
define('JWT_SECRET',  $_ENV['JWT_SECRET']  ?? '');
define('CORE_SCHEMA', $_ENV['CORE_SCHEMA'] ?? 'movira_core_dev');
define('APP_SCHEMA',  $_ENV['APP_SCHEMA']  ?? 'agentra_dev');

define('WAHA_BASE_URL', $_ENV['WAHA_BASE_URL'] ?? '');
define('WAHA_SESSION',  $_ENV['WAHA_SESSION']  ?? '');
define('WAHA_API_KEY',  $_ENV['WAHA_API_KEY']  ?? '');
