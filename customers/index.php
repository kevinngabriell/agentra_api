<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/sub_agents.php';
require_once __DIR__ . '/export.php';
require_once __DIR__ . '/../helpers/subscription.php';
define('CUSTOMER_GROUPS_AS_LIBRARY', true);
require_once __DIR__ . '/../customer-groups/index.php';
require_once __DIR__ . '/overview.php';
require_once __DIR__ . '/../helpers/import.php';

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
    // [NEW v1.7.0] Referring sub-agent must be an active sub-agent of this company; naming one makes the customer a referral.
    $referred_by_agent_id = requireActiveSubAgentId($conn, $company_id, $input['referred_by_agent_id'] ?? null);
    if ($referred_by_agent_id !== null) {
        $source = 'referral';
    }

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
    $limit         = min(15, max(1, (int)$limit));
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

    // [NEW v1.11.0] Customer group
    $data['group_name'] = null;
    if (!empty($data['group_id'])) {
        $gr = mysqli_query($conn, "SELECT name FROM " . APP_SCHEMA . ".customer_groups WHERE group_id = '" . mysqli_real_escape_string($conn, $data['group_id']) . "' LIMIT 1");
        if ($gr && mysqli_num_rows($gr) > 0) $data['group_name'] = mysqli_fetch_assoc($gr)['name'];
    }

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

