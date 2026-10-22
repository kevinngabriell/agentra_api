<?php
require_once __DIR__ . '/connection/db.php';

function logApiError(
    int    $httpStatus,
    string $endpoint,
    string $errorMessage,
    string $errorLevel     = 'ERROR',
    string $method         = '',
    string $file           = '',
    int    $line           = 0,
    string $userIdentifier = '',
    string $companyId      = '',
    string $requestId      = ''
): void {
    global $conn;

    $errorId = 'ERR' . strtoupper(bin2hex(random_bytes(5)));

    // Resolve IP safely: X-Forwarded-For is client-controlled and trivially
    // spoofable. Only trust it when the request came from a known proxy, take
    // the rightmost untrusted entry, and validate with filter_var. Without this,
    // an attacker can poison the audit log (frame another IP) or even land a
    // stored XSS payload in any admin view that renders ip_address unescaped.
    $trustedProxies = ['127.0.0.1', '::1'];
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = '';
    if (in_array($remoteAddr, $trustedProxies, true)) {
        $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($raw !== '') {
            $parts = array_map('trim', explode(',', $raw));
            foreach (array_reverse($parts) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    $ip = $candidate;
                    break;
                }
            }
        }
    }
    if ($ip === '') {
        $ip = filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false ? $remoteAddr : '';
    }

    // The logger is the most likely path to run when something is already going
    // wrong — guard against $conn being null or closed so logging never throws
    // a TypeError that masks the original error.
    if (!$conn instanceof mysqli) {
        error_log("logApiError: no DB connection — error_id=$errorId endpoint=$endpoint msg=$errorMessage");
        return;
    }

    $ipEsc     = mysqli_real_escape_string($conn, $ip);
    $userAgent = mysqli_real_escape_string($conn, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
    $method    = mysqli_real_escape_string($conn, $method ?: ($_SERVER['REQUEST_METHOD'] ?? ''));
    $endpoint  = mysqli_real_escape_string($conn, $endpoint);
    $errorMsg  = mysqli_real_escape_string($conn, $errorMessage);
    $errLevel  = mysqli_real_escape_string($conn, $errorLevel);
    $fileEsc   = mysqli_real_escape_string($conn, $file);
    $uidEsc    = mysqli_real_escape_string($conn, $userIdentifier);
    $compEsc   = mysqli_real_escape_string($conn, $companyId);
    $reqEsc    = mysqli_real_escape_string($conn, $requestId);

    try {
        mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".api_error_log
            (error_id, http_status, endpoint, method, error_message, error_level, file, line,
             user_identifier, company_id, request_id, ip_address, user_agent)
            VALUES ('$errorId', $httpStatus, '$endpoint', '$method', '$errorMsg', '$errLevel',
                    '$fileEsc', $line, '$uidEsc', '$compEsc', '$reqEsc', '$ipEsc', '$userAgent')");
    } catch (\Throwable $e) {
        // Widen the catch to Throwable so TypeError / Error don't propagate.
        // Logging must never interrupt the request.
        error_log('logApiError insert failed: ' . $e->getMessage());
    }
}
