<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// The Agentra app_id is a fixed constant — defined in config.php as AGENTRA_APP_ID.
// Self-registration always targets THIS app.
// New self-registered accounts always get DEFAULT_REGISTER_ROLE (config.php / .env).
// Privilege escalation (admin/owner) must go through an authenticated admin path.

function createNewCompany($conn, $input){
    $requiredFields = ['name', 'email', 'password', 'phone', 'business_name', 'plan'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            jsonResponse(400, "Field {$field} is required");
            return;
        }
    }

    // Validate raw values BEFORE escaping — cleanInput mutates the value
    // (injecting backslashes) and would corrupt the email format check.
    $name                 = trim($input['name']                  ?? '');
    $email                = trim($input['email']                 ?? '');
    $password             = $input['password']                    ?? '';
    $passwordConfirmation = $input['password_confirmation']       ?? '';
    $phone                = trim($input['phone']                 ?? '');
    $businessName         = trim($input['business_name']         ?? '');
    // city is optional at signup (it can be filled in later from settings).
    $city                 = trim($input['city']                  ?? '');
    $plan                 = trim($input['plan']                  ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, "Email format is not valid");
        return;
    }

    if (strlen($password) < 8) {
        jsonResponse(400, "Password minimum 8 characters");
        return;
    }

    // Reject passwords that contain the email localpart — trivial diversity guard.
    $localpart = strtolower(explode('@', $email)[0]);
    if ($localpart !== '' && stripos($password, $localpart) !== false) {
        jsonResponse(400, "Password must not contain your email localpart");
        return;
    }

    if ($password !== $passwordConfirmation) {
        jsonResponse(400, "Password not match !!");
        return;
    }

    // Escape now that validation is complete.
    $nameEsc         = mysqli_real_escape_string($conn, $name);
    $emailEsc        = mysqli_real_escape_string($conn, $email);
    $phoneEsc        = mysqli_real_escape_string($conn, $phone);
    $appId           = AGENTRA_APP_ID; // server-controlled, not client-controlled
    $appRoleId       = DEFAULT_REGISTER_ROLE; // server-controlled, not client-controlled
    $businessNameEsc = mysqli_real_escape_string($conn, $businessName);
    $cityEsc         = mysqli_real_escape_string($conn, $city);
    $planEsc         = mysqli_real_escape_string($conn, $plan);

    // verify plan exists
    $serviceResult = mysqli_query($conn, "SELECT service_id, service_price FROM " . CORE_SCHEMA . ".app_services WHERE service_id = '$planEsc' AND app_id = '$appId' AND is_active = 1 LIMIT 1");
    if (!$serviceResult || mysqli_num_rows($serviceResult) === 0) {
        jsonResponse(400, "Plan not found !!");
        return;
    }

    $service = mysqli_fetch_assoc($serviceResult);
    $serviceId = $service['service_id'];
    // Starter (free) is active for good. A paid plan starts unpaid — no trial — and the account runs on
    // Starter limits until the checkout payment is confirmed (see helpers/subscription.php).
    $isFree = (int)$service['service_price'] <= 0;

    // check duplicate company
    $dupCompany = mysqli_query($conn, "SELECT company_id FROM " . CORE_SCHEMA . ".app_company WHERE app_id = '$appId' AND company_name = '$businessNameEsc' LIMIT 1");
    if ($dupCompany && mysqli_num_rows($dupCompany) > 0) {
        jsonResponse(409, 'Perusahaan dengan nama tersebut sudah terdaftar di aplikasi ini');
    }

    // check duplicate user
    $dupUser = mysqli_query($conn, "SELECT user_id FROM " . CORE_SCHEMA . ".app_user WHERE app_id = '$appId' AND (username = '$emailEsc' OR phone_number = '$phoneEsc') LIMIT 1");
    if ($dupUser && mysqli_num_rows($dupUser) > 0) {
        jsonResponse(409, 'Akun dengan email atau nomor telepon tersebut sudah terdaftar');
    }

    $companyId      = generateUUID();
    $subscriptionId = generateUUID();
    $userId         = generateUUID();
    $today          = date('Y-m-d');
    $periodEnd      = $isFree ? date('Y-m-d', strtotime('+100 years')) : $today;
    $periodEndDt    = $periodEnd . ' 23:59:59';
    $paymentStatus  = $isFree ? 'paid' : 'unpaid';
    // Use PASSWORD_DEFAULT so PHP can upgrade to Argon2 without code changes,
    // and password_needs_rehash() can re-hash on next login.
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Three dependent INSERTs — wrap in a transaction so a partial failure
    // (e.g. subscription insert fails after company insert) doesn't leave
    // orphaned rows that would block a clean retry.
    mysqli_begin_transaction($conn);
    try {
        if (DEFAULT_REGISTER_ROLE === '') throw new Exception('DEFAULT_REGISTER_ROLE is not configured');

        $okCompany = mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_company
            (company_id, company_name, city, app_id, status, expired_at)
            VALUES ('$companyId', '$businessNameEsc', '$cityEsc', '$appId', 'active', '$periodEndDt')");
        if (!$okCompany) throw new Exception('company insert failed');

        $okSub = mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_subscription
            (subscription_id, app_company_id, app_id, service_id, start_date, next_billing_date, billing_cycle, subscription_status, payment_status)
            VALUES ('$subscriptionId', '$companyId', '$appId', '$serviceId', '$today', '$periodEnd', 'monthly', 'active', '$paymentStatus')");
        if (!$okSub) throw new Exception('subscription insert failed');

        $okUser = mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_user
            (user_id, username, first_name, email, password, phone_number, account_status, app_id, app_role_id, company_id)
            VALUES ('$userId', '$emailEsc', '$nameEsc', '$emailEsc', '$hashedPassword', '$phoneEsc', 'verified', '$appId', '$appRoleId', '$companyId')");
        if (!$okUser) throw new Exception('user insert failed');

        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        error_log('register failed: ' . $e->getMessage() . ' / ' . mysqli_error($conn));
        Response::internalError();
    }

    jsonResponse(201, $isFree ? 'Akun berhasil dibuat.' : 'Akun berhasil dibuat. Selesaikan pembayaran paket setelah login untuk mengaktifkan fitur paket.', [
        'name'             => $name,
        'email'            => $email,
        'plan'             => $plan,
        'account_status'   => 'verified',
        'payment_required' => !$isFree,
    ]);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn = getConn();

    switch($method){
        case 'POST':
            $input = input();
            createNewCompany($conn, $input);
            break;
        default : 
            jsonResponse(405, 'Method not allowed');
            break;
    }

} catch (\Throwable $e){
    RequestLogger::setException($e);
    error_log('register exception: ' . $e->getMessage());
    Response::internalError();
}
