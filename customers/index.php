<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';

// --- CREATE ---
function createCustomer($conn, $input, $username, $company_id){
    $required = ['customer_type', 'display_name'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $customer_type = mysqli_real_escape_string($conn, $input['customer_type']);
    if (!in_array($customer_type, ['individual', 'company'], true)) {
        jsonResponse(400, 'Invalid customer_type. Must be individual or company');
        return;
    }

    $display_name = trim(mysqli_real_escape_string($conn, $input['display_name']));
    if ($display_name === '') {
        jsonResponse(400, 'display_name cannot be empty');
        return;
    }

    $status = isset($input['status']) ? mysqli_real_escape_string($conn, $input['status']) : 'active';
    if (!in_array($status, ['active', 'inactive', 'lapsed'], true)) {
        $status = 'active';
    }

    $source = isset($input['source']) ? mysqli_real_escape_string($conn, $input['source']) : 'direct';
    if (!in_array($source, ['direct', 'referral'], true)) {
        $source = 'direct';
    }

    $notes = isset($input['notes']) ? trim(mysqli_real_escape_string($conn, $input['notes'])) : null;
    $referred_by_agent_id = isset($input['referred_by_agent_id']) ? mysqli_real_escape_string($conn, $input['referred_by_agent_id']) : null;

    if ($customer_type === 'individual') {
        $nik = isset($input['nik']) ? trim(mysqli_real_escape_string($conn, $input['nik'])) : null;
        if (!$nik || strlen($nik) !== 16 || !ctype_digit($nik)) {
            jsonResponse(400, 'NIK is required for individual and must be 16 digits');
            return;
        }

        $date_of_birth     = isset($input['date_of_birth'])     ? mysqli_real_escape_string($conn, $input['date_of_birth'])              : null;
        $personal_phone    = isset($input['personal_phone'])    ? trim(mysqli_real_escape_string($conn, $input['personal_phone']))        : null;
        $personal_whatsapp = isset($input['personal_whatsapp']) ? trim(mysqli_real_escape_string($conn, $input['personal_whatsapp']))     : null;
        $personal_email    = isset($input['personal_email'])    ? trim(mysqli_real_escape_string($conn, $input['personal_email']))        : null;
        $personal_address  = isset($input['personal_address'])  ? trim(mysqli_real_escape_string($conn, $input['personal_address']))      : null;
        $npwp_personal     = isset($input['npwp_personal'])     ? trim(mysqli_real_escape_string($conn, $input['npwp_personal']))         : null;

        $customer_id = 'cust_' . uniqid();
        $now = date('Y-m-d H:i:s');

        $insert = "INSERT INTO " . APP_SCHEMA . ".customers
            (customer_id, company_id, customer_type, status, source, referred_by_agent_id, display_name, notes, nik, date_of_birth, personal_phone, personal_whatsapp, personal_email, personal_address, npwp_personal, created_by, created_at)
            VALUES
            ('$customer_id', '$company_id', '$customer_type', '$status', '$source', " . ($referred_by_agent_id ? "'$referred_by_agent_id'" : "NULL") . ", '$display_name', " . ($notes ? "'$notes'" : "NULL") . ", '$nik', " . ($date_of_birth ? "'$date_of_birth'" : "NULL") . ", " . ($personal_phone ? "'$personal_phone'" : "NULL") . ", " . ($personal_whatsapp ? "'$personal_whatsapp'" : "NULL") . ", " . ($personal_email ? "'$personal_email'" : "NULL") . ", " . ($personal_address ? "'$personal_address'" : "NULL") . ", " . ($npwp_personal ? "'$npwp_personal'" : "NULL") . ", '$username', '$now')";
    } else {
        $company_legal_name = isset($input['company_legal_name']) ? trim(mysqli_real_escape_string($conn, $input['company_legal_name'])) : null;
        if (!$company_legal_name) {
            jsonResponse(400, 'company_legal_name is required for company type');
            return;
        }

        $npwp_company = isset($input['npwp_company']) ? trim(mysqli_real_escape_string($conn, $input['npwp_company'])) : null;
        if (!$npwp_company) {
            jsonResponse(400, 'npwp_company is required for company type');
            return;
        }

        $pic_name     = isset($input['pic_name'])     ? trim(mysqli_real_escape_string($conn, $input['pic_name']))     : null;
        $pic_phone    = isset($input['pic_phone'])    ? trim(mysqli_real_escape_string($conn, $input['pic_phone']))    : null;
        $pic_whatsapp = isset($input['pic_whatsapp']) ? trim(mysqli_real_escape_string($conn, $input['pic_whatsapp'])) : null;

        if (!$pic_name || !$pic_phone || !$pic_whatsapp) {
            jsonResponse(400, 'pic_name, pic_phone, and pic_whatsapp are required for company type');
            return;
        }

        $business_type       = isset($input['business_type'])       ? trim(mysqli_real_escape_string($conn, $input['business_type']))       : null;
        $nib                 = isset($input['nib'])                 ? trim(mysqli_real_escape_string($conn, $input['nib']))                 : null;
        $operational_address = isset($input['operational_address']) ? trim(mysqli_real_escape_string($conn, $input['operational_address'])) : null;
        $legal_address       = isset($input['legal_address'])       ? trim(mysqli_real_escape_string($conn, $input['legal_address']))       : null;
        $company_phone       = isset($input['company_phone'])       ? trim(mysqli_real_escape_string($conn, $input['company_phone']))       : null;
        $company_email       = isset($input['company_email'])       ? trim(mysqli_real_escape_string($conn, $input['company_email']))       : null;
        $pic_role            = isset($input['pic_role'])            ? trim(mysqli_real_escape_string($conn, $input['pic_role']))            : null;
        $pic_email           = isset($input['pic_email'])           ? trim(mysqli_real_escape_string($conn, $input['pic_email']))           : null;

        $customer_id = 'cust_' . uniqid();
        $now = date('Y-m-d H:i:s');

        $insert = "INSERT INTO " . APP_SCHEMA . ".customers
            (customer_id, company_id, customer_type, status, source, referred_by_agent_id, display_name, notes, company_legal_name, business_type, npwp_company, nib, operational_address, legal_address, company_phone, company_email, pic_name, pic_role, pic_phone, pic_whatsapp, pic_email, created_by, created_at)
            VALUES
            ('$customer_id', '$company_id', '$customer_type', '$status', '$source', " . ($referred_by_agent_id ? "'$referred_by_agent_id'" : "NULL") . ", '$display_name', " . ($notes ? "'$notes'" : "NULL") . ", '$company_legal_name', " . ($business_type ? "'$business_type'" : "NULL") . ", '$npwp_company', " . ($nib ? "'$nib'" : "NULL") . ", " . ($operational_address ? "'$operational_address'" : "NULL") . ", " . ($legal_address ? "'$legal_address'" : "NULL") . ", " . ($company_phone ? "'$company_phone'" : "NULL") . ", " . ($company_email ? "'$company_email'" : "NULL") . ", '$pic_name', " . ($pic_role ? "'$pic_role'" : "NULL") . ", '$pic_phone', '$pic_whatsapp', " . ($pic_email ? "'$pic_email'" : "NULL") . ", '$username', '$now')";
    }

    if (mysqli_query($conn, $insert)) {
        jsonResponse(201, 'Customer created successfully', ['customer_id' => $customer_id]);
    } else {
        jsonResponse(500, 'Failed to create customer', ['error' => mysqli_error($conn)]);
    }
}

// --- GET ALL ---
function getAllCustomers($conn, $company_id, $params = '', $customer_type = '', $status = '', $page = 1, $limit = 10){
    $params        = mysqli_real_escape_string($conn, $params);
    $customer_type = mysqli_real_escape_string($conn, $customer_type);
    $status        = mysqli_real_escape_string($conn, $status);
    $page          = max(1, (int)$page);
    $limit         = min(100, max(1, (int)$limit));
    $offset        = ($page - 1) * $limit;

    $where = "company_id = '$company_id'";

    if ($params) {
        $where .= " AND (display_name LIKE '%$params%' OR company_legal_name LIKE '%$params%' OR nik LIKE '%$params%' OR npwp_company LIKE '%$params%')";
    }

    if ($customer_type && in_array($customer_type, ['individual', 'company'], true)) {
        $where .= " AND customer_type = '$customer_type'";
    }

    if ($status && in_array($status, ['active', 'inactive', 'lapsed'], true)) {
        $where .= " AND status = '$status'";
    }

    $query       = "SELECT customer_id, customer_type, display_name, status, source, company_legal_name, nik, npwp_company, created_at FROM " . APP_SCHEMA . ".customers WHERE $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $countQuery  = "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".customers WHERE $where";

    $result      = mysqli_query($conn, $query);
    $countResult = mysqli_query($conn, $countQuery);
    $total       = mysqli_fetch_assoc($countResult)['total'];

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Customers found', [
            'data' => $data,
            'pagination' => [
                'total'       => (int)$total,
                'page'        => (int)$page,
                'limit'       => (int)$limit,
                'total_pages' => ceil($total / $limit),
            ]
        ]);
    } else {
        jsonResponse(404, 'No customers found');
    }
}

