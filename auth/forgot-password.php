<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../notification/notification.php';

function forgotPassword($conn, $input) {
    $email = trim($input['email'] ?? '');

    if (!$email) {
        jsonResponse(422, 'Email is required');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(422, 'Email format is not valid');
    }

    $emailEscaped = mysqli_real_escape_string($conn, $email);

    $result = mysqli_query($conn, "SELECT user_id, phone_number, reset_attempts FROM " . CORE_SCHEMA . ".app_user
        WHERE username = '$emailEscaped' LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        // Same message as the success path — no user enumeration via body.
        jsonResponse(200, 'If that email exists, a reset link has been sent');
    }

    $user  = mysqli_fetch_assoc($result);
    $uid   = mysqli_real_escape_string($conn, $user['user_id']);
    $phone = preg_replace('/[^0-9]/', '', $user['phone_number'] ?? '');

    if (!$phone) {
        jsonResponse(200, 'If that email exists, a reset link has been sent');
    }

    // Rate limit: lock the account out of reset requests after 5 attempts.
    // Even a high-entropy token benefits from this — it blocks brute-force amplification.
    $attempts = (int)($user['reset_attempts'] ?? 0);
    if ($attempts >= 5) {
        error_log("forgot-password: account $uid locked after $attempts attempts");
        jsonResponse(200, 'If that email exists, a reset link has been sent');
    }

    // Higher-entropy reset token: 16 bytes hex = 128 bits (was 6-digit OTP, ~20 bits).
    // The previous scheme was brute-forceable in ~3h within the 1h TTL.
    $token = bin2hex(random_bytes(16));

    // Hash the token before storing — plaintext storage means a DB read
    // (SQLi, backup leak) gives the attacker active OTPs for any account.
    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
    $tokenHashEsc = mysqli_real_escape_string($conn, $tokenHash);

    mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
        SET reset_token = '$tokenHashEsc',
            reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR),
            reset_attempts = reset_attempts + 1
        WHERE user_id = '$uid'");

    if (mysqli_errno($conn)) {
        error_log('forgot-password DB error: ' . mysqli_error($conn));
        jsonResponse(500, 'Internal Server Error');
    }

    $chatId = "{$phone}@c.us";
    $text   = "Halo!\n\nKami menerima permintaan reset password untuk akun *{$email}*.\n\n*Kode Reset Password:*\n*{$token}*\n\nKode ini berlaku selama *1 jam*. Jangan bagikan kode ini kepada siapapun.\n\nJika kamu tidak merasa meminta ini, abaikan pesan ini.\nTerima kasih!";

    sendWhatsAppText($chatId, $text);

    jsonResponse(200, 'If that email exists, a reset link has been sent');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = input();
            forgotPassword($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method not allowed');
            break;
    }
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log('forgot-password exception: ' . $e->getMessage());
    Response::internalError();
}
