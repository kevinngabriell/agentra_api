<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../notification/notification.php';

function forgotPassword($conn, $input) {
    $email = cleanInput($input['email'] ?? '');

    if (!$email) {
        jsonResponse(422, 'Email is required');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(422, 'Email format is not valid');
    }

    $result = mysqli_query($conn, "SELECT user_id, phone_number FROM " . CORE_SCHEMA . ".app_user
        WHERE username = '$email' LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(200, 'If that email exists, a reset link has been sent');
    }

    $user  = mysqli_fetch_assoc($result);
    $uid   = cleanInput($user['user_id']);
    $phone = preg_replace('/[^0-9]/', '', $user['phone_number'] ?? '');

    if (!$phone) {
        jsonResponse(200, 'If that email exists, a reset link has been sent');
    }

    $token = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
        SET reset_token = '$token', reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
        WHERE user_id = '$uid'");

    if (mysqli_errno($conn)) {
        jsonResponse(500, 'Internal Server Error', ['error' => mysqli_error($conn)]);
    }

    $chatId = "{$phone}@c.us";
    $text   = "Halo! 👋\n\nKami menerima permintaan reset password untuk akun *{$email}*.\n\n*Kode OTP Reset Password:*\n*{$token}*\n\nKode ini berlaku selama *1 jam*. Jangan bagikan kode ini kepada siapapun.\n\nJika kamu tidak merasa meminta ini, abaikan pesan ini.\nTerima kasih! 🔐";

    sendWhatsAppText($chatId, $text);

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
