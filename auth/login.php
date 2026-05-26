<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function login($conn, $input){
    $email = cleanInput($input['email'] ?? '');
    $password = trim($input['password'] ?? '');

    if (!$email || !$password) {
        jsonResponse(422, 'Email and password are required');
    }

    $result = mysqli_query($conn, "SELECT user_id, username, password, app_role_id FROM " . CORE_SCHEMA . ".app_user WHERE username = '$email' AND app_id = '7c2e6a2f-b254-4bbb-87f2-c6ece0f88db2' LIMIT 1");

    if (!$result) {
        jsonResponse(500, 'Database error: ' . mysqli_error($conn));
    }

    if (mysqli_num_rows($result) === 0) {
        jsonResponse(401, 'Invalid credentials');
    }

    $user = mysqli_fetch_assoc($result);

    if (!password_verify($password, $user['password'])) {
        jsonResponse(401, 'Invalid credentials');
    }

    $claims = [
        'sub'      => $user['user_id'],
        'username' => $user['username'],
        'role'     => $user['app_role_id'],
    ];

    jsonResponse(200, 'Login successful', [
        'access_token'  => JWT::encode($claims),
        'refresh_token' => JWT::encodeRefresh($claims),
        'token_type'    => 'Bearer',
        'expires_in'    => JWT::ACCESS_TTL,
    ]);

}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn = getConn();

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            login($conn, $input);
            break;
        default : 
            jsonResponse(405, 'Method not allowed');
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}