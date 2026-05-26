<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

function createNewCompany($conn, $input){
    $requiredFields = ['name', 'email', 'password', 'phone', 'business_name', 'city', 'plan'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(400, "Field {$field} is required");
            return;
        }
    }

    $name                 = cleanInput($input['name']                  ?? '');
    $email                = cleanInput($input['email']                 ?? '');
    $password             = trim($input['password']                    ?? '');
    $passwordConfirmation = trim($input['password_confirmation']       ?? '');
    $phone                = cleanInput($input['phone']                 ?? '');
    $appId                = cleanInput($input['app_id']                ?? '');
    $appRoleId            = cleanInput($input['app_role_id']           ?? '');
    $businessName         = cleanInput($input['business_name']         ?? '');
    $city                 = cleanInput($input['city']                  ?? '');
    $plan                 = cleanInput($input['plan']                  ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, "Email format is not valid");
        return;
    }

    if (strlen($password) < 8) {
        jsonResponse(400, "Password minimum 8 characters");
        return;
    }

    if ($password !== $passwordConfirmation) {
        jsonResponse(400, "Password not match !!");
        return;
    }

    // verify plan exists
    $serviceResult = mysqli_query($conn, "SELECT service_id FROM " . CORE_SCHEMA . ".app_services WHERE service_id = '$plan' AND app_id = '$appId' LIMIT 1");
    if (!$serviceResult || mysqli_num_rows($serviceResult) === 0) {
        jsonResponse(400, "Plan not found !!");
        return;
    }

    $service = mysqli_fetch_assoc($serviceResult);
    $serviceId = $service['service_id'];

    // check duplicate company
    $dupCompany = mysqli_query($conn, "SELECT company_id FROM " . CORE_SCHEMA . ".app_company WHERE app_id = '$appId' AND company_name = '$businessName' LIMIT 1");
    if ($dupCompany && mysqli_num_rows($dupCompany) > 0) {
        jsonResponse(409, 'Perusahaan dengan nama tersebut sudah terdaftar di aplikasi ini');
    }

    // check duplicate user
    $dupUser = mysqli_query($conn, "SELECT user_id FROM " . CORE_SCHEMA . ".app_user WHERE app_id = '$appId' AND (username = '$email' OR phone_number = '$phone') LIMIT 1");
    if ($dupUser && mysqli_num_rows($dupUser) > 0) {
        jsonResponse(409, 'Akun dengan email atau nomor telepon tersebut sudah terdaftar');
    }

    $companyId      = generateUUID();
    $subscriptionId = generateUUID();
    $userId         = generateUUID();
    $today          = date('Y-m-d');
    $trialEnd       = date('Y-m-d', strtotime('+14 days'));
    $trialEndDt     = date('Y-m-d H:i:s', strtotime('+14 days'));
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_company
        (company_id, company_name, city, app_id, status, expired_at)
        VALUES ('$companyId', '$businessName', '$city', '$appId', 'active', '$trialEndDt')");
    if (mysqli_errno($conn)) {
        jsonResponse(500, 'Gagal membuat perusahaan: ' . mysqli_error($conn));
    }

    mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_subscription
        (subscription_id, app_company_id, app_id, service_id, start_date, next_billing_date, billing_cycle, subscription_status, payment_status)
        VALUES ('$subscriptionId', '$companyId', '$appId', '$serviceId', '$today', '$trialEnd', 'monthly', 'active', 'unpaid')");
    if (mysqli_errno($conn)) {
        jsonResponse(500, 'Gagal membuat langganan: ' . mysqli_error($conn));
    }

    mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_user
        (user_id, username, first_name, email, password, phone_number, account_status, app_id, app_role_id, company_id)
        VALUES ('$userId', '$email', '$name', '$email', '$hashedPassword', '$phone', 'verified', '$appId', '$appRoleId', '$companyId')");
    if (mysqli_errno($conn)) {
        jsonResponse(500, 'Gagal membuat akun: ' . mysqli_error($conn));
    }

    jsonResponse(201, 'Akun berhasil dibuat. Masa trial aktif selama 14 hari.', [
        'user_id'          => $userId,
        'company_id'       => $companyId,
        'subscription_id'  => $subscriptionId,
        'name'             => $name,
        'email'            => $email,
        'plan'             => $plan,
        'account_status'   => 'verified',
        'trial_expires_at' => $trialEndDt,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn = getConn();

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createNewCompany($conn, $input);
            break;
        default : 
            jsonResponse(405, 'Method not allowed');
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>