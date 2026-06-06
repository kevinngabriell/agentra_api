<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- GET ALL ---
function getAllInsurers($conn, $company_id, $is_active = null){
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $where = "company_id = '$company_id'";

    if ($is_active !== null) {
        $where .= " AND is_active = " . ($is_active ? 1 : 0);
    }

    $result = mysqli_query($conn, "SELECT insurer_id, name, short_name, agent_code, is_primary, is_active, notes, created_at FROM " . APP_SCHEMA . ".insurers WHERE $where ORDER BY is_primary DESC, name ASC");

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Insurers found', ['data' => $data]);
    } else {
        jsonResponse(404, 'No insurers found');
    }
}

// --- CREATE ---
function createInsurer($conn, $input, $username, $company_id){
    foreach (['name', 'short_name'] as $field) {
        if (empty($input[$field])) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $name       = trim(mysqli_real_escape_string($conn, $input['name']));
    $short_name = strtoupper(trim(mysqli_real_escape_string($conn, $input['short_name'])));
    $agent_code = isset($input['agent_code']) ? trim(mysqli_real_escape_string($conn, $input['agent_code'])) : null;
    $notes      = isset($input['notes'])      ? trim(mysqli_real_escape_string($conn, $input['notes']))      : null;
    $is_primary = !empty($input['is_primary']) ? 1 : 0;

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".insurers WHERE company_id = '$company_id' AND short_name = '$short_name' LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'An insurer with this short_name already exists for your company');
        return;
    }

    if ($is_primary) {
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".insurers SET is_primary = 0 WHERE company_id = '$company_id'");
    }

    $insurer_id     = 'ins_' . uniqid();
    $now            = date('Y-m-d H:i:s');
    $agent_code_sql = $agent_code ? "'$agent_code'" : 'NULL';
    $notes_sql      = $notes      ? "'$notes'"      : 'NULL';

    $sql = "INSERT INTO " . APP_SCHEMA . ".insurers (insurer_id, company_id, name, short_name, agent_code, is_primary, is_active, notes, created_by, created_at)
        VALUES ('$insurer_id', '$company_id', '$name', '$short_name', $agent_code_sql, $is_primary, 1, $notes_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Insurer created successfully', ['insurer_id' => $insurer_id]);
    } else {
        jsonResponse(500, 'Failed to create insurer', ['error' => mysqli_error($conn)]);
    }
}

// --- GET DETAIL ---
function getDetailInsurer($conn, $insurer_id, $company_id){
    if (!$insurer_id) {
        jsonResponse(400, 'insurer_id is required');
        return;
    }

    $insurer_id = mysqli_real_escape_string($conn, $insurer_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$insurer_id' AND company_id = '$company_id' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Insurer not found');
        return;
    }

    jsonResponse(200, 'Insurer found', mysqli_fetch_assoc($result));
}

// --- UPDATE ---
function updateInsurer($conn, $insurer_id, $input, $username, $company_id){
    if (!$insurer_id) {
        jsonResponse(400, 'insurer_id is required');
        return;
    }

    $insurer_id = mysqli_real_escape_string($conn, $insurer_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$insurer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Insurer not found');
        return;
    }

    $updates = [];

    if (isset($input['name'])) {
        $name = trim(mysqli_real_escape_string($conn, $input['name']));
        if ($name === '') {
            jsonResponse(400, 'name cannot be empty');
            return;
        }
        $updates[] = "name = '$name'";
    }

    if (isset($input['short_name'])) {
        $short_name = strtoupper(trim(mysqli_real_escape_string($conn, $input['short_name'])));
        if ($short_name === '') {
            jsonResponse(400, 'short_name cannot be empty');
            return;
        }
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".insurers WHERE company_id = '$company_id' AND short_name = '$short_name' AND insurer_id != '$insurer_id' LIMIT 1");
        if (mysqli_num_rows($dup) > 0) {
            jsonResponse(409, 'An insurer with this short_name already exists for your company');
            return;
        }
        $updates[] = "short_name = '$short_name'";
    }

    if (isset($input['agent_code'])) {
        $agent_code = trim(mysqli_real_escape_string($conn, $input['agent_code']));
        $updates[] = "agent_code = " . ($agent_code !== '' ? "'$agent_code'" : 'NULL');
    }

    if (isset($input['notes'])) {
        $notes = trim(mysqli_real_escape_string($conn, $input['notes']));
        $updates[] = "notes = " . ($notes !== '' ? "'$notes'" : 'NULL');
    }

    if (isset($input['is_primary'])) {
        $is_primary = !empty($input['is_primary']) ? 1 : 0;
        if ($is_primary) {
            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".insurers SET is_primary = 0 WHERE company_id = '$company_id'");
        }
        $updates[] = "is_primary = $is_primary";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".insurers SET " . implode(', ', $updates) . " WHERE insurer_id = '$insurer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Insurer updated successfully');
    } else {
        jsonResponse(500, 'Failed to update insurer', ['error' => mysqli_error($conn)]);
    }
}

// --- SOFT DELETE ---
function deleteInsurer($conn, $insurer_id, $username, $company_id){
    if (!$insurer_id) {
        jsonResponse(400, 'insurer_id is required');
        return;
    }

    $insurer_id = mysqli_real_escape_string($conn, $insurer_id);

    $check = mysqli_query($conn, "SELECT is_active, is_primary FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$insurer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Insurer not found');
        return;
    }

    $row = mysqli_fetch_assoc($check);
    if (!$row['is_active']) {
        jsonResponse(400, 'Insurer is already inactive');
        return;
    }
    if ($row['is_primary']) {
        jsonResponse(400, 'Cannot deactivate the primary insurer. Set another insurer as primary first.');
        return;
    }

    $now = date('Y-m-d H:i:s');
    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".insurers SET is_active = 0, updated_by = '$username', updated_at = '$now' WHERE insurer_id = '$insurer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Insurer deactivated successfully');
    } else {
        jsonResponse(500, 'Failed to deactivate insurer', ['error' => mysqli_error($conn)]);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

$insurer_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($insurer_id) {
        switch ($method) {
            case 'GET':
                getDetailInsurer($conn, $insurer_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateInsurer($conn, $insurer_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteInsurer($conn, $insurer_id, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                $is_active = isset($_GET['is_active'])
                    ? filter_var($_GET['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                    : null;
                getAllInsurers($conn, $company_id, $is_active);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createInsurer($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
