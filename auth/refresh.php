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
    } catch (Exception $e) {
        jsonResponse(401, 'Invalid or expired refresh token');
    }

    $userId = cleanInput($payload['sub'] ?? '');

    $result = mysqli_query($conn, "SELECT user_id, username, app_role_id
        FROM " . CORE_SCHEMA . ".app_user
        WHERE user_id = '$userId'
        LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(401, 'User not found');
    }

    $user = mysqli_fetch_assoc($result);

    $claims = [
        'sub'      => $user['user_id'],
        'username' => $user['username'],
        'role'     => $user['app_role_id'],
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
            $input = json_decode(file_get_contents('php://input'), true);
            refreshToken($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method not allowed');
            break;
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