// --- GET POLICIES OWNED BY CUSTOMER (v1.1) ---
function getCustomerPolicies($conn, $customer_id, $company_id, $params){
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

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(15, max(1, (int)($params['limit'] ?? 15)));
    $offset = ($page - 1) * $limit;

    $renewal_status = isset($params['renewal_status']) ? mysqli_real_escape_string($conn, $params['renewal_status']) : '';
    $where = "p.customer_id = '$customer_id' AND p.company_id = '$company_id'";
    if ($renewal_status && in_array($renewal_status, ['pending', 'renewed', 'lapsed', 'cancelled'], true)) {
        $where .= " AND p.renewal_status = '$renewal_status'";
    }

    $query = "SELECT p.policy_id, p.policy_number, p.product_type, p.policy_year,
                p.renewal_status, p.payment_status,
                p.coverage_start, p.coverage_end,
                p.sum_insured, p.premium_amount, p.commission_amount,
                p.insurer_id, i.short_name AS insurer_short_name,
                p.issuing_agent_id, p.created_at
              FROM " . APP_SCHEMA . ".policies p
              LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
              WHERE $where
              ORDER BY p.coverage_start DESC
              LIMIT $limit OFFSET $offset";

    $countQuery = "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".policies p WHERE $where";

    $result      = mysqli_query($conn, $query);
    $countResult = mysqli_query($conn, $countQuery);
    $total       = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;
    $data        = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Customer policies retrieved', [
        'data' => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
        ]
    ]);
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

    // [NEW v1.7.0] Validated against sub_agents; a direct customer has no referrer, a referrer makes it a referral.
    if (array_key_exists('referred_by_agent_id', $input)) {
        $referred_by = requireActiveSubAgentId($conn, $company_id, $input['referred_by_agent_id']);
        $updates[] = "referred_by_agent_id = " . ($referred_by !== null ? "'" . mysqli_real_escape_string($conn, $referred_by) . "'" : 'NULL');
        if ($referred_by !== null) {
            $updates[] = "source = 'referral'";
        }
    }
    if (($input['source'] ?? null) === 'direct' && !array_key_exists('referred_by_agent_id', $input)) {
        $updates[] = "referred_by_agent_id = NULL";
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

    // Refuse to delete a customer that still has linked policies — otherwise
    // those policies are orphaned (customer_id points to a nonexistent row),
    // breaking joins and reports. Soft-delete is the safer long-term option.
    $polCheck = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies WHERE customer_id = '$customer_id' AND company_id = '$company_id'");
    if ($polCheck) {
        $cnt = (int)mysqli_fetch_assoc($polCheck)['cnt'];
        if ($cnt > 0) {
            jsonResponse(409, 'Cannot delete customer with existing policies. Deactivate the customer instead.');
            return;
        }
    }

    // Same for documents (KYC files are an audit trail and FK-bound to the customer).
    $docCheck = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".documents WHERE customer_id = '$customer_id'");
    if ($docCheck && (int)mysqli_fetch_assoc($docCheck)['cnt'] > 0) {
        jsonResponse(409, 'Cannot delete customer with documents. Deactivate the customer instead.');
        return;
    }

    if (mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Customer deleted successfully');
    } else {
        error_log('deleteCustomer DB error: ' . mysqli_error($conn));
        jsonResponse(500, 'Failed to delete customer');
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
    $limit  = min(15, max(1, (int)($_GET['limit'] ?? 15)));
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

// --- IMPORT (CSV / XLSX) ---
const CUSTOMER_IMPORT_TEMPLATE = [
    'customer_type' => 'individual', 'display_name' => 'Budi Santoso', 'status' => 'active', 'source' => 'direct', 'notes' => '',
    'nik' => '3174012345678901', 'date_of_birth' => '1990-05-17', 'npwp_personal' => '', 'personal_phone' => '081234567890',
    'personal_whatsapp' => '081234567890', 'personal_email' => 'budi@example.com', 'personal_address' => 'Jl. Merdeka No. 1, Jakarta',
    'company_legal_name' => '', 'npwp_company' => '', 'nib' => '', 'business_type' => '', 'company_phone' => '', 'company_email' => '',
    'operational_address' => '', 'legal_address' => '', 'pic_name' => '', 'pic_role' => '', 'pic_phone' => '', 'pic_whatsapp' => '', 'pic_email' => '',
];

/** Nullable quoted SQL literal for an optional import cell. */
function importSqlOrNull($conn, array $d, string $key): string {
    $v = trim((string)($d[$key] ?? ''));
    return $v === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
}

/**
 * Validates one import row. Returns ['errors' => string[], 'sql' => ?string]; sql is set only when errors is empty.
 * $seen tracks NIK / NPWP values already taken in this file, on top of the ones already in the database.
 */
function validateCustomerImportRow($conn, string $company_id, string $username, string $now, array $d, array &$seen): array {
    $errors = [];
    $customer_type = strtolower(trim((string)($d['customer_type'] ?? '')));
    $display_name  = trim((string)($d['display_name'] ?? ''));

    if (!in_array($customer_type, ['individual', 'company'], true)) $errors[] = 'customer_type must be individual or company';
    if ($display_name === '') $errors[] = 'display_name is empty';

    $status = strtolower(trim((string)($d['status'] ?? '')));
    if ($status !== '' && !in_array($status, ['active', 'inactive', 'lapsed'], true)) $errors[] = 'status must be active, inactive or lapsed';
    $source = strtolower(trim((string)($d['source'] ?? '')));
    if ($source !== '' && !in_array($source, ['direct', 'referral'], true)) $errors[] = 'source must be direct or referral';
    $status = $status === '' ? 'active' : $status;
    $source = $source === '' ? 'direct' : $source;

    foreach (['personal_email', 'company_email', 'pic_email'] as $emailKey) {
        $e = trim((string)($d[$emailKey] ?? ''));
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) $errors[] = "$emailKey is not a valid email";
    }
    $dob = trim((string)($d['date_of_birth'] ?? ''));
    $dobNorm = $dob === '' ? null : importNormalizeDate($dob);
    if ($dob !== '' && $dobNorm === null) $errors[] = 'date_of_birth must be YYYY-MM-DD';

    $idKey = null; $idCol = null;
    if ($customer_type === 'individual') {
        $nik = trim((string)($d['nik'] ?? ''));
        if (strlen($nik) !== 16 || !ctype_digit($nik)) $errors[] = 'Invalid or missing NIK (must be 16 digits)';
        else { $idKey = "nik:$nik"; $idCol = ['nik', $nik, 'NIK']; }
    } elseif ($customer_type === 'company') {
        foreach (['company_legal_name', 'npwp_company', 'pic_name', 'pic_phone', 'pic_whatsapp'] as $f) {
            if (trim((string)($d[$f] ?? '')) === '') $errors[] = "$f is required for company";
        }
        $npwp = trim((string)($d['npwp_company'] ?? ''));
        if ($npwp !== '') { $idKey = "npwp:$npwp"; $idCol = ['npwp_company', $npwp, 'NPWP']; }
    }

    if ($idKey !== null) {
        if (isset($seen[$idKey])) {
            $errors[] = "Duplicate {$idCol[2]} in file (also on row {$seen[$idKey]})";
        } else {
            $col = $idCol[0];
            $val = mysqli_real_escape_string($conn, $idCol[1]);
            $res = mysqli_query($conn, "SELECT display_name FROM " . APP_SCHEMA . ".customers WHERE company_id = '$company_id' AND $col = '$val' LIMIT 1");
            if ($res && ($ex = mysqli_fetch_assoc($res))) $errors[] = "{$idCol[2]} already registered to \"{$ex['display_name']}\"";
        }
    }
    if ($errors) return ['errors' => $errors, 'sql' => null];

    $customer_id = 'cust_' . bin2hex(random_bytes(10));
    $ct_s     = mysqli_real_escape_string($conn, $customer_type);
    $dn_s     = mysqli_real_escape_string($conn, $display_name);
    $status_s = mysqli_real_escape_string($conn, $status);
    $source_s = mysqli_real_escape_string($conn, $source);
    $notes_s  = importSqlOrNull($conn, $d, 'notes');
    $o = fn(string $k) => importSqlOrNull($conn, $d, $k);
    $u = mysqli_real_escape_string($conn, (string)$username);

    if ($customer_type === 'individual') {
        $nik_s = mysqli_real_escape_string($conn, trim((string)$d['nik']));
        $dob_s = $dobNorm === null ? 'NULL' : "'$dobNorm'";
        $sql = "INSERT INTO " . APP_SCHEMA . ".customers (customer_id, company_id, customer_type, status, source, display_name, notes, nik, date_of_birth, personal_phone, personal_whatsapp, personal_email, personal_address, npwp_personal, created_by, created_at)
            VALUES ('$customer_id', '$company_id', '$ct_s', '$status_s', '$source_s', '$dn_s', $notes_s, '$nik_s', $dob_s, {$o('personal_phone')}, {$o('personal_whatsapp')}, {$o('personal_email')}, {$o('personal_address')}, {$o('npwp_personal')}, '$u', '$now')";
    } else {
        $q = fn(string $k) => mysqli_real_escape_string($conn, trim((string)$d[$k]));
        $sql = "INSERT INTO " . APP_SCHEMA . ".customers (customer_id, company_id, customer_type, status, source, display_name, notes, company_legal_name, business_type, npwp_company, nib, operational_address, legal_address, company_phone, company_email, pic_name, pic_role, pic_phone, pic_whatsapp, pic_email, created_by, created_at)
            VALUES ('$customer_id', '$company_id', '$ct_s', '$status_s', '$source_s', '$dn_s', $notes_s, '{$q('company_legal_name')}', {$o('business_type')}, '{$q('npwp_company')}', {$o('nib')}, {$o('operational_address')}, {$o('legal_address')}, {$o('company_phone')}, {$o('company_email')}, '{$q('pic_name')}', {$o('pic_role')}, '{$q('pic_phone')}', '{$q('pic_whatsapp')}', {$o('pic_email')}, '$u', '$now')";
    }
    return ['errors' => [], 'sql' => $sql, 'idKey' => $idKey];
}

