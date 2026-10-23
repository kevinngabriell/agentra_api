<?php

/**
 * Movira SSO — consumer library for sibling apps (bizion, bizgen, raki, agentra, …).
 *
 * CANONICAL COPY: movira_api/docs/sibling_sso/MoviraSso.php. Each sibling carries a byte-identical
 * copy at <app>/sso/MoviraSso.php. Change it in movira_api first, then re-copy to every sibling.
 *
 * What it does (and why there is no JWT library): a sibling receives a short-lived RS256
 * "assertion" signed by Movira Core, proves it is genuine, burns its one-time id, and loads the
 * local app_user it refers to. The sibling then mints ITS OWN token exactly as its password login
 * does — nothing else in the app changes.
 *
 * Security properties enforced here:
 *   - algorithm pinned to RS256 (an attacker cannot downgrade to "none" / HS256-with-public-key)
 *   - signature checked against Movira's PUBLIC key; issuer, audience (= this app), exp, nbf checked
 *   - single use: the jti is consumed atomically in the shared auth_sso_jti table (replay → 401)
 *   - the app_user is re-read from the database; role/company/status come from the DB, not the token
 *
 * .env of the sibling
 *   MOVIRA_SSO_PUBLIC_KEY_PATH   path to Movira's sso_public.pem        (required)
 *   MOVIRA_SSO_ISSUER            default movira-core
 *   MOVIRA_CORE_SCHEMA           schema holding app_user / auth_sso_jti (each app has its own default)
 *   MOVIRA_APP_ID                this app's app_id (only for apps that do not hardcode it)
 */

class MoviraSsoException extends Exception
{
    public function __construct(string $message, private int $httpStatus = 401)
    {
        parent::__construct($message, $httpStatus);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

function movira_sso_b64url_decode(string $data): string
{
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    if ($decoded === false) {
        throw new MoviraSsoException('Malformed assertion');
    }
    return $decoded;
}

function movira_sso_public_key(): string
{
    $path = $_ENV['MOVIRA_SSO_PUBLIC_KEY_PATH'] ?? '';
    if ($path === '' || !is_readable($path)) {
        // Server misconfiguration, not the caller's fault — and never fall back to "accept".
        throw new MoviraSsoException('SSO is not configured on this server', 503);
    }
    return (string)file_get_contents($path);
}

/**
 * Verifies the assertion and returns its claims.
 *
 * @param string $expectedAppId the app_id of THIS app (assertion `aud` must equal it)
 * @throws MoviraSsoException
 */
function movira_sso_verify(string $assertion, string $expectedAppId): array
{
    if ($expectedAppId === '') {
        throw new MoviraSsoException('SSO is not configured on this server', 503);
    }

    $parts = explode('.', $assertion);
    if (count($parts) !== 3) {
        throw new MoviraSsoException('Malformed assertion');
    }
    [$h64, $p64, $s64] = $parts;

    $header = json_decode(movira_sso_b64url_decode($h64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256') {
        throw new MoviraSsoException('Unsupported assertion algorithm');
    }

    $key = openssl_pkey_get_public(movira_sso_public_key());
    if ($key === false) {
        throw new MoviraSsoException('SSO is not configured on this server', 503);
    }
    $ok = openssl_verify($h64 . '.' . $p64, movira_sso_b64url_decode($s64), $key, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        throw new MoviraSsoException('Invalid assertion signature');
    }

    $claims = json_decode(movira_sso_b64url_decode($p64), true);
    if (!is_array($claims)) {
        throw new MoviraSsoException('Malformed assertion');
    }

    $now    = time();
    $leeway = 5;
    $issuer = $_ENV['MOVIRA_SSO_ISSUER'] ?? 'movira-core';

    if (($claims['iss'] ?? '') !== $issuer)        throw new MoviraSsoException('Invalid assertion issuer');
    if (($claims['aud'] ?? '') !== $expectedAppId) throw new MoviraSsoException('Assertion is not for this application');
    if (($claims['exp'] ?? 0) < $now - $leeway)    throw new MoviraSsoException('Assertion expired');
    if (($claims['nbf'] ?? 0) > $now + $leeway)    throw new MoviraSsoException('Assertion not yet valid');
    foreach (['jti', 'sub', 'app_user_id'] as $required) {
        if (empty($claims[$required]) || !is_string($claims[$required])) {
            throw new MoviraSsoException('Malformed assertion');
        }
    }

    return $claims;
}

/** Schema names are interpolated (identifiers cannot be bound) → whitelist the characters. */
function movira_sso_schema(string $schema): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
        throw new MoviraSsoException('SSO is not configured on this server', 503);
    }
    return '`' . $schema . '`';
}

/**
 * Atomically marks the assertion's jti as used. Exactly one concurrent caller wins.
 * @throws MoviraSsoException when it was already used, expired, or never issued by Movira.
 */
function movira_sso_consume_jti(mysqli $conn, string $coreSchema, array $claims): void
{
    $schema = movira_sso_schema($coreSchema);
    $now    = time();

    $stmt = $conn->prepare(
        "UPDATE $schema.auth_sso_jti
            SET consumed_at = ?
          WHERE jti = ? AND app_id = ? AND app_user_id = ? AND identity_id = ?
            AND consumed_at IS NULL AND expires_at > ?"
    );
    $stmt->bind_param('issssi', $now, $claims['jti'], $claims['aud'], $claims['app_user_id'], $claims['sub'], $now);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new MoviraSsoException('Assertion already used or invalid');
    }
}

/**
 * Loads the app_user the assertion points at, re-checked against the database:
 * same identity, same app, same company, account verified. Returns the full row
 * (so the caller can read role / company / language exactly like its password login does).
 */
function movira_sso_load_user(mysqli $conn, string $coreSchema, array $claims): array
{
    $schema = movira_sso_schema($coreSchema);
    $company = $claims['company_id'] ?? null;

    $stmt = $conn->prepare(
        "SELECT user_id, app_id, identity_id, company_id, username, app_role_id,
                account_status, language, display_name
           FROM $schema.app_user
          WHERE user_id = ? AND app_id = ? AND identity_id = ? AND company_id <=> ?
            AND account_status = 'verified'
          LIMIT 1"
    );
    $stmt->bind_param('ssss', $claims['app_user_id'], $claims['aud'], $claims['sub'], $company);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        throw new MoviraSsoException('No active account for this application', 403);
    }
    return $row;
}
