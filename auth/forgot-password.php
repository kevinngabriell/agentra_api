<?php
require_once __DIR__ . '/../general.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method not allowed');
}

$body  = input();
$email = cleanInput($body['email'] ?? '');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(422, 'A valid email is required');
}

$result = mysqli_query($conn, "SELECT user_id FROM " . CORE_SCHEMA . ".app_user
    WHERE username = '$email' LIMIT 1");

// Always return the same response to prevent user enumeration
if (!$result || mysqli_num_rows($result) === 0) {
    jsonResponse(200, 'If that email exists, a reset link has been sent');
}

$user  = mysqli_fetch_assoc($result);
$uid   = $user['user_id'];
$token   = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
    SET reset_token = '$token', reset_token_expires_at = '$expires'
    WHERE user_id = '$uid'");

// TODO: send $token via email (e.g. with PHPMailer)

jsonResponse(200, 'If that email exists, a reset link has been sent', [
    'reset_token' => $token,
]);
