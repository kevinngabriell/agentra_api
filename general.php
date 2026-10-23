<?php
require_once __DIR__ . '/config.php';

require_once __DIR__ . '/helpers/ErrorCode.php';
require_once __DIR__ . '/helpers/ApiException.php';
require_once __DIR__ . '/helpers/RequestLogger.php';
require_once __DIR__ . '/helpers/Response.php';

// ── CORS ────────────────────────────────────────────────────────────────────
if (APP_ENV === 'development') {
    $allowedDevOrigins = ['http://localhost:3000', 'http://localhost:5173', 'http://127.0.0.1:3000', 'http://127.0.0.1:5173'];
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($requestOrigin !== '' && in_array($requestOrigin, $allowedDevOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-ID');
        header('Access-Control-Expose-Headers: X-Request-ID');
        header('Access-Control-Max-Age: 86400');
    }

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/connection/db.php';
require_once __DIR__ . '/helpers/jwt.php';

// ── Database connection (global) ──────────────────────────────────────────────
$conn = getConn();

// ── Request logging init ─────────────────────────────────────────────────────
// Derive an endpoint pattern from the URI for log_request (e.g. api/v1/customers/{id}).
$_endpointPattern = '/';
if (isset($_SERVER['REQUEST_URI'])) {
    $_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/', '/');
    $_segs = explode('/', $_uri);
    // Replace UUID-like or long hex segments with {id} so logs group by route, not by row.
    foreach ($_segs as &$_seg) {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $_seg)
            || preg_match('/^[a-zA-Z0-9_]{10,}$/', $_seg)) {
            $_seg = '{id}';
        }
    }
    unset($_seg);
    $_endpointPattern = implode('/', $_segs) ?: '/';
}
RequestLogger::init($_endpointPattern);

// ── Global exception & error handlers ────────────────────────────────────────
set_exception_handler(function (\Throwable $e): void {
    if ($e instanceof ApiException) {
        Response::error($e->errCode, $e->getMessage(), $e->httpStatus, $e->details);
    }

    RequestLogger::setException($e);
    error_log('Unhandled exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Response::internalError();
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// ── Response ──────────────────────────────────────────────────────────────────
// Backward-compatible wrapper: maps the old (code, message, data) signature
// to the new standard envelope {success, data, meta, message, error}.
// For 2xx → success response; for 4xx/5xx → error envelope with request_id + error code.

function jsonResponse(int $code, string $message, mixed $data = []): void {
    if ($code >= 200 && $code < 300) {
        Response::success($data, null, $code, $message);
    }

    // For 4xx/5xx: if $data carries an 'error' detail (e.g. mysqli_error),
    // log it to log_request.exception_message so it's traceable by request_id,
    // but never send the raw detail to the client — the client only sees
    // the clean message + error code + request_id.
    if (is_array($data) && isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
        RequestLogger::setException(new \RuntimeException($data['error']));
    }

    Response::fromHttpStatus($code, $message);
}

// ── Input ─────────────────────────────────────────────────────────────────────

function cleanInput(string $value): string {
    global $conn;
    return mysqli_real_escape_string($conn, trim($value));
}

function input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function requireFields(array $body, array $fields): void {
    $errors = [];
    foreach ($fields as $field) {
        if (!isset($body[$field]) || (is_string($body[$field]) && trim($body[$field]) === '') || $body[$field] === null) {
            $errors[$field] = "$field wajib diisi";
        }
    }
    if ($errors) {
        Response::validationError($errors);
    }
}

// ── Auth ──────────────────────────────────────────────────────────────────────

function requireAuth(): array {
    try {
        $payload = JWT::fromRequest();
        RequestLogger::setActor($payload);
        return $payload;
    } catch (Exception $e) {
        Response::unauthorized($e->getMessage());
    }
}

// ── Utilities ─────────────────────────────────────────────────────────────────

function generateUUID(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

function getUserIP(): string {
    $trustedProxies = ['127.0.0.1', '::1'];
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if (in_array($remoteAddr, $trustedProxies, true)) {
        $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($raw !== '') {
            $parts = array_map('trim', explode(',', $raw));
            foreach ($parts as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false ? $remoteAddr : '';
}

function nowJakarta(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
}
