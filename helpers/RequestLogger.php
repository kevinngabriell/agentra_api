<?php

final class RequestLogger
{
    private static string $requestId;
    private static string $clientRequestId;
    private static float $startTime;
    private static ?array $actor = null;
    private static ?int $httpCode = 200;
    private static ?string $errorCode = null;
    private static ?string $errorMessage = null;
    private static ?string $endpoint = null;
    private static ?array $exceptionData = null;
    private static bool $flushed = false;

    public static function init(string $endpointPattern): void
    {
        self::$startTime = microtime(true);
        self::$requestId = self::uuidV7();
        self::$clientRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        self::$endpoint = $endpointPattern;

        register_shutdown_function(fn() => self::flush());
    }

    public static function requestId(): string
    {
        return self::$requestId ?? '';
    }

    public static function setActor(array $payload): void
    {
        self::$actor = $payload;
    }

    public static function setOutcome(int $httpCode, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        self::$httpCode = $httpCode;
        if ($errorCode !== null) {
            self::$errorCode = $errorCode;
        }
        if ($errorMessage !== null) {
            self::$errorMessage = $errorMessage;
        }
    }

    public static function setException(\Throwable $e): void
    {
        self::$exceptionData = [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ];
    }

    private static function uuidV7(): string
    {
        $now = microtime(true);
        $ms = (int) ($now * 1000);
        $tsHigh = $ms >> 16;
        $tsLow = $ms & 0xFFFF;

        $rand = random_bytes(10);
        $rand[0] = chr((ord($rand[0]) & 0x0F) | 0x70);
        $rand[2] = chr((ord($rand[2]) & 0x3F) | 0x80);

        $hex = sprintf('%08x%04x', $tsHigh, $tsLow)
             . bin2hex(substr($rand, 0, 2))
             . bin2hex(substr($rand, 2, 2))
             . bin2hex(substr($rand, 4, 6));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public static function flush(): void
    {
        if (self::$flushed) {
            return;
        }
        self::$flushed = true;

        $durationMs = (int) round((microtime(true) - self::$startTime) * 1000);
        $outcome = self::$httpCode < 400 ? 'success' : 'error';
        $level = match (true) {
            self::$httpCode >= 500 => 'error',
            self::$httpCode >= 400 => 'warning',
            default => 'info',
        };

        $identityId = self::$actor['sub'] ?? null;
        $companyId  = self::$actor['company_id'] ?? null;
        $username   = self::$actor['username'] ?? null;

        $ip = self::clientIp();
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $memoryPeak = (int) round(memory_get_peak_usage(true) / 1024);

        global $conn;
        $schema = defined('MOVIRA_CORE_SCHEMA') ? MOVIRA_CORE_SCHEMA : 'movira_core_dev';

        try {
            if (!$conn instanceof \mysqli) {
                throw new \RuntimeException('No DB connection');
            }

            $stmt = $conn->prepare(
                "INSERT INTO `{$schema}`.log_request
                    (request_id, client_request_id, timestamp, api_version, endpoint, http_method,
                     status_code, outcome, level, error_code, error_message, retryable,
                     client_type, client_id, identity_id, username, customer_id, app_id,
                     ip_address, user_agent, duration_ms, db_connected, memory_peak_kb,
                     exception_class, exception_message, exception_file, exception_line, stack_trace)
                 VALUES
                    (?, ?, NOW(3), 'v1', ?, ?, ?, ?, ?, ?, ?, ?, 'portal_web', NULL, ?, ?, ?, NULL, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)"
            );

            $requestId       = self::$requestId ?? '';
            $clientRequestId = self::$clientRequestId ?: null;
            $endpoint        = self::$endpoint ?? '/';
            $errorCode       = self::$errorCode;
            $errorMessage    = self::$errorMessage ? mb_substr(self::$errorMessage, 0, 500) : null;
        $retryable = (self::$httpCode === 429 || self::$httpCode >= 500) ? 1 : 0;
            $excClass        = self::$exceptionData['class'] ?? null;
            $excMessage      = mb_substr(self::$exceptionData['message'] ?? '', 0, 65535);
            $excFile         = self::$exceptionData['file'] ?? null;
            $excLine         = self::$exceptionData['line'] ?? null;
            $stackTrace      = mb_substr(self::$exceptionData['trace'] ?? '', 0, 65535);

            $types = 'ssssissssisssssiisssis';

            $stmt->bind_param(
                $types,
                $requestId,       // 1  s  request_id
                $clientRequestId, // 2  s  client_request_id
                $endpoint,        // 3  s  endpoint
                $method,          // 4  s  http_method
                self::$httpCode,  // 5  i  status_code
                $outcome,         // 6  s  outcome
                $level,           // 7  s  level
                $errorCode,       // 8  s  error_code
                $errorMessage,    // 9  s  error_message
                $retryable,       // 10 i  retryable
                $identityId,      // 11 s  identity_id
                $username,        // 12 s  username
                $companyId,       // 13 s  customer_id (agentra maps company_id → customer_id)
                $ip,              // 14 s  ip_address
                $ua,              // 15 s  user_agent
                $durationMs,      // 16 i  duration_ms
                $memoryPeak,      // 17 i  memory_peak_kb
                $excClass,        // 18 s  exception_class
                $excMessage,      // 19 s  exception_message
                $excFile,         // 20 s  exception_file
                $excLine,         // 21 i  exception_line
                $stackTrace,      // 22 s  stack_trace
            );
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            $row = [
                'request_id'  => self::$requestId ?? '',
                'endpoint'    => self::$endpoint ?? '/',
                'status_code' => self::$httpCode,
                'error_code'  => self::$errorCode,
                'duration_ms' => $durationMs,
                'ip'          => $ip,
            ];
            $line = json_encode($row, JSON_UNESCAPED_UNICODE);
            $date = date('Y-m-d');
            $dir = __DIR__ . '/../storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents("$dir/request-$date.jsonl", $line . "\n", FILE_APPEND);
        }
    }

    private static function clientIp(): string
    {
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
}
