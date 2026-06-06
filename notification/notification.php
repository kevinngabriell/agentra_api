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
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);

    $body     = curl_exec($ch);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return ['success' => false, 'httpCode' => 0, 'error' => $error, 'raw' => $body];
    }

    return [
        'success'  => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data'     => json_decode($body, true),
        'raw'      => $body,
    ];
}
