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

    $errorId   = 'ERR' . strtoupper(bin2hex(random_bytes(5)));
    $ip        = mysqli_real_escape_string($conn, $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? '');
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
                    '$fileEsc', $line, '$uidEsc', '$compEsc', '$reqEsc', '$ip', '$userAgent')");
    } catch (Exception $e) {
        // Silently fail — logging must not interrupt the request
    }
}
