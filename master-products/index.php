<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- GET ALL ---
function getAllMasterProducts($conn, $company_id) {
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $result = mysqli_query($conn,
        "SELECT product_id, product_code, product_name, commission_rate, policy_prefixes, is_active,
                created_by, created_at, updated_by, updated_at
         FROM " . APP_SCHEMA . ".master_products
         WHERE company_id = '$company_id'
         ORDER BY product_name ASC"
    );

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Master products found', ['data' => mysqli_fetch_all($result, MYSQLI_ASSOC)]);
    } else {
        jsonResponse(404, 'No master products found');
    }
}

// --- CREATE ---
function createMasterProduct($conn, $input, $username, $company_id) {
    foreach (['product_code', 'product_name', 'commission_rate'] as $field) {
        if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $product_code = strtolower(trim(mysqli_real_escape_string($conn, $input['product_code'])));
    $product_name = trim(mysqli_real_escape_string($conn, $input['product_name']));
    $commission_rate = $input['commission_rate'];

    if (!is_numeric($commission_rate) || $commission_rate < 0 || $commission_rate > 100) {
        jsonResponse(400, 'commission_rate must be a number between 0 and 100');
        return;
    }
    $commission_rate = number_format((float)$commission_rate, 2, '.', '');

    $policy_prefixes_sql = 'NULL';
    if (isset($input['policy_prefixes']) && trim($input['policy_prefixes']) !== '') {
        $policy_prefixes_sql = "'" . mysqli_real_escape_string($conn, trim($input['policy_prefixes'])) . "'";
    }

    $dup = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".master_products
         WHERE company_id = '$company_id' AND product_code = '$product_code' LIMIT 1"
    );
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'A master product with this code already exists');
        return;
    }

    $product_id = 'prod_' . uniqid();
    $now        = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".master_products
                (product_id, company_id, product_code, product_name, commission_rate, policy_prefixes, created_by, created_at)
            VALUES ('$product_id', '$company_id', '$product_code', '$product_name', $commission_rate, $policy_prefixes_sql, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        jsonResponse(201, 'Master product created', ['product_id' => $product_id]);
    } else {
        jsonResponse(500, 'Failed to create master product', ['error' => mysqli_error($conn)]);
    }
}

// --- GET DETAIL ---
function getMasterProductDetail($conn, $product_id, $company_id) {
    $product_id = mysqli_real_escape_string($conn, $product_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $result = mysqli_query($conn,
        "SELECT product_id, product_code, product_name, commission_rate, policy_prefixes, is_active,
                created_by, created_at, updated_by, updated_at
         FROM " . APP_SCHEMA . ".master_products
         WHERE product_id = '$product_id' AND company_id = '$company_id' LIMIT 1"
    );

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Master product not found');
        return;
    }

    jsonResponse(200, 'Master product found', mysqli_fetch_assoc($result));
}

// --- UPDATE ---
function updateMasterProduct($conn, $product_id, $input, $username, $company_id) {
    $product_id = mysqli_real_escape_string($conn, $product_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT product_code FROM " . APP_SCHEMA . ".master_products
         WHERE product_id = '$product_id' AND company_id = '$company_id' LIMIT 1"
    );
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Master product not found');
        return;
    }
    $current = mysqli_fetch_assoc($check);
    $updates = [];

    if (isset($input['product_code'])) {
        $code = strtolower(trim(mysqli_real_escape_string($conn, $input['product_code'])));
        if ($code !== $current['product_code']) {
            $dup = mysqli_query($conn,
                "SELECT 1 FROM " . APP_SCHEMA . ".master_products
                 WHERE company_id = '$company_id' AND product_code = '$code' AND product_id != '$product_id' LIMIT 1"
            );
            if (mysqli_num_rows($dup) > 0) {
                jsonResponse(409, 'A master product with this code already exists');
                return;
            }
        }
        $updates[] = "product_code = '$code'";
    }

    if (isset($input['product_name'])) {
        $name = trim(mysqli_real_escape_string($conn, $input['product_name']));
        $updates[] = "product_name = '$name'";
    }

    if (isset($input['commission_rate'])) {
        $rate = $input['commission_rate'];
        if (!is_numeric($rate) || $rate < 0 || $rate > 100) {
            jsonResponse(400, 'commission_rate must be a number between 0 and 100');
            return;
        }
        $updates[] = "commission_rate = " . number_format((float)$rate, 2, '.', '');
    }

    if (array_key_exists('policy_prefixes', $input)) {
        $val = trim($input['policy_prefixes'] ?? '');
        $updates[] = $val !== ''
            ? "policy_prefixes = '" . mysqli_real_escape_string($conn, $val) . "'"
            : "policy_prefixes = NULL";
    }

    if (isset($input['is_active'])) {
        $updates[] = "is_active = " . ($input['is_active'] ? 1 : 0);
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".master_products SET " . implode(', ', $updates) . " WHERE product_id = '$product_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Master product updated successfully');
    } else {
        jsonResponse(500, 'Failed to update master product', ['error' => mysqli_error($conn)]);
    }
}

// --- DELETE ---
function deleteMasterProduct($conn, $product_id, $company_id) {
    $product_id = mysqli_real_escape_string($conn, $product_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".master_products WHERE product_id = '$product_id' AND company_id = '$company_id' LIMIT 1"
    );
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Master product not found');
        return;
    }

    if (mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".master_products WHERE product_id = '$product_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Master product deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete master product', ['error' => mysqli_error($conn)]);
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

$product_id = !empty($action) ? $action : null;

try {
    $conn = getConn();

    if ($product_id) {
        switch ($method) {
            case 'GET':
                getMasterProductDetail($conn, $product_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateMasterProduct($conn, $product_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteMasterProduct($conn, $product_id, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getAllMasterProducts($conn, $company_id);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createMasterProduct($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