// --- GET DETAIL (with linked policies summary) ---
function getDetailCustomer($conn, $customer_id, $company_id){
    if (!$customer_id) {
        jsonResponse(400, 'customer_id is required');
        return;
    }

    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    $result = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    $data = mysqli_fetch_assoc($result);

    // Linked policies summary
    $policies_total = 0;
    $recent_policies = [];
    $count_res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".policies WHERE customer_id = '$customer_id' AND company_id = '$company_id'");
    if ($count_res) {
        $policies_total = (int)mysqli_fetch_assoc($count_res)['total'];
    }
    $policy_res = mysqli_query($conn, "SELECT policy_id, policy_number, renewal_status, created_at FROM " . APP_SCHEMA . ".policies WHERE customer_id = '$customer_id' AND company_id = '$company_id' ORDER BY created_at DESC LIMIT 5");
    if ($policy_res) {
        $recent_policies = mysqli_fetch_all($policy_res, MYSQLI_ASSOC);
    }

    $data['policies_summary'] = [
        'total'  => $policies_total,
        'recent' => $recent_policies,
    ];

    jsonResponse(200, 'Customer found', $data);
}

// --- UPDATE PROFILE FIELDS ---
function updateCustomer($conn, $customer_id, $input, $company_id){
    if (!$customer_id) {
        jsonResponse(400, 'customer_id is required');
        return;
    }

    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    $check = mysqli_query($conn, "SELECT customer_type FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    $customer_data = mysqli_fetch_assoc($check);
    $customer_type = $customer_data['customer_type'];

    $updates = [];

    if (isset($input['display_name'])) {
        $name = trim(mysqli_real_escape_string($conn, $input['display_name']));
        if ($name === '') {
            jsonResponse(400, 'display_name cannot be empty');
            return;
        }
        $updates[] = "display_name = '$name'";
    }

    if (isset($input['notes'])) {
        $notes = trim(mysqli_real_escape_string($conn, $input['notes']));
        $updates[] = "notes = " . ($notes !== '' ? "'$notes'" : "NULL");
    }

    if (isset($input['source'])) {
        $source = mysqli_real_escape_string($conn, $input['source']);
        if (in_array($source, ['direct', 'referral'], true)) {
            $updates[] = "source = '$source'";
        }
    }

    if (isset($input['referred_by_agent_id'])) {
        $referred_by = mysqli_real_escape_string($conn, $input['referred_by_agent_id']);
        $updates[] = "referred_by_agent_id = " . ($referred_by !== '' ? "'$referred_by'" : "NULL");
    }

    if ($customer_type === 'individual') {
        if (isset($input['date_of_birth'])) {
            $dob = mysqli_real_escape_string($conn, $input['date_of_birth']);
            $updates[] = "date_of_birth = " . ($dob !== '' ? "'$dob'" : "NULL");
        }
        if (isset($input['personal_phone'])) {
            $phone = trim(mysqli_real_escape_string($conn, $input['personal_phone']));
            $updates[] = "personal_phone = " . ($phone !== '' ? "'$phone'" : "NULL");
        }
        if (isset($input['personal_whatsapp'])) {
            $whatsapp = trim(mysqli_real_escape_string($conn, $input['personal_whatsapp']));
            $updates[] = "personal_whatsapp = " . ($whatsapp !== '' ? "'$whatsapp'" : "NULL");
        }
        if (isset($input['personal_email'])) {
            $email = trim(mysqli_real_escape_string($conn, $input['personal_email']));
            $updates[] = "personal_email = " . ($email !== '' ? "'$email'" : "NULL");
        }
        if (isset($input['personal_address'])) {
            $address = trim(mysqli_real_escape_string($conn, $input['personal_address']));
            $updates[] = "personal_address = " . ($address !== '' ? "'$address'" : "NULL");
        }
        if (isset($input['npwp_personal'])) {
            $npwp = trim(mysqli_real_escape_string($conn, $input['npwp_personal']));
            $updates[] = "npwp_personal = " . ($npwp !== '' ? "'$npwp'" : "NULL");
        }
    } else {
        if (isset($input['company_legal_name'])) {
            $legal_name = trim(mysqli_real_escape_string($conn, $input['company_legal_name']));
            if ($legal_name === '') {
                jsonResponse(400, 'company_legal_name cannot be empty');
                return;
            }
            $updates[] = "company_legal_name = '$legal_name'";
        }
        if (isset($input['business_type'])) {
            $btype = trim(mysqli_real_escape_string($conn, $input['business_type']));
            $updates[] = "business_type = " . ($btype !== '' ? "'$btype'" : "NULL");
        }
        if (isset($input['npwp_company'])) {
            $npwp = trim(mysqli_real_escape_string($conn, $input['npwp_company']));
            if ($npwp === '') {
                jsonResponse(400, 'npwp_company cannot be empty');
                return;
            }
            $updates[] = "npwp_company = '$npwp'";
        }
        if (isset($input['nib'])) {
            $nib = trim(mysqli_real_escape_string($conn, $input['nib']));
            $updates[] = "nib = " . ($nib !== '' ? "'$nib'" : "NULL");
        }
        if (isset($input['operational_address'])) {
            $op_addr = trim(mysqli_real_escape_string($conn, $input['operational_address']));
            $updates[] = "operational_address = " . ($op_addr !== '' ? "'$op_addr'" : "NULL");
        }
        if (isset($input['legal_address'])) {
            $legal_addr = trim(mysqli_real_escape_string($conn, $input['legal_address']));
            $updates[] = "legal_address = " . ($legal_addr !== '' ? "'$legal_addr'" : "NULL");
        }
        if (isset($input['company_phone'])) {
            $cphone = trim(mysqli_real_escape_string($conn, $input['company_phone']));
            $updates[] = "company_phone = " . ($cphone !== '' ? "'$cphone'" : "NULL");
        }
        if (isset($input['company_email'])) {
            $cemail = trim(mysqli_real_escape_string($conn, $input['company_email']));
            $updates[] = "company_email = " . ($cemail !== '' ? "'$cemail'" : "NULL");
        }
        if (isset($input['pic_name'])) {
            $pic = trim(mysqli_real_escape_string($conn, $input['pic_name']));
            if ($pic === '') {
                jsonResponse(400, 'pic_name cannot be empty');
                return;
            }
            $updates[] = "pic_name = '$pic'";
        }
        if (isset($input['pic_role'])) {
            $pic_role = trim(mysqli_real_escape_string($conn, $input['pic_role']));
            $updates[] = "pic_role = " . ($pic_role !== '' ? "'$pic_role'" : "NULL");
        }
        if (isset($input['pic_phone'])) {
            $pic_phone = trim(mysqli_real_escape_string($conn, $input['pic_phone']));
            if ($pic_phone === '') {
                jsonResponse(400, 'pic_phone cannot be empty');
                return;
            }
            $updates[] = "pic_phone = '$pic_phone'";
        }
        if (isset($input['pic_whatsapp'])) {
            $pic_wa = trim(mysqli_real_escape_string($conn, $input['pic_whatsapp']));
            if ($pic_wa === '') {
                jsonResponse(400, 'pic_whatsapp cannot be empty');
                return;
            }
            $updates[] = "pic_whatsapp = '$pic_wa'";
        }
        if (isset($input['pic_email'])) {
            $pic_email = trim(mysqli_real_escape_string($conn, $input['pic_email']));
            $updates[] = "pic_email = " . ($pic_email !== '' ? "'$pic_email'" : "NULL");
        }
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".customers SET " . implode(', ', $updates) . " WHERE customer_id = '$customer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Customer updated successfully');
    } else {
        jsonResponse(500, 'Failed to update customer', ['error' => mysqli_error($conn)]);
    }
}

// --- UPDATE STATUS ---
function updateCustomerStatus($conn, $customer_id, $company_id, $input){
    if (!$customer_id) {
        jsonResponse(400, 'customer_id is required');
        return;
    }

    $customer_id = mysqli_real_escape_string($conn, $customer_id);
    $status = isset($input['status']) ? mysqli_real_escape_string($conn, $input['status']) : null;

    if (!$status || !in_array($status, ['active', 'inactive', 'lapsed'], true)) {
        jsonResponse(400, 'status is required and must be active, inactive, or lapsed');
        return;
    }

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    $now = date('Y-m-d H:i:s');
    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".customers SET status = '$status', updated_at = '$now' WHERE customer_id = '$customer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Customer status updated successfully');
    } else {
        jsonResponse(500, 'Failed to update customer status', ['error' => mysqli_error($conn)]);
    }
}

