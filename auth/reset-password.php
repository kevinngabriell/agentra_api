<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function resetPassword($conn, $input) {
    $token                = cleanInput($input['token']                ?? '');
    $password             = trim($input['password']                   ?? '');
    $passwordConfirmation = trim($input['password_confirmation']      ?? '');

    if (!$token) {
        jsonResponse(422, 'Reset token is required');
    }

    if (!$password) {
        jsonResponse(422, 'New password is required');
    }

    if (strlen($password) < 8) {
        jsonResponse(422, 'Password must be at least 8 characters');
    }

    if ($password !== $passwordConfirmation) {
        jsonResponse(422, 'Password confirmation does not match');
    }

    $result = mysqli_query($conn, "SELECT user_id FROM " . CORE_SCHEMA . ".app_user
        WHERE reset_token = '$token' AND reset_token_expires_at > NOW() LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(400, 'Invalid or expired reset token');
    }

    $user   = mysqli_fetch_assoc($result);
    $uid    = cleanInput($user['user_id']);
    $hashed = password_hash($password, PASSWORD_BCRYPT);

    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
        SET password = '$hashed', reset_token = NULL, reset_token_expires_at = NULL
        WHERE user_id = '$uid'");

    if (mysqli_errno($conn)) {
        jsonResponse(500, 'Failed to reset password: ' . mysqli_error($conn));
    }

    jsonResponse(200, 'Password reset successfully');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            resetPassword($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method not allowed');
            break;
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
