<?php

require_once __DIR__ . '/../config.php';

function sendWhatsAppText(string $chatId, string $text, string $session = WAHA_SESSION): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'curl not available'];
    }

    $url     = rtrim(WAHA_BASE_URL, '/') . '/api/sendText';
    $payload = ['chatId' => $chatId, 'text' => $text, 'session' => $session];

    $headers = ['Content-Type: application/json'];
    if (!empty(WAHA_API_KEY)) {
        $headers[] = 'X-Api-Key: ' . WAHA_API_KEY;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 10,
        // Explicit TLS + protocol pinning. Defaults are currently true/2, but
        // pinning them removes the entire class of regression if a future ops
        // change disables verification. Every WhatsApp dispatch carries
        // customer phone numbers and message text — MITM interception here
        // would be a data breach.
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
    ]);

    $body     = curl_exec($ch);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['success' => false, 'httpCode' => 0, 'error' => $error];
    }

    return [
        'success'  => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data'     => json_decode($body, true),
        // 'raw' intentionally omitted from the default return — callers persist
        // the result array in whatsapp_digest_logs.error_message, and WAHA's
        // raw 500 body could echo the X-Api-Key or stack traces. Callers who
        // need raw debugging output can request it via a separate flag.
    ];
}
