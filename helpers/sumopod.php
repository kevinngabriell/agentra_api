<?php
// SumoPod payment gateway client (QRIS payment links) and Svix webhook signature check.

function createSumoPodPayment(array $payload): array {
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'curl_init MISSING'];
    }
    if (SUMOPOD_API_KEY === '') {
        return ['success' => false, 'error' => 'SUMOPOD_API_KEY is not configured'];
    }

    $ch = curl_init(rtrim(SUMOPOD_BASE_URL, '/') . '/api/v1/payments');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Api-Key: ' . SUMOPOD_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response_body = curl_exec($ch);
    $errno         = curl_errno($ch);
    $error         = curl_error($ch);
    $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        error_log('SumoPod CURL error: ' . $error);
        return ['success' => false, 'httpCode' => $http_code, 'error' => $error];
    }

    $decoded = json_decode($response_body, true);

    if ($http_code < 200 || $http_code >= 300 || !is_array($decoded)) {
        error_log('SumoPod create payment failed (' . $http_code . '): ' . $response_body);
        return ['success' => false, 'httpCode' => $http_code, 'error' => 'Gateway rejected the request'];
    }

    return ['success' => true, 'httpCode' => $http_code, 'data' => $decoded['data'] ?? $decoded];
}

// SumoPod signs webhooks with Svix. $svix_signature can hold several space-separated
// "v1,<sig>" values for ~24h after a secret rotation. The timestamp window is replay protection.
function verifySumoPodWebhookSignature(string $secret, string $svix_id, string $svix_timestamp, string $svix_signature, string $raw_body, int $tolerance_seconds = 300): bool {
    if ($secret === '' || $svix_id === '' || $svix_timestamp === '' || $svix_signature === '') {
        return false;
    }
    if (!ctype_digit($svix_timestamp) || abs(time() - (int)$svix_timestamp) > $tolerance_seconds) {
        return false;
    }

    $secret_bytes = base64_decode(str_replace('whsec_', '', $secret), true);
    if ($secret_bytes === false) {
        return false;
    }

    $expected_signature = base64_encode(hash_hmac('sha256', "{$svix_id}.{$svix_timestamp}.{$raw_body}", $secret_bytes, true));

    foreach (explode(' ', $svix_signature) as $part) {
        $pieces = explode(',', $part, 2);
        if (count($pieces) === 2 && hash_equals($expected_signature, $pieces[1])) {
            return true;
        }
    }

    return false;
}
