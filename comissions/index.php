<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

const VALID_PRODUCT_TYPES = ['fire', 'motorcycle', 'car', 'travel', 'cargo', 'other'];

// --- GET ALL ---
function getAllCommissionRates($conn, $company_id, $insurer_id = null) {
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $where = "i.company_id = '$company_id'";
    if ($insurer_id !== null) {
        $insurer_id = mysqli_real_escape_string($conn, $insurer_id);
        $where .= " AND r.insurer_id = '$insurer_id'";
    }

    $result = mysqli_query($conn,
        "SELECT r.rate_id, r.insurer_id, i.name AS insurer_name, i.short_name AS insurer_short_name,
                r.product_type, r.rate_percent, r.created_by, r.created_at, r.updated_by, r.updated_at
         FROM " . APP_SCHEMA . ".insurer_commission_rates r
         JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = r.insurer_id
         WHERE $where
         ORDER BY i.name ASC, r.product_type ASC"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Commission rates found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No commission rates found');
    }
}

// --- CREATE ---
function createCommissionRate($conn, $input, $username, $company_id) {
    foreach (['insurer_id', 'product_type', 'rate_percent'] as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $insurer_id   = mysqli_real_escape_string($conn, $input['insurer_id']);
    $product_type = strtolower(trim(mysqli_real_escape_string($conn, $input['product_type'])));
    $rate_percent = $input['rate_percent'];

    if (!in_array($product_type, VALID_PRODUCT_TYPES, true)) {
        jsonResponse(400, 'product_type must be one of: ' . implode(', ', VALID_PRODUCT_TYPES));
        return;
    }

    if (!is_numeric($rate_percent) || $rate_percent < 0 || $rate_percent > 100) {
        jsonResponse(400, 'rate_percent must be a number between 0 and 100');
        return;
    }

    $rate_percent = number_format((float)$rate_percent, 2, '.', '');

    // Verify insurer belongs to this company
    $ins = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$insurer_id' AND company_id = '$company_id' LIMIT 1"
    );
    if (mysqli_num_rows($ins) === 0) {
        jsonResponse(404, 'Insurer not found');
        return;
    }

    // Unique constraint check
    $dup = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".insurer_commission_rates
         WHERE insurer_id = '$insurer_id' AND product_type = '$product_type' LIMIT 1"
    );
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'A commission rate for this insurer and product type already exists');
        return;
    }

    $rate_id = 'rate_' . uniqid();
    $now     = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".insurer_commission_rates
                (rate_id, insurer_id, product_type, rate_percent, created_by, created_at)
            VALUES ('$rate_id', '$insurer_id', '$product_type', $rate_percent, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Commission rate created successfully', ['rate_id' => $rate_id]);
    } else {
        jsonResponse(500, 'Failed to create commission rate', ['error' => mysqli_error($conn)]);
    }
}

// --- GET DETAIL ---
function getDetailCommissionRate($conn, $rate_id, $company_id) {
    $rate_id    = mysqli_real_escape_string($conn, $rate_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $result = mysqli_query($conn,
        "SELECT r.rate_id, r.insurer_id, i.name AS insurer_name, i.short_name AS insurer_short_name,
                r.product_type, r.rate_percent, r.created_by, r.created_at, r.updated_by, r.updated_at
         FROM " . APP_SCHEMA . ".insurer_commission_rates r
         JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = r.insurer_id
         WHERE r.rate_id = '$rate_id' AND i.company_id = '$company_id'
         LIMIT 1"
    );

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Commission rate not found');
        return;
    }

    jsonResponse(200, 'Commission rate found', mysqli_fetch_assoc($result));
}

// --- UPDATE ---
function updateCommissionRate($conn, $rate_id, $input, $username, $company_id) {
    $rate_id    = mysqli_real_escape_string($conn, $rate_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT r.insurer_id, r.product_type FROM " . APP_SCHEMA . ".insurer_commission_rates r
         JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = r.insurer_id
         WHERE r.rate_id = '$rate_id' AND i.company_id = '$company_id' LIMIT 1"
    );
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Commission rate not found');
        return;
    }

    $current    = mysqli_fetch_assoc($check);
    $updates    = [];

    if (isset($input['product_type'])) {
        $product_type = strtolower(trim(mysqli_real_escape_string($conn, $input['product_type'])));
        if (!in_array($product_type, VALID_PRODUCT_TYPES, true)) {
            jsonResponse(400, 'product_type must be one of: ' . implode(', ', VALID_PRODUCT_TYPES));
            return;
        }
        // Check uniqueness if product_type changes
        if ($product_type !== $current['product_type']) {
            $dup = mysqli_query($conn,
                "SELECT 1 FROM " . APP_SCHEMA . ".insurer_commission_rates
                 WHERE insurer_id = '{$current['insurer_id']}' AND product_type = '$product_type'
                 AND rate_id != '$rate_id' LIMIT 1"
            );
            if (mysqli_num_rows($dup) > 0) {
                jsonResponse(409, 'A commission rate for this insurer and product type already exists');
                return;
            }
        }
        $updates[] = "product_type = '$product_type'";
    }

    if (isset($input['rate_percent'])) {
        $rate_percent = $input['rate_percent'];
        if (!is_numeric($rate_percent) || $rate_percent < 0 || $rate_percent > 100) {
            jsonResponse(400, 'rate_percent must be a number between 0 and 100');
            return;
        }
        $rate_percent = number_format((float)$rate_percent, 2, '.', '');
        $updates[] = "rate_percent = $rate_percent";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".insurer_commission_rates SET " . implode(', ', $updates) . " WHERE rate_id = '$rate_id'")) {
        jsonResponse(200, 'Commission rate updated successfully');
    } else {
        jsonResponse(500, 'Failed to update commission rate', ['error' => mysqli_error($conn)]);
    }
}

// --- DELETE ---
function deleteCommissionRate($conn, $rate_id, $company_id) {
    $rate_id    = mysqli_real_escape_string($conn, $rate_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT r.rate_id FROM " . APP_SCHEMA . ".insurer_commission_rates r
         JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = r.insurer_id
         WHERE r.rate_id = '$rate_id' AND i.company_id = '$company_id' LIMIT 1"
    );
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Commission rate not found');
        return;
    }

    if (mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".insurer_commission_rates WHERE rate_id = '$rate_id'")) {
        jsonResponse(200, 'Commission rate deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete commission rate', ['error' => mysqli_error($conn)]);
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

$rate_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($rate_id) {
        switch ($method) {
            case 'GET':
                getDetailCommissionRate($conn, $rate_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateCommissionRate($conn, $rate_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteCommissionRate($conn, $rate_id, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                $insurer_id = isset($_GET['insurer_id']) && $_GET['insurer_id'] !== '' ? $_GET['insurer_id'] : null;
                getAllCommissionRates($conn, $company_id, $insurer_id);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createCommissionRate($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
