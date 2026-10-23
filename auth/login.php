<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function login($conn, $input){
    $email    = trim($input['email']    ?? '');
    $password = $input['password']      ?? '';

    if (!$email || $password === '') {
        throw ApiException::from('ERR_AUTH_005');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw ApiException::from('ERR_AUTH_006');
    }

    $emailEscaped = mysqli_real_escape_string($conn, $email);

    $result = mysqli_query($conn, "SELECT user_id, username, password, app_role_id, company_id, account_status FROM " . CORE_SCHEMA . ".app_user WHERE username = '$emailEscaped' AND app_id = '" . AGENTRA_APP_ID . "' LIMIT 1");

    if (!$result) {
        error_log('login DB error: ' . mysqli_error($conn));
        throw ApiException::from('ERR_COMMON_005');
    }

    $dummyHash = '$2y$10$abcdefghijklmnopqrstuvDEADBEEFDEADBEEFDEADBEEFDEADBEEF';
    $user = ($result && mysqli_num_rows($result) > 0) ? mysqli_fetch_assoc($result) : ['password' => $dummyHash];

    if (!password_verify($password, $user['password'])) {
        throw ApiException::from('ERR_AUTH_001');
    }

    if (!isset($user['user_id'])) {
        throw ApiException::from('ERR_AUTH_001');
    }

    if (in_array($user['account_status'] ?? '', ['suspended', 'disabled', 'rejected'], true)) {
        throw ApiException::from('ERR_AUTH_003');
    }

    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if ($newHash !== false) {
            $uid = mysqli_real_escape_string($conn, $user['user_id']);
            mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET password = '$newHash' WHERE user_id = '$uid'");
        }
    }

    $claims = [
        'sub'        => $user['user_id'],
        'username'   => $user['username'],
        'role'       => $user['app_role_id'],
        'company_id' => $user['company_id'],
    ];

    RequestLogger::setActor($claims);

    Response::success([
        'access_token'  => JWT::encode($claims),
        'refresh_token' => JWT::encodeRefresh($claims),
        'token_type'    => 'Bearer',
        'expires_in'    => JWT::ACCESS_TTL,
    ], null, 200, 'Login successful');
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn = getConn();

    switch($method){
        case 'POST':
            $input = input();
            login($conn, $input);
            break;
        default :
            Response::methodNotAllowed();
    }

} catch (ApiException $e) {
    Response::error($e->errCode, $e->getMessage(), $e->httpStatus, $e->details);
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log('login exception: ' . $e->getMessage());
    Response::internalError();
}
