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
