<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/response.php';

$method = $_SERVER['REQUEST_METHOD'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Helper ────────────────────────────────────────────────────────────────────

function tokenPair(array $user): array {
  $claims = ['sub' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];
  return [
    'access_token'  => JWT::encode($claims),
    'refresh_token' => JWT::encodeRefresh($claims),
    'token_type'    => 'Bearer',
    'expires_in'    => JWT::ACCESS_TTL,
  ];
}

// ── POST /api/v1/auth/login ───────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
  $email = trim($input['email'] ?? '');
  $password = trim($input['password'] ?? '');

  if (!$email || !$password) {
    Response::error('Email and password are required', 422);
  }

  $user = DB::fetchOne(
    'SELECT user_id, username, password, app_role_id FROM app_user WHERE username = ? AND app_id = "7c2e6a2f-b254-4bbb-87f2-c6ece0f88db2" LIMIT 1',
    [$email]
  );

  if (!$user || !password_verify($password, $user['password'])) {
    Response::error('Invalid credentials', 401);
  }

  Response::success(tokenPair($user), 'Login successful');
}

// --- POST /api/v1/auth/register
if ($action === 'register' && $method === 'POST') {
  $name = trim($input['name'] ?? '');
  $email = trim($input['email'] ?? '');
  $password = trim($input['password'] ?? '');
  $password_confirmation = trim($input['password_confirmation'] ?? '');
  $phone = trim($input['phone'] ?? '');
  $app_id        = trim($input['app_id'] ?? '');
  $app_role_id   = trim($input['app_role_id'] ?? '');
  $business_name = trim($input['business_name'] ?? '');
  $city          = trim($input['city'] ?? '');
  $plan          = trim($input['plan'] ?? '');

  $errors = [];

  //check name validation
  if (!$name)  $errors['name'] = 'Nama wajib diisi';

  //check email validation
  if (!$email) {
    $errors['email'] = 'Email wajib diisi';
  } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Format email tidak valid';
  }

  //check password validation
  if (!$password) {
    $errors['password'] = 'Password wajib diisi';
  } else if (strlen($password) < 8) {
    $errors['password'] = 'Min 8 karakter';
  }

  //check password not match 
  if ($password !== $password_confirmation) {
    $errors['password_confirmation'] = 'Konfirmasi password tidak cocok';
  }

  //check required validation
  if (!$phone)         $errors['phone']         = 'Nomor telepon wajib diisi';
  if (!$business_name) $errors['business_name'] = 'Nama bisnis wajib diisi';
  if (!$city)          $errors['city']          = 'Kota wajib diisi';
  if (!$plan)          $errors['plan']          = 'Plan wajib dipilih';
  if (!$app_id)        $errors['app_id']        = 'App ID wajib diisi';
  if (!$app_role_id)   $errors['app_role_id']   = 'App Role ID wajib diisi';

  if (!empty($errors)) {
    Response::json(['status' => 'error', 'errors' => $errors], 422);
  }

  // verify plan exists as a service in this app
  $service = DB::fetchOne(
    'SELECT service_id FROM app_services WHERE service_name = ? AND app_id = ? LIMIT 1',
    [$plan, $app_id]
  );
  if (!$service) {
    Response::json(['status' => 'error', 'message' => 'Plan tidak ditemukan untuk aplikasi ini'], 404);
  }

  // check duplicate company by name within same app
  $existingCompany = DB::fetchOne(
    'SELECT company_id FROM app_company WHERE app_id = ? AND company_name = ? LIMIT 1',
    [$app_id, $business_name]
  );
  if ($existingCompany) {
    Response::json(['status' => 'error', 'message' => 'Perusahaan dengan nama tersebut sudah terdaftar di aplikasi ini'], 409);
  }

  // check duplicate user by email or phone within same app
  $existingUser = DB::fetchOne(
    'SELECT user_id FROM app_user WHERE app_id = ? AND (username = ? OR phone_number = ?) LIMIT 1',
    [$app_id, $email, $phone]
  );
  if ($existingUser) {
    Response::json(['status' => 'error', 'message' => 'Akun dengan email atau nomor telepon tersebut sudah terdaftar'], 409);
  }

  $companyId      = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
  $subscriptionId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
  $userId         = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

  $today          = date('Y-m-d');
  $trialEnd       = date('Y-m-d', strtotime('+14 days'));
  $trialEndDt     = date('Y-m-d H:i:s', strtotime('+14 days'));
  $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

  // 1. create company
  DB::execute(
    'INSERT INTO app_company (company_id, company_name, city, app_id, status, expired_at)
     VALUES (?, ?, ?, ?, "active", ?)',
    [$companyId, $business_name, $city, $app_id, $trialEndDt]
  );

  // 2. create subscription
  DB::execute(
    'INSERT INTO app_subscription (subscription_id, app_company_id, app_id, service_id, start_date, next_billing_date, billing_cycle, subscription_status, payment_status)
     VALUES (?, ?, ?, ?, ?, ?, "monthly", "active", "unpaid")',
    [$subscriptionId, $companyId, $app_id, $service['service_id'], $today, $trialEnd]
  );

  // 3. create user linked to new company
  DB::execute(
    'INSERT INTO app_user (user_id, username, first_name, email, password, phone_number, account_status, app_id, app_role_id, company_id)
     VALUES (?, ?, ?, ?, ?, ?, "verified", ?, ?, ?)',
    [$userId, $email, $name, $email, $hashedPassword, $phone, $app_id, $app_role_id, $companyId]
  );

  Response::json([
    'status'  => 'success',
    'message' => 'Akun berhasil dibuat. Masa trial aktif selama 14 hari.',
    'data'    => [
      'user_id'         => $userId,
      'company_id'      => $companyId,
      'subscription_id' => $subscriptionId,
      'name'            => $name,
      'email'           => $email,
      'plan'            => $plan,
      'account_status'  => 'verified',
      'trial_expires_at' => $trialEndDt,
    ],
  ], 201);
}

