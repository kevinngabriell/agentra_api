<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function forgotPassword($conn, $input) {
    $email = cleanInput($input['email'] ?? '');

    if (!$email) {
        jsonResponse(422, 'Email is required');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(422, 'Email format is not valid');
    }

    $result = mysqli_query($conn, "SELECT user_id FROM " . CORE_SCHEMA . ".app_user
        WHERE username = '$email' LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(200, 'If that email exists, a reset link has been sent');
    }

    $user    = mysqli_fetch_assoc($result);
    $uid     = cleanInput($user['user_id']);
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
        SET reset_token = '$token', reset_token_expires_at = '$expires'
        WHERE user_id = '$uid'");

    if (mysqli_errno($conn)) {
        jsonResponse(500, 'Failed to generate reset token: ' . mysqli_error($conn));
    }

    // TODO: send $token via email (e.g. with PHPMailer)

    jsonResponse(200, 'If that email exists, a reset link has been sent');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            forgotPassword($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method not allowed');
            break;
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