// POST /customers/import              multipart: file (CSV or XLSX), dry_run=1 to validate without saving
// GET  /customers/import/template     ?format=csv|xlsx
function importCustomers($conn, $company_id, $username){
    $table = importReadUpload();
    foreach (['customer_type', 'display_name'] as $required) {
        if (!in_array($required, $table['headers'], true)) {
            jsonResponse(400, "Missing required column: $required");
        }
    }
    $dryRun = in_array(strtolower((string)($_POST['dry_run'] ?? '')), ['1', 'true'], true);

    $now      = date('Y-m-d H:i:s');
    $seen     = [];
    $valid    = [];   // [row_num => sql]
    $failed   = [];   // [row, reason]
    $preview  = [];

    foreach ($table['rows'] as $r) {
        $res = validateCustomerImportRow($conn, $company_id, (string)$username, $now, $r['data'], $seen);
        if ($res['errors']) {
            $failed[] = ['row' => $r['row'], 'reason' => implode('; ', $res['errors'])];
        } else {
            $valid[$r['row']] = $res['sql'];
            if (isset($res['idKey'])) $seen[$res['idKey']] = $r['row'];
        }
        if (count($preview) < IMPORT_PREVIEW_ROWS) {
            $preview[] = [
                'row'           => $r['row'],
                'customer_type' => strtolower(trim((string)($r['data']['customer_type'] ?? ''))),
                'display_name'  => trim((string)($r['data']['display_name'] ?? '')),
                'identifier'    => trim((string)(($r['data']['nik'] ?? '') ?: ($r['data']['npwp_company'] ?? ''))),
                'errors'        => $res['errors'],
            ];
        }
    }

    if ($dryRun) {
        jsonResponse(200, 'Import preview', [
            'dry_run'      => true,
            'total'        => count($table['rows']),
            'valid_count'  => count($valid),
            'failed_count' => count($failed),
            'failed'       => $failed,
            'preview'      => $preview,
        ]);
    }

    // One transaction: a failure mid-import can't leave the first N rows saved while the response reports an error,
    // so the client can safely retry without creating duplicates.
    $inserted = 0;
    mysqli_begin_transaction($conn);
    try {
        foreach ($valid as $row_num => $sql) {
            if (mysqli_query($conn, $sql)) {
                $inserted++;
            } else {
                // Real error stays in the server log; the client only sees a generic reason.
                error_log("importCustomers row $row_num failed: " . mysqli_error($conn));
                $failed[] = ['row' => $row_num, 'reason' => 'Database error'];
            }
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        error_log('importCustomers exception: ' . $e->getMessage());
        Response::error('ERR_COMMON_005', 'Import gagal — tidak ada baris yang berhasil disimpan', 500);
    }

    jsonResponse(200, "Import completed. $inserted customer(s) imported.", [
        'dry_run'      => false,
        'total'        => count($table['rows']),
        'inserted'     => $inserted,
        'failed_count' => count($failed),
        'failed'       => $failed,
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

// $parts[3] = customer_id or special keyword (e.g. 'import', 'export')
// $parts[4] = sub-action (e.g. 'status', 'follow-ups', 'policies')
$customer_id = (!empty($action) && !in_array($action, ['import', 'export'], true)) ? $action : null;
$sub_action  = $parts[4] ?? '';

try {
    $conn = getConn();

    // POST /api/v1/customers/import
    if ($action === 'import' && $sub_action === 'template') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        importSendTemplate('TEMPLATE IMPORT CUSTOMER', CUSTOMER_IMPORT_TEMPLATE, ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx');

    } elseif ($method === 'POST' && $action === 'import') {
        importCustomers($conn, $company_id, $username);

    // GET /api/v1/customers/export
    } elseif ($action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        requirePlanFeature($conn, $company_id, 'export');
        exportCustomers($conn, $company_id, $_GET);

    // GET /api/v1/customers/{customer_id}/overview, /cross-sell   PUT /api/v1/customers/{customer_id}/group
    } elseif ($customer_id && $sub_action === 'overview') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getCustomerOverview($conn, $company_id, $customer_id, $_GET);

    } elseif ($customer_id && $sub_action === 'cross-sell') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getCustomerCrossSell($conn, $company_id, $customer_id, $_GET);

    } elseif ($customer_id && $sub_action === 'group') {
        if ($method !== 'PUT') { jsonResponse(405, 'Method Not Allowed'); }
        setCustomerGroup($conn, $company_id, $customer_id, input(), $username);

    // PUT /api/v1/customers/{customer_id}/status
    } elseif ($customer_id && $sub_action === 'status') {
        if ($method !== 'PUT') { jsonResponse(405, 'Method Not Allowed'); }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        updateCustomerStatus($conn, $customer_id, $company_id, $input);

    // GET /api/v1/customers/{customer_id}/follow-ups
    } elseif ($customer_id && $sub_action === 'follow-ups') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getFollowUps($conn, $customer_id, $company_id);

    // GET /api/v1/customers/{customer_id}/policies
    } elseif ($customer_id && $sub_action === 'policies') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getCustomerPolicies($conn, $customer_id, $company_id, $_GET);

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
                $page          = max(1, (int)($_GET['page'] ?? 1));
                $limit         = min(15, max(1, (int)($_GET['limit'] ?? 15)));
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

} catch (ApiException $e) {
    throw $e;
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log($e->getMessage());
    Response::internalError();
}
