<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method not allowed');
}

$body     = input();
$token    = cleanInput($body['token']    ?? '');
$password = trim($body['password']       ?? '');

if (!$token || !$password) {
    jsonResponse(422, 'Token and new password are required');
}

if (strlen($password) < 8) {
    jsonResponse(422, 'Password must be at least 8 characters');
}

$result = mysqli_query($conn, "SELECT user_id FROM " . CORE_SCHEMA . ".app_user
    WHERE reset_token = '$token' AND reset_token_expires_at > NOW() LIMIT 1");

if (!$result || mysqli_num_rows($result) === 0) {
    jsonResponse(400, 'Invalid or expired reset token');
}

$user   = mysqli_fetch_assoc($result);
$uid    = $user['user_id'];
$hashed = password_hash($password, PASSWORD_BCRYPT);

mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
    SET password = '$hashed', reset_token = NULL, reset_token_expires_at = NULL
    WHERE user_id = '$uid'");

jsonResponse(200, 'Password reset successfully');
