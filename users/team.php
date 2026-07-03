<?php
// Team management — lets an owner/admin manage the admins and subagents that
// share their company_id. Mounted at /api/v1/users/team by users/index.php.
//
// Routes:
//   GET    /users/team                 — list team members
//   POST   /users/team                 — invite a new admin or subagent
//   PATCH  /users/team/{user_id}/role  — change a member's role
//   DELETE /users/team/{user_id}       — deactivate a member
//
// $authUser, $conn, $company_id, $method, $parts are already in scope from
// the parent users/index.php.

const AGENTRA_APP_ID = '7c2e6a2f-b254-4bbb-87f2-c6ece0f88db2';

requireRole($authUser, ['owner', 'admin']);

function listTeam($conn, $company_id) {
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $res = mysqli_query($conn, "
        SELECT user_id, username, first_name, email, phone_number, agentra_role, account_status, created_at
        FROM " . CORE_SCHEMA . ".app_user
        WHERE company_id = '$company_id'
        ORDER BY FIELD(agentra_role, 'owner', 'admin', 'subagent'), first_name ASC
    ");
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    jsonResponse(200, 'Team members retrieved', ['items' => $rows]);
}

function inviteTeamMember($conn, $company_id, $input) {
    $required = ['name', 'email', 'password', 'password_confirmation', 'phone', 'agentra_role'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $role = mysqli_real_escape_string($conn, $input['agentra_role']);
    if (!in_array($role, ['admin', 'subagent'], true)) {
        jsonResponse(400, 'agentra_role must be admin or subagent');
        return;
    }

    $name     = cleanInput($input['name']);
    $email    = cleanInput($input['email']);
    $phone    = cleanInput($input['phone']);
    $password = trim($input['password']);
    $passwordConfirmation = trim($input['password_confirmation']);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(400, 'Email format is not valid');
        return;
    }
    if (strlen($password) < 8) {
        jsonResponse(400, 'Password minimum 8 characters');
        return;
    }
    if ($password !== $passwordConfirmation) {
        jsonResponse(400, 'Password not match');
        return;
    }

    $dupUser = mysqli_query($conn,
        "SELECT user_id FROM " . CORE_SCHEMA . ".app_user
         WHERE app_id = '" . AGENTRA_APP_ID . "' AND (username = '$email' OR phone_number = '$phone') LIMIT 1");
    if ($dupUser && mysqli_num_rows($dupUser) > 0) {
        jsonResponse(409, 'Akun dengan email atau nomor telepon tersebut sudah terdaftar');
        return;
    }

    $userId         = generateUUID();
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $companyIdEsc   = mysqli_real_escape_string($conn, $company_id);

    $ok = mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_user
        (user_id, username, first_name, email, password, phone_number, account_status, app_id, app_role_id, agentra_role, company_id)
        VALUES ('$userId', '$email', '$name', '$email', '$hashedPassword', '$phone', 'verified', '" . AGENTRA_APP_ID . "', '', '$role', '$companyIdEsc')");

    if ($ok) {
        jsonResponse(201, 'Team member created successfully', [
            'user_id'      => $userId,
            'email'        => $email,
            'agentra_role' => $role,
        ]);
    } else {
        jsonResponse(500, 'Failed to create team member', ['error' => mysqli_error($conn)]);
    }
}

function updateTeamMemberRole($conn, $company_id, $target_user_id, $input) {
    if (!$target_user_id) {
        jsonResponse(400, 'user_id is required');
        return;
    }

    $role = isset($input['agentra_role']) ? mysqli_real_escape_string($conn, $input['agentra_role']) : null;
    if (!$role || !in_array($role, ['admin', 'subagent'], true)) {
        jsonResponse(400, 'agentra_role is required and must be admin or subagent');
        return;
    }

    $target_user_id = mysqli_real_escape_string($conn, $target_user_id);
    $company_id     = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT agentra_role FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$target_user_id' AND company_id = '$company_id' LIMIT 1");
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Team member not found');
        return;
    }

    $current = mysqli_fetch_assoc($check);
    if ($current['agentra_role'] === 'owner') {
        jsonResponse(403, "The company owner's role cannot be changed");
        return;
    }

    if (mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET agentra_role = '$role' WHERE user_id = '$target_user_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Team member role updated successfully');
    } else {
        jsonResponse(500, 'Failed to update team member role', ['error' => mysqli_error($conn)]);
    }
}

function deactivateTeamMember($conn, $company_id, $target_user_id, $requesting_user_id) {
    if (!$target_user_id) {
        jsonResponse(400, 'user_id is required');
        return;
    }
    if ($target_user_id === $requesting_user_id) {
        jsonResponse(400, 'You cannot deactivate your own account');
        return;
    }

    $target_user_id = mysqli_real_escape_string($conn, $target_user_id);
    $company_id     = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT agentra_role FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$target_user_id' AND company_id = '$company_id' LIMIT 1");
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Team member not found');
        return;
    }

    $current = mysqli_fetch_assoc($check);
    if ($current['agentra_role'] === 'owner') {
        jsonResponse(403, 'The company owner cannot be deactivated');
        return;
    }

    if (mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET account_status = 'inactive' WHERE user_id = '$target_user_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Team member deactivated successfully');
    } else {
        jsonResponse(500, 'Failed to deactivate team member', ['error' => mysqli_error($conn)]);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$target_user_id  = $parts[4] ?? '';
$team_sub_action = $parts[5] ?? '';

if ($target_user_id === '') {
    switch ($method) {
        case 'GET':
            listTeam($conn, $company_id);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            inviteTeamMember($conn, $company_id, $input);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} elseif ($team_sub_action === 'role') {
    if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    updateTeamMemberRole($conn, $company_id, $target_user_id, $input);
} elseif ($team_sub_action === '') {
    if ($method !== 'DELETE') { jsonResponse(405, 'Method Not Allowed'); }
    $requesting_user_id = $authUser['sub'] ?? $authUser['user_id'] ?? null;
    deactivateTeamMember($conn, $company_id, $target_user_id, $requesting_user_id);
} else {
    jsonResponse(404, 'Route not found');
}