// ── POST /api/v1/auth/refresh ─────────────────────────────────────────────────
if ($action === 'refresh' && $method === 'POST') {
  $refreshToken = trim($input['refresh_token'] ?? '');

  if (!$refreshToken) {
    Response::error('refresh_token is required', 422);
  }

  try {
    $payload = JWT::decode($refreshToken, allowRefresh: true);
  } catch (Exception $e) {
    Response::error($e->getMessage(), 401);
  }

  $user = DB::fetchOne(
    'SELECT id, username, role FROM users WHERE id = ? LIMIT 1',
    [$payload['sub']]
  );

  if (!$user) {
    Response::error('User not found', 401);
  }

  Response::success(tokenPair($user), 'Token refreshed');
}

// ── POST /api/v1/auth/logout ──────────────────────────────────────────────────
if ($action === 'logout' && $method === 'POST') {
  // Stateless JWT — client is responsible for discarding the token.
  // To enforce server-side logout, store a token blacklist in the DB.
  Response::success(null, 'Logged out successfully');
}

// ── POST /api/v1/auth/forgot-password ────────────────────────────────────────
if ($action === 'forgot-password' && $method === 'POST') {
  $email = trim($input['email'] ?? '');

  if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('A valid email is required', 422);
  }

  $user = DB::fetchOne(
    'SELECT id FROM users WHERE email = ? LIMIT 1',
    [$email]
  );

  // Always return the same response to prevent user enumeration
  if (!$user) {
    Response::success(null, 'If that email exists, a reset link has been sent');
  }

  $token   = bin2hex(random_bytes(32));
  $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

  DB::execute(
    'UPDATE users SET reset_token = ?, reset_token_expires_at = ? WHERE id = ?',
    [$token, $expires, $user['id']]
  );

  // TODO: send $token via email (e.g. with PHPMailer or an SMTP helper)

  Response::success(['reset_token' => $token], 'If that email exists, a reset link has been sent');
}

// ── POST /api/v1/auth/reset-password ─────────────────────────────────────────
if ($action === 'reset-password' && $method === 'POST') {
  $token    = trim($input['token'] ?? '');
  $password = trim($input['password'] ?? '');

  if (!$token || !$password) {
    Response::error('Token and new password are required', 422);
  }

  if (strlen($password) < 8) {
    Response::error('Password must be at least 8 characters', 422);
  }

  $user = DB::fetchOne(
    'SELECT id FROM users WHERE reset_token = ? AND reset_token_expires_at > NOW() LIMIT 1',
    [$token]
  );

  if (!$user) {
    Response::error('Invalid or expired reset token', 400);
  }

  $hashed = password_hash($password, PASSWORD_BCRYPT);

  DB::execute(
    'UPDATE users SET password = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?',
    [$hashed, $user['id']]
  );

  Response::success(null, 'Password reset successfully');
}

Response::error('Route not found', 404);