// --- DELETE ---
function deleteCustomer($conn, $customer_id, $company_id){
    if (!$customer_id) {
        jsonResponse(400, 'customer_id is required');
        return;
    }

    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    if (mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Customer deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete customer', ['error' => mysqli_error($conn)]);
    }
}

// --- GET FOLLOW-UPS ---
function getFollowUps($conn, $customer_id, $company_id){
    if (!$customer_id) {
        jsonResponse(400, 'customer_id is required');
        return;
    }

    $customer_id = mysqli_real_escape_string($conn, $customer_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $result      = mysqli_query($conn, "SELECT fl.* FROM " . APP_SCHEMA . ".follow_up_logs fl
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = fl.policy_id
        WHERE p.customer_id = '$customer_id' AND p.company_id = '$company_id'
        ORDER BY fl.followup_date DESC LIMIT $limit OFFSET $offset");
    $countResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".follow_up_logs fl
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = fl.policy_id
        WHERE p.customer_id = '$customer_id' AND p.company_id = '$company_id'");

    if (!$result) {
        jsonResponse(500, 'Failed to fetch follow-ups', ['error' => mysqli_error($conn)]);
        return;
    }

    $total = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;
    $data  = mysqli_fetch_all($result, MYSQLI_ASSOC);

    jsonResponse(200, 'Follow-ups retrieved', [
        'data' => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
        ]
    ]);
}

// --- IMPORT (CSV) ---
function importCustomers($conn, $company_id, $username){
    if (empty($_FILES['file'])) {
        jsonResponse(400, 'CSV file is required (field name: file)');
        return;
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, 'File upload error code: ' . $file['error']);
        return;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        jsonResponse(400, 'Only CSV files are supported');
        return;
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        jsonResponse(500, 'Failed to read uploaded file');
        return;
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        jsonResponse(400, 'Empty or invalid CSV file');
        return;
    }

    $headers = array_map('trim', $headers);
    foreach (['customer_type', 'display_name'] as $required) {
        if (!in_array($required, $headers, true)) {
            fclose($handle);
            jsonResponse(400, "Missing required CSV column: $required");
            return;
        }
    }

    $now      = date('Y-m-d H:i:s');
    $inserted = 0;
    $failed   = [];
    $row_num  = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $row_num++;
        if (count($row) !== count($headers)) {
            $failed[] = ['row' => $row_num, 'reason' => 'Column count mismatch'];
            continue;
        }

        $d = array_combine($headers, $row);

        $customer_type = trim($d['customer_type'] ?? '');
        $display_name  = trim($d['display_name'] ?? '');

        if (!in_array($customer_type, ['individual', 'company'], true)) {
            $failed[] = ['row' => $row_num, 'reason' => 'Invalid customer_type'];
            continue;
        }
        if ($display_name === '') {
            $failed[] = ['row' => $row_num, 'reason' => 'display_name is empty'];
            continue;
        }

        $customer_id  = 'cust_' . uniqid();
        $ct_s         = mysqli_real_escape_string($conn, $customer_type);
        $dn_s         = mysqli_real_escape_string($conn, $display_name);
        $status_s     = in_array(trim($d['status'] ?? ''), ['active', 'inactive', 'lapsed'], true) ? mysqli_real_escape_string($conn, trim($d['status'])) : 'active';
        $source_s     = in_array(trim($d['source'] ?? ''), ['direct', 'referral'], true) ? mysqli_real_escape_string($conn, trim($d['source'])) : 'direct';
        $notes_s      = (isset($d['notes']) && trim($d['notes']) !== '') ? "'" . mysqli_real_escape_string($conn, trim($d['notes'])) . "'" : 'NULL';

        if ($customer_type === 'individual') {
            $nik = trim($d['nik'] ?? '');
            if (!$nik || strlen($nik) !== 16 || !ctype_digit($nik)) {
                $failed[] = ['row' => $row_num, 'reason' => 'Invalid or missing NIK (must be 16 digits)'];
                continue;
            }
            $nik_s  = mysqli_real_escape_string($conn, $nik);
            $dob    = (isset($d['date_of_birth']) && trim($d['date_of_birth']) !== '')     ? "'" . mysqli_real_escape_string($conn, trim($d['date_of_birth'])) . "'"     : 'NULL';
            $phone  = (isset($d['personal_phone']) && trim($d['personal_phone']) !== '')   ? "'" . mysqli_real_escape_string($conn, trim($d['personal_phone'])) . "'"   : 'NULL';
            $wa     = (isset($d['personal_whatsapp']) && trim($d['personal_whatsapp']) !== '') ? "'" . mysqli_real_escape_string($conn, trim($d['personal_whatsapp'])) . "'" : 'NULL';
            $email  = (isset($d['personal_email']) && trim($d['personal_email']) !== '')   ? "'" . mysqli_real_escape_string($conn, trim($d['personal_email'])) . "'"   : 'NULL';
            $addr   = (isset($d['personal_address']) && trim($d['personal_address']) !== '') ? "'" . mysqli_real_escape_string($conn, trim($d['personal_address'])) . "'" : 'NULL';
            $npwp   = (isset($d['npwp_personal']) && trim($d['npwp_personal']) !== '')     ? "'" . mysqli_real_escape_string($conn, trim($d['npwp_personal'])) . "'"     : 'NULL';

            $sql = "INSERT INTO " . APP_SCHEMA . ".customers (customer_id, company_id, customer_type, status, source, display_name, notes, nik, date_of_birth, personal_phone, personal_whatsapp, personal_email, personal_address, npwp_personal, created_by, created_at)
                VALUES ('$customer_id', '$company_id', '$ct_s', '$status_s', '$source_s', '$dn_s', $notes_s, '$nik_s', $dob, $phone, $wa, $email, $addr, $npwp, '$username', '$now')";
        } else {
            $company_legal_name = trim($d['company_legal_name'] ?? '');
            $npwp_company       = trim($d['npwp_company'] ?? '');
            $pic_name           = trim($d['pic_name'] ?? '');
            $pic_phone          = trim($d['pic_phone'] ?? '');
            $pic_whatsapp       = trim($d['pic_whatsapp'] ?? '');

            if (!$company_legal_name || !$npwp_company || !$pic_name || !$pic_phone || !$pic_whatsapp) {
                $failed[] = ['row' => $row_num, 'reason' => 'Missing required fields for company type (company_legal_name, npwp_company, pic_name, pic_phone, pic_whatsapp)'];
                continue;
            }

            $legal_s    = mysqli_real_escape_string($conn, $company_legal_name);
            $npwp_co_s  = mysqli_real_escape_string($conn, $npwp_company);
            $pic_name_s = mysqli_real_escape_string($conn, $pic_name);
            $pic_ph_s   = mysqli_real_escape_string($conn, $pic_phone);
            $pic_wa_s   = mysqli_real_escape_string($conn, $pic_whatsapp);
            $btype      = (isset($d['business_type']) && trim($d['business_type']) !== '')         ? "'" . mysqli_real_escape_string($conn, trim($d['business_type'])) . "'"         : 'NULL';
            $nib        = (isset($d['nib']) && trim($d['nib']) !== '')                             ? "'" . mysqli_real_escape_string($conn, trim($d['nib'])) . "'"                     : 'NULL';
            $op_addr    = (isset($d['operational_address']) && trim($d['operational_address']) !== '') ? "'" . mysqli_real_escape_string($conn, trim($d['operational_address'])) . "'" : 'NULL';
            $legal_addr = (isset($d['legal_address']) && trim($d['legal_address']) !== '')         ? "'" . mysqli_real_escape_string($conn, trim($d['legal_address'])) . "'"         : 'NULL';
            $co_phone   = (isset($d['company_phone']) && trim($d['company_phone']) !== '')         ? "'" . mysqli_real_escape_string($conn, trim($d['company_phone'])) . "'"         : 'NULL';
            $co_email   = (isset($d['company_email']) && trim($d['company_email']) !== '')         ? "'" . mysqli_real_escape_string($conn, trim($d['company_email'])) . "'"         : 'NULL';
            $pic_role   = (isset($d['pic_role']) && trim($d['pic_role']) !== '')                   ? "'" . mysqli_real_escape_string($conn, trim($d['pic_role'])) . "'"               : 'NULL';
            $pic_email  = (isset($d['pic_email']) && trim($d['pic_email']) !== '')                 ? "'" . mysqli_real_escape_string($conn, trim($d['pic_email'])) . "'"             : 'NULL';

            $sql = "INSERT INTO " . APP_SCHEMA . ".customers (customer_id, company_id, customer_type, status, source, display_name, notes, company_legal_name, business_type, npwp_company, nib, operational_address, legal_address, company_phone, company_email, pic_name, pic_role, pic_phone, pic_whatsapp, pic_email, created_by, created_at)
                VALUES ('$customer_id', '$company_id', '$ct_s', '$status_s', '$source_s', '$dn_s', $notes_s, '$legal_s', $btype, '$npwp_co_s', $nib, $op_addr, $legal_addr, $co_phone, $co_email, '$pic_name_s', $pic_role, '$pic_ph_s', '$pic_wa_s', $pic_email, '$username', '$now')";
        }

        if (mysqli_query($conn, $sql)) {
            $inserted++;
        } else {
            $failed[] = ['row' => $row_num, 'reason' => mysqli_error($conn)];
        }
    }

    fclose($handle);

    jsonResponse(200, "Import completed. $inserted customer(s) imported.", [
        'inserted'    => $inserted,
        'failed_count' => count($failed),
        'failed'      => $failed,
    ]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
// $action and $parts are available from the parent router (index.php via require)

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

// $parts[3] = customer_id or special keyword (e.g. 'import')
// $parts[4] = sub-action (e.g. 'status', 'follow-ups')
$customer_id = (!empty($action) && $action !== 'import') ? $action : null;
$sub_action  = $parts[4] ?? '';

try {
    $conn = getConn();

    // POST /api/v1/customers/import
    if ($method === 'POST' && $action === 'import') {
        importCustomers($conn, $company_id, $username);

    // PUT /api/v1/customers/{customer_id}/status
    } elseif ($customer_id && $sub_action === 'status') {
        if ($method !== 'PUT') { jsonResponse(405, 'Method Not Allowed'); }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateCustomerStatus($conn, $customer_id, $company_id, $input);

    // GET /api/v1/customers/{customer_id}/follow-ups
    } elseif ($customer_id && $sub_action === 'follow-ups') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getFollowUps($conn, $customer_id, $company_id);

    // /api/v1/customers/{customer_id}
    } elseif ($customer_id) {
        switch ($method) {
            case 'GET':
                getDetailCustomer($conn, $customer_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateCustomer($conn, $customer_id, $input, $company_id);
                break;
            case 'DELETE':
                deleteCustomer($conn, $customer_id, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    // /api/v1/customers
    } else {
        switch ($method) {
            case 'GET':
                $params        = $_GET['params'] ?? '';
                $customer_type = $_GET['customer_type'] ?? '';
                $status        = $_GET['status'] ?? '';
                $page          = $_GET['page'] ?? 1;
                $limit         = $_GET['limit'] ?? 10;
                getAllCustomers($conn, $company_id, $params, $customer_type, $status, $page, $limit);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createCustomer($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
