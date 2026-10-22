<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function refreshToken($conn, $input) {
    $refreshToken = trim($input['refresh_token'] ?? '');

    if (!$refreshToken) {
        jsonResponse(422, 'refresh_token is required');
    }

    try {
        $payload = JWT::decode($refreshToken, allowRefresh: true);
    } catch (\Throwable $e) {
        Response::unauthorized('Invalid or expired refresh token');
    }

    $userId = mysqli_real_escape_string($conn, $payload['sub'] ?? '');

    // Include company_id in the lookup so the refreshed access token keeps
    // the same claims as the original login token. Previously this dropped
    // company_id, which broke every company-scoped query after one refresh.
    $result = mysqli_query($conn, "SELECT user_id, username, app_role_id, company_id, account_status
        FROM " . CORE_SCHEMA . ".app_user
        WHERE user_id = '$userId'
        LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(401, 'User not found');
    }

    $user = mysqli_fetch_assoc($result);

    if (in_array($user['account_status'] ?? '', ['suspended', 'disabled', 'rejected'], true)) {
        throw ApiException::from('ERR_AUTH_003');
    }

    $claims = [
        'sub'        => $user['user_id'],
        'username'   => $user['username'],
        'role'       => $user['app_role_id'],
        'company_id' => $user['company_id'],
    ];

    jsonResponse(200, 'Token refreshed successfully', [
        'access_token'  => JWT::encode($claims),
        'refresh_token' => JWT::encodeRefresh($claims),
        'token_type'    => 'Bearer',
        'expires_in'    => JWT::ACCESS_TTL,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = input();
            refreshToken($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method not allowed');
            break;
    }
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log('refresh exception: ' . $e->getMessage());
    Response::internalError();
}
