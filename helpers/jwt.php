<?php
require_once __DIR__ . '/../config/config.php';

class JWT {
  const ACCESS_TTL  = 900;      // 15 minutes
  const REFRESH_TTL = 604800;   // 7 days

  public static function encode(array $payload, int $expireSeconds = self::ACCESS_TTL): string {
    $header = self::base64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $payload['iat'] = time();
    $payload['exp'] = time() + $expireSeconds;

    $body = self::base64url(json_encode($payload));
    $sig  = self::base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));

    return "$header.$body.$sig";
  }

  public static function encodeRefresh(array $payload): string {
    return self::encode(array_merge($payload, ['type' => 'refresh']), self::REFRESH_TTL);
  }

  // Verifies and returns the payload; throws on failure
  public static function decode(string $token, bool $allowRefresh = false): array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
      throw new Exception('Invalid token format', 401);
    }

    [$header, $body, $sig] = $parts;

    $expected = self::base64url(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) {
      throw new Exception('Invalid token signature', 401);
    }

    $payload = json_decode(self::base64urlDecode($body), true);
    if (!$payload || !isset($payload['exp'])) {
      throw new Exception('Malformed token payload', 401);
    }

    if (time() > $payload['exp']) {
      throw new Exception('Token expired', 401);
    }

    // Prevent refresh tokens from being used as access tokens and vice versa
    $isRefresh = ($payload['type'] ?? '') === 'refresh';
    if ($allowRefresh && !$isRefresh) {
      throw new Exception('Expected a refresh token', 401);
    }
    if (!$allowRefresh && $isRefresh) {
      throw new Exception('Cannot use refresh token as access token', 401);
    }

    return $payload;
  }

  public static function fromRequest(): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) {
      throw new Exception('Authorization header missing or malformed', 401);
    }
    return self::decode(substr($auth, 7));
  }

  private static function base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }

  private static function base64urlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
  }
}
