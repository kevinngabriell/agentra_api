<?php
// POST /api/v1/auth/sso   Body: { "assertion": "<from Movira Core POST /auth/sso/assertion>" }
//
// Single sign-on entry. Replaces typing a password: Movira Core already authenticated the person.
// Returns the SAME access/refresh pair as auth/login.php (same claims: sub, username, role,
// company_id), so auth/refresh.php and every JWT::fromRequest() caller keep working untouched.
// See movira_api/docs/SSO_Implementation_Notes.md.

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../sso/MoviraSso.php';

// Agentra's app_id — defined in config.php as AGENTRA_APP_ID.
function ssoLogin($conn, $input) {
    $assertion = trim((string)($input['assertion'] ?? ''));
    if ($assertion === '') {
        throw ApiException::from('ERR_AUTH_014');
    }

    try {
        $claims = movira_sso_verify($assertion, AGENTRA_APP_ID);
        movira_sso_consume_jti($conn, CORE_SCHEMA, $claims);
        $user   = movira_sso_load_user($conn, CORE_SCHEMA, $claims);
    } catch (MoviraSsoException $e) {
        $httpStatus = $e->httpStatus();
        $code = match ($httpStatus) {
            503             => 'ERR_SSO_001',
            403             => 'ERR_SSO_005',
            400             => 'ERR_SSO_006',
            401             => 'ERR_SSO_002',
            default         => 'ERR_SSO_002',
        };
        Response::error($code, $e->getMessage(), $httpStatus);
    }

    $jwtClaims = [
        'sub'        => $user['user_id'],
        'username'   => $user['username'],
        'role'       => $user['app_role_id'],
        'company_id' => $user['company_id'],
    ];

    RequestLogger::setActor($jwtClaims);

    Response::success([
        'access_token'  => JWT::encode($jwtClaims),
        'refresh_token' => JWT::encodeRefresh($jwtClaims),
        'token_type'    => 'Bearer',
        'expires_in'    => JWT::ACCESS_TTL,
    ], null, 200, 'Login successful');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = input();
            ssoLogin($conn, $input);
            break;
        default:
            Response::methodNotAllowed();
    }
} catch (ApiException $e) {
    Response::error($e->errCode, $e->getMessage(), $e->httpStatus, $e->details);
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log('SSO exception: ' . $e->getMessage());
    Response::internalError();
}
