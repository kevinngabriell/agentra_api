<?php

final class Response
{
    public static function success(mixed $data = [], ?array $meta = null, int $httpCode = 200, string $message = 'OK'): never
    {
        RequestLogger::setOutcome($httpCode);
        self::emit([
            'success' => true,
            'data'    => $data,
            'meta'    => $meta,
            'message' => $message,
            'error'   => null,
        ], $httpCode);
    }

    public static function error(string $code, string $message, int $httpStatus, array $details = []): never
    {
        $requestId = RequestLogger::requestId();

        RequestLogger::setOutcome($httpStatus, $code, $message);

        self::emit([
            'success' => false,
            'data'    => null,
            'meta'    => null,
            'message' => null,
            'error'   => [
                'code'        => $code,
                'message'     => $message,
                'http_status' => $httpStatus,
                'details'     => $details,
                'request_id'  => $requestId,
            ],
        ], $httpStatus);
    }

    public static function notFound(string $message = 'Data tidak ditemukan'): never
    {
        self::error('ERR_COMMON_002', $message, 404);
    }

    public static function methodNotAllowed(): never
    {
        self::error('ERR_COMMON_006', 'Metode HTTP tidak diizinkan untuk endpoint ini', 405);
    }

    public static function forbidden(string $message = 'Anda tidak memiliki izin untuk mengakses endpoint ini'): never
    {
        self::error('ERR_COMMON_003', $message, 403);
    }

    public static function badRequest(string $message, array $details = []): never
    {
        self::error('ERR_COMMON_007', $message, 400, $details);
    }

    public static function unauthorized(string $message = 'Token autentikasi tidak valid atau sudah kedaluwarsa'): never
    {
        self::error('ERR_AUTH_002', $message, 401);
    }

    public static function conflict(string $message, array $details = []): never
    {
        self::error('ERR_COMMON_008', $message, 409, $details);
    }

    public static function tooManyRequests(int $retryAfter): never
    {
        self::error(
            'ERR_COMMON_004',
            'Terlalu banyak permintaan. Silakan coba lagi dalam beberapa saat.',
            429,
            ['retry_after_seconds' => $retryAfter],
        );
    }

    public static function validationError(array $fieldErrors): never
    {
        self::error(
            'ERR_COMMON_001',
            'Validasi gagal',
            422,
            ['fields' => $fieldErrors],
        );
    }

    public static function internalError(): never
    {
        self::error(
            'ERR_COMMON_005',
            'Terjadi kesalahan pada server. Tim kami telah diberi notifikasi.',
            500,
        );
    }

    public static function fromHttpStatus(int $httpStatus, string $message): never
    {
        $code = ErrorCode::codeForHttpStatus($httpStatus);
        self::error($code, $message, $httpStatus);
    }

    private static function emit(array $body, int $httpCode): never
    {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            if (RequestLogger::requestId() !== '') {
                header('X-Request-ID: ' . RequestLogger::requestId());
            }
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
