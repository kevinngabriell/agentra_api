<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- US-001: GET /api/v1/users/me ---
function getMe($conn, $user_id) {
    $user_id = mysqli_real_escape_string($conn, $user_id);
    $result = mysqli_query($conn, "SELECT user_id, username, first_name, email, phone_number, language, app_role_id, account_status, created_at, updated_at FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$user_id' LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'User not found');
        return;
    }

    jsonResponse(200, 'User profile retrieved', mysqli_fetch_assoc($result));
}

// --- US-002: PUT /api/v1/users/me ---
function updateMe($conn, $user_id, $input) {
    $user_id = mysqli_real_escape_string($conn, $user_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$user_id' LIMIT 1");
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'User not found');
        return;
    }

    $updates = [];

    if (isset($input['first_name'])) {
        $first_name = trim(mysqli_real_escape_string($conn, $input['first_name']));
        if ($first_name === '') {
            jsonResponse(400, 'first_name cannot be empty');
            return;
        }
        $updates[] = "first_name = '$first_name'";
    }

    if (isset($input['phone_number'])) {
        $phone = trim(mysqli_real_escape_string($conn, $input['phone_number']));
        $updates[] = "phone_number = " . ($phone !== '' ? "'$phone'" : "NULL");
    }

    if (isset($input['language'])) {
        $lang = trim(mysqli_real_escape_string($conn, $input['language']));
        if ($lang !== '') {
            $updates[] = "language = '$lang'";
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $updates[] = "updated_by = '$user_id'";

    if (mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET " . implode(', ', $updates) . " WHERE user_id = '$user_id'")) {
        jsonResponse(200, 'Profile updated successfully');
    } else {
        jsonResponse(500, 'Failed to update profile', ['error' => mysqli_error($conn)]);
    }
}

// --- US-003: PUT /api/v1/users/me/password ---
function changePassword($conn, $user_id, $input) {
    $user_id = mysqli_real_escape_string($conn, $user_id);

    $current_password = trim($input['current_password'] ?? '');
    $new_password     = trim($input['new_password'] ?? '');

    if (!$current_password || !$new_password) {
        jsonResponse(400, 'current_password and new_password are required');
        return;
    }

    if (strlen($new_password) < 8) {
        jsonResponse(400, 'new_password must be at least 8 characters');
        return;
    }

    $result = mysqli_query($conn, "SELECT password FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$user_id' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'User not found');
        return;
    }

    $user = mysqli_fetch_assoc($result);
    if (!password_verify($current_password, $user['password'])) {
        jsonResponse(401, 'Current password is incorrect');
        return;
    }

    $hashed = password_hash($new_password, PASSWORD_BCRYPT);
    $hashed = mysqli_real_escape_string($conn, $hashed);

    if (mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET password = '$hashed', updated_by = '$user_id' WHERE user_id = '$user_id'")) {
        jsonResponse(200, 'Password changed successfully');
    } else {
        jsonResponse(500, 'Failed to change password', ['error' => mysqli_error($conn)]);
    }
}

// --- US-004: PUT /api/v1/users/me/notification-settings ---
function updateNotificationSettings($conn, $user_id, $company_id, $input) {
    $user_id    = mysqli_real_escape_string($conn, $user_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn, "SELECT setting_id FROM " . APP_SCHEMA . ".notification_settings WHERE company_id = '$company_id' LIMIT 1");
    if (!$check) {
        jsonResponse(500, 'Database error', ['error' => mysqli_error($conn)]);
        return;
    }

    $updates = [];

    if (isset($input['daily_digest_enabled'])) {
        $val = $input['daily_digest_enabled'] ? 1 : 0;
        $updates[] = "daily_digest_enabled = $val";
    }

    if (isset($input['daily_digest_time'])) {
        $t = trim(mysqli_real_escape_string($conn, $input['daily_digest_time']));
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
            jsonResponse(400, 'daily_digest_time must be in HH:MM or HH:MM:SS format');
            return;
        }
        $updates[] = "daily_digest_time = '$t'";
    }

    if (isset($input['daily_days_of_week'])) {
        $days = trim(mysqli_real_escape_string($conn, $input['daily_days_of_week']));
        if (!preg_match('/^[1-7](,[1-7])*$/', $days)) {
            jsonResponse(400, 'daily_days_of_week must be comma-separated values between 1 and 7');
            return;
        }
        $updates[] = "daily_days_of_week = '$days'";
    }

    if (isset($input['monthly_digest_enabled'])) {
        $val = $input['monthly_digest_enabled'] ? 1 : 0;
        $updates[] = "monthly_digest_enabled = $val";
    }

    if (isset($input['monthly_digest_day'])) {
        $day = (int)$input['monthly_digest_day'];
        if ($day < 1 || $day > 28) {
            jsonResponse(400, 'monthly_digest_day must be between 1 and 28');
            return;
        }
        $updates[] = "monthly_digest_day = $day";
    }

    if (isset($input['monthly_digest_time'])) {
        $t = trim(mysqli_real_escape_string($conn, $input['monthly_digest_time']));
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) {
            jsonResponse(400, 'monthly_digest_time must be in HH:MM or HH:MM:SS format');
            return;
        }
        $updates[] = "monthly_digest_time = '$t'";
    }

    if (isset($input['whatsapp_target_number'])) {
        $wa = trim(mysqli_real_escape_string($conn, $input['whatsapp_target_number']));
        if ($wa === '') {
            jsonResponse(400, 'whatsapp_target_number cannot be empty');
            return;
        }
        $updates[] = "whatsapp_target_number = '$wa'";
    }

    if (mysqli_num_rows($check) > 0) {
        if (empty($updates)) {
            jsonResponse(400, 'No fields provided for update');
            return;
        }

        $updates[] = "updated_by = '$user_id'";

        if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".notification_settings SET " . implode(', ', $updates) . " WHERE company_id = '$company_id'")) {
            jsonResponse(200, 'Notification settings updated successfully');
        } else {
            jsonResponse(500, 'Failed to update notification settings', ['error' => mysqli_error($conn)]);
        }
    } else {
        // First-time setup — whatsapp_target_number is mandatory
        if (!isset($input['whatsapp_target_number']) || trim($input['whatsapp_target_number']) === '') {
            jsonResponse(400, 'whatsapp_target_number is required for initial setup');
            return;
        }

        $setting_id      = 'notif_' . uniqid();
        $wa              = trim(mysqli_real_escape_string($conn, $input['whatsapp_target_number']));
        $daily_enabled   = isset($input['daily_digest_enabled'])  ? ($input['daily_digest_enabled']  ? 1 : 0) : 1;
        $daily_time      = isset($input['daily_digest_time'])     ? trim(mysqli_real_escape_string($conn, $input['daily_digest_time']))  : '08:00:00';
        $daily_days      = isset($input['daily_days_of_week'])    ? trim(mysqli_real_escape_string($conn, $input['daily_days_of_week'])) : '1,2,3,4,5,6';
        $monthly_enabled = isset($input['monthly_digest_enabled'])? ($input['monthly_digest_enabled'] ? 1 : 0) : 1;
        $monthly_day     = isset($input['monthly_digest_day'])    ? (int)$input['monthly_digest_day'] : 1;
        $monthly_time    = isset($input['monthly_digest_time'])   ? trim(mysqli_real_escape_string($conn, $input['monthly_digest_time'])) : '08:00:00';

        $sql = "INSERT INTO " . APP_SCHEMA . ".notification_settings
            (setting_id, company_id, user_id, daily_digest_enabled, daily_digest_time, daily_days_of_week, monthly_digest_enabled, monthly_digest_day, monthly_digest_time, whatsapp_target_number, updated_by)
            VALUES
            ('$setting_id', '$company_id', '$user_id', $daily_enabled, '$daily_time', '$daily_days', $monthly_enabled, $monthly_day, '$monthly_time', '$wa', '$user_id')";

        if (mysqli_query($conn, $sql)) {
            jsonResponse(201, 'Notification settings created successfully');
        } else {
            jsonResponse(500, 'Failed to create notification settings', ['error' => mysqli_error($conn)]);
        }
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$user_id    = $authUser['sub'] ?? $authUser['user_id'] ?? null;
$company_id = $authUser['company_id'] ?? null;

// $action  = $parts[3] (e.g. 'me')
// $parts[4] = sub-action (e.g. 'password', 'notification-settings')
$sub_action = $parts[4] ?? '';

if ($action !== 'me') {
    jsonResponse(404, 'Route not found');
}

try {
    $conn = getConn();

    if ($sub_action === 'password') {
        if ($method !== 'PUT') { jsonResponse(405, 'Method Not Allowed'); }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        changePassword($conn, $user_id, $input);

    } elseif ($sub_action === 'notification-settings') {
        if ($method !== 'PUT') { jsonResponse(405, 'Method Not Allowed'); }
        if (!$company_id) { jsonResponse(400, 'company_id is required'); }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateNotificationSettings($conn, $user_id, $company_id, $input);

    } elseif ($sub_action === '') {
        switch ($method) {
            case 'GET':
                getMe($conn, $user_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateMe($conn, $user_id, $input);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    } else {
        jsonResponse(404, 'Route not found');
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
