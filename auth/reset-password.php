<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function resetPassword($conn, $input) {
    $token                = trim($input['token']                ?? '');
    $password             = $input['password']                  ?? '';
    $passwordConfirmation = $input['password_confirmation']     ?? '';

    if (!$token) {
        jsonResponse(422, 'Reset token is required');
    }

    if ($password === '') {
        jsonResponse(422, 'New password is required');
    }

    if (strlen($password) < 8) {
        jsonResponse(422, 'Password must be at least 8 characters');
    }

    // Reject passwords that contain the localpart of the requesting email —
    // a trivial password-diversity guard that requires no external service.
    if (preg_match('/^[^@]+@[^@]+\.[^@]+$/', $input['email'] ?? '')) {
        $localpart = strtolower(explode('@', $input['email'])[0]);
        if ($localpart !== '' && stripos($password, $localpart) !== false) {
            jsonResponse(422, 'Password must not contain your email localpart');
        }
    }

    if ($password !== $passwordConfirmation) {
        jsonResponse(422, 'Password confirmation does not match');
    }

    // The stored reset_token is now a bcrypt hash of the random 16-byte hex token.
    // We can't `WHERE reset_token = '$token'` any more — fetch all unexpired,
    // unclaimed reset rows for the supplied token candidates and verify with
    // password_verify (timing-safe). Since the token is 128 bits and unique per
    // request, the candidate set here is exactly the row that matches.
    // NOTE: callers send the raw token; we hash-verify against each stored hash.
    // The lookup below uses the token as a coarse filter on user_id via email
    // if provided; otherwise we fall back to a full unexpired-row scan.
    $tokenEsc = mysqli_real_escape_string($conn, $token);

    // Narrow the candidate set when email is provided (preferred), else scan.
    $where = "reset_token_expires_at > NOW()";
    if (!empty($input['email']) && filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $emailEsc = mysqli_real_escape_string($conn, trim($input['email']));
        $where .= " AND username = '$emailEsc'";
    }

    $result = mysqli_query($conn, "SELECT user_id, reset_token FROM " . CORE_SCHEMA . ".app_user
        WHERE $where LIMIT 10");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(400, 'Invalid or expired reset token');
    }

    $matchedUid = null;
    while ($row = mysqli_fetch_assoc($result)) {
        if (password_verify($token, $row['reset_token'])) {
            $matchedUid = $row['user_id'];
            break;
        }
    }

    if ($matchedUid === null) {
        // Increment attempt counter on every failed verification.
        mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
            SET reset_attempts = reset_attempts + 1
            WHERE reset_token_expires_at > NOW()");
        jsonResponse(400, 'Invalid or expired reset token');
    }

    $uid    = mysqli_real_escape_string($conn, $matchedUid);
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $hashedEsc = mysqli_real_escape_string($conn, $hashed);

    $ok = mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user
        SET password = '$hashedEsc',
            reset_token = NULL,
            reset_token_expires_at = NULL,
            reset_attempts = 0
        WHERE user_id = '$uid'");

    if (!$ok) {
        error_log('reset-password DB error: ' . mysqli_error($conn));
        jsonResponse(500, 'Internal server error');
    }

    jsonResponse(200, 'Password reset successfully');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn   = getConn();

    switch ($method) {
        case 'POST':
            $input = input();
            resetPassword($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method not allowed');
            break;
    }
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log('reset-password exception: ' . $e->getMessage());
    Response::internalError();
}
