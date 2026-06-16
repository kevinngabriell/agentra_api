<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/policy_log.php';
require_once __DIR__ . '/../helpers/commission.php';

// Looks up commission rate from master_products.
// Matches first by product_code (= product_type), then by policy number prefix via policy_prefixes.
// Returns ['rate' => float, 'product_name' => string] or null if no match.
function resolveCommissionRateFromDB($conn, $company_id, $product_type, $policy_number) {
    $company_id   = mysqli_real_escape_string($conn, $company_id);
    $product_code = strtolower(trim(mysqli_real_escape_string($conn, $product_type)));

    $res = mysqli_query($conn,
        "SELECT commission_rate, product_name FROM " . APP_SCHEMA . ".master_products
         WHERE company_id = '$company_id' AND product_code = '$product_code' AND is_active = 1 LIMIT 1"
    );
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
        return ['rate' => (float)$row['commission_rate'], 'product_name' => $row['product_name']];
    }

    // Fall back: match by policy number prefix stored in policy_prefixes
    $prefix = substr(preg_replace('/\s+/', '', $policy_number), 0, 2);
    $prefix = mysqli_real_escape_string($conn, $prefix);
    $res = mysqli_query($conn,
        "SELECT commission_rate, product_name FROM " . APP_SCHEMA . ".master_products
         WHERE company_id = '$company_id' AND is_active = 1
         AND FIND_IN_SET('$prefix', REPLACE(policy_prefixes, ' ', '')) > 0 LIMIT 1"
    );
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
        return ['rate' => (float)$row['commission_rate'], 'product_name' => $row['product_name']];
    }

    return null;
}

// --- GET ALL (PO-001) ---
function getAllPolicies($conn, $company_id, $params){
    $page           = max(1, (int)($params['page']   ?? 1));
    $limit          = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset         = ($page - 1) * $limit;
    $search         = isset($params['search'])         ? mysqli_real_escape_string($conn, $params['search'])         : '';
    $customer_id    = isset($params['customer_id'])    ? mysqli_real_escape_string($conn, $params['customer_id'])    : '';
    $product_type   = isset($params['product_type'])   ? mysqli_real_escape_string($conn, $params['product_type'])   : '';
    $insurer_id     = isset($params['insurer_id'])     ? mysqli_real_escape_string($conn, $params['insurer_id'])     : '';
    $renewal_status = isset($params['renewal_status']) ? mysqli_real_escape_string($conn, $params['renewal_status']) : '';
    $expiry_month   = isset($params['expiry_month'])   ? mysqli_real_escape_string($conn, $params['expiry_month'])   : '';
    $agent_id       = isset($params['agent_id'])       ? mysqli_real_escape_string($conn, $params['agent_id'])       : '';

    $where = "p.company_id = '$company_id'";

    if ($search) {
        $where .= " AND (p.policy_number LIKE '%$search%' OR c.display_name LIKE '%$search%' OR c.company_legal_name LIKE '%$search%')";
    }
    if ($customer_id) {
        $where .= " AND p.customer_id = '$customer_id'";
    }
    if ($product_type) {
        $where .= " AND p.product_type = '$product_type'";
    }
    if ($insurer_id) {
        $where .= " AND p.insurer_id = '$insurer_id'";
    }
    if ($renewal_status && in_array($renewal_status, ['pending', 'renewed', 'lapsed', 'cancelled'], true)) {
        $where .= " AND p.renewal_status = '$renewal_status'";
    }
    if ($expiry_month && preg_match('/^\d{4}-\d{2}$/', $expiry_month)) {
        $where .= " AND DATE_FORMAT(p.coverage_end, '%Y-%m') = '$expiry_month'";
    }
    if ($agent_id) {
        $where .= " AND p.issuing_agent_id = '$agent_id'";
    }

    $query = "SELECT p.policy_id, p.policy_number, p.product_type, p.policy_year,
                p.renewal_status, p.payment_status,
                p.coverage_start, p.coverage_end,
                p.sum_insured, p.premium_amount, p.commission_rate, p.commission_amount,
                p.customer_id, c.display_name AS customer_name,
                p.insurer_id, i.short_name AS insurer_short_name,
                p.issuing_agent_id, p.created_at
              FROM " . APP_SCHEMA . ".policies p
              LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
              LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
              WHERE $where
              ORDER BY p.created_at DESC
              LIMIT $limit OFFSET $offset";

    $countQuery = "SELECT COUNT(*) AS total
                   FROM " . APP_SCHEMA . ".policies p
                   LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
                   WHERE $where";

    $result      = mysqli_query($conn, $query);
    $countResult = mysqli_query($conn, $countQuery);
    $total       = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;

    if ($result && mysqli_num_rows($result) > 0) {
        jsonResponse(200, 'Policies found', [
            'data'       => mysqli_fetch_all($result, MYSQLI_ASSOC),
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ]);
    } else {
        jsonResponse(404, 'No policies found');
    }
}

// --- CREATE (PO-002) ---
function createPolicy($conn, $input, $username, $company_id){
    $required = ['insurer_id', 'customer_id', 'policy_number', 'product_type', 'coverage_start', 'coverage_end', 'sum_insured', 'premium_amount'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || is_string($input[$field]) && trim($input[$field]) === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $insurer_id    = mysqli_real_escape_string($conn, $input['insurer_id']);
    $customer_id   = mysqli_real_escape_string($conn, $input['customer_id']);
    $policy_number = trim(mysqli_real_escape_string($conn, $input['policy_number']));
    $product_type  = strtolower(trim(mysqli_real_escape_string($conn, $input['product_type'])));

    // Validate product_type against master_products
    $pt_check = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".master_products
         WHERE company_id = '$company_id' AND product_code = '$product_type' AND is_active = 1 LIMIT 1"
    );
    if (!$pt_check || mysqli_num_rows($pt_check) === 0) {
        jsonResponse(400, 'Invalid product_type. Must match an active product code in master products.');
        return;
    }

    $coverage_start = trim(mysqli_real_escape_string($conn, $input['coverage_start']));
    $coverage_end   = trim(mysqli_real_escape_string($conn, $input['coverage_end']));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverage_start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverage_end)) {
        jsonResponse(400, 'coverage_start and coverage_end must be YYYY-MM-DD format');
        return;
    }
    if ($coverage_end <= $coverage_start) {
        jsonResponse(400, 'coverage_end must be after coverage_start');
        return;
    }

    $sum_insured    = (int)$input['sum_insured'];
    $premium_amount = (int)$input['premium_amount'];

    if (isset($input['commission_rate']) && $input['commission_rate'] !== '') {
        $commission_rate = round((float)$input['commission_rate'], 2);
    } else {
        $resolved = resolveCommissionRateFromDB($conn, $company_id, $product_type, $policy_number);
        if ($resolved === null) {
            jsonResponse(400, 'commission_rate is required: no commission rule found for this product type or policy number prefix');
            return;
        }
        $commission_rate = $resolved['rate'];
    }
    $commission_amount = (int)round($premium_amount * $commission_rate / 100);

    // Unique policy number per company
    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policies WHERE company_id = '$company_id' AND policy_number = '$policy_number' LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Policy number already exists for this company');
        return;
    }

    // Validate insurer (must belong to company and be active)
    $ins_res = mysqli_query($conn, "SELECT agent_code FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$insurer_id' AND company_id = '$company_id' AND is_active = 1 LIMIT 1");
    if (mysqli_num_rows($ins_res) === 0) {
        jsonResponse(404, 'Insurer not found or inactive');
        return;
    }
    $ins_row            = mysqli_fetch_assoc($ins_res);
    $agent_code_used    = $ins_row['agent_code'] ? "'" . mysqli_real_escape_string($conn, $ins_row['agent_code']) . "'" : 'NULL';

    // Validate customer
    $cust_res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($cust_res) === 0) {
        jsonResponse(404, 'Customer not found');
        return;
    }

    $policy_id          = 'pol_' . uniqid();
    $now                = date('Y-m-d H:i:s');
    $policy_year        = isset($input['policy_year']) ? max(1, (int)$input['policy_year']) : 1;
    $issuing_agent_id   = isset($input['issuing_agent_id']) && $input['issuing_agent_id'] !== ''
                            ? "'" . mysqli_real_escape_string($conn, $input['issuing_agent_id']) . "'" : 'NULL';
    $previous_policy_id = isset($input['previous_policy_id']) && $input['previous_policy_id'] !== ''
                            ? "'" . mysqli_real_escape_string($conn, $input['previous_policy_id']) . "'" : 'NULL';
    $object_insured     = isset($input['object_insured'])  && trim($input['object_insured'])  !== ''
                            ? "'" . mysqli_real_escape_string($conn, trim($input['object_insured'])) . "'"  : 'NULL';
    $coverage_notes     = isset($input['coverage_notes']) && trim($input['coverage_notes']) !== ''
                            ? "'" . mysqli_real_escape_string($conn, trim($input['coverage_notes'])) . "'" : 'NULL';
    $notes              = isset($input['notes'])          && trim($input['notes'])          !== ''
                            ? "'" . mysqli_real_escape_string($conn, trim($input['notes'])) . "'"          : 'NULL';

    $sql = "INSERT INTO " . APP_SCHEMA . ".policies
        (policy_id, company_id, insurer_id, customer_id, issuing_agent_id, policy_number, agent_code_used,
         product_type, policy_year, previous_policy_id, object_insured, sum_insured, coverage_notes,
         coverage_start, coverage_end, premium_amount, renewal_status, payment_status,
         commission_rate, commission_amount, notes, created_by, created_at)
        VALUES
        ('$policy_id', '$company_id', '$insurer_id', '$customer_id', $issuing_agent_id, '$policy_number', $agent_code_used,
         '$product_type', $policy_year, $previous_policy_id, $object_insured, $sum_insured, $coverage_notes,
         '$coverage_start', '$coverage_end', $premium_amount, 'pending', 'unpaid',
         $commission_rate, $commission_amount, $notes, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        insertCommission($conn, $policy_id, $company_id, $insurer_id, $premium_amount, $commission_rate, $commission_amount, $issuing_agent_id !== 'NULL' ? $input['issuing_agent_id'] ?? null : null);
        insertPolicyLog($conn, $policy_id, $company_id, 'policy_created', 'Polis dibuat', $username);
        jsonResponse(201, 'Policy created successfully', ['policy_id' => $policy_id]);
    } else {
        jsonResponse(500, 'Failed to create policy', ['error' => mysqli_error($conn)]);
    }
}

// --- GET DETAIL (PO-003) ---
function getDetailPolicy($conn, $policy_id, $company_id){
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $result = mysqli_query($conn, "SELECT p.*,
        c.display_name AS customer_name, c.customer_type,
        i.name AS insurer_name, i.short_name AS insurer_short_name
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE p.policy_id = '$policy_id' AND p.company_id = '$company_id'
        LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    jsonResponse(200, 'Policy found', mysqli_fetch_assoc($result));
}

// --- UPDATE (PO-004) ---
function updatePolicy($conn, $policy_id, $input, $username, $company_id){
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $check = mysqli_query($conn,
        "SELECT premium_amount, commission_rate, object_insured, sum_insured,
                coverage_notes, coverage_start, coverage_end, notes
         FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }
    $current = mysqli_fetch_assoc($check);

    $updates = [];

    if (isset($input['object_insured'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['object_insured']));
        $updates[] = "object_insured = " . ($val !== '' ? "'$val'" : 'NULL');
    }
    if (isset($input['sum_insured'])) {
        $updates[] = "sum_insured = " . (int)$input['sum_insured'];
    }
    if (isset($input['coverage_notes'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['coverage_notes']));
        $updates[] = "coverage_notes = " . ($val !== '' ? "'$val'" : 'NULL');
    }
    if (isset($input['coverage_start'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['coverage_start']));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            jsonResponse(400, 'coverage_start must be YYYY-MM-DD format');
            return;
        }
        $updates[] = "coverage_start = '$val'";
    }
    if (isset($input['coverage_end'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['coverage_end']));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            jsonResponse(400, 'coverage_end must be YYYY-MM-DD format');
            return;
        }
        $updates[] = "coverage_end = '$val'";
    }
    if (isset($input['notes'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['notes']));
        $updates[] = "notes = " . ($val !== '' ? "'$val'" : 'NULL');
    }

    $new_premium = isset($input['premium_amount']) ? (int)$input['premium_amount'] : null;
    $new_rate    = isset($input['commission_rate']) ? round((float)$input['commission_rate'], 2) : null;

    if ($new_premium !== null) {
        $updates[] = "premium_amount = $new_premium";
    }
    if ($new_rate !== null) {
        $updates[] = "commission_rate = $new_rate";
    }
    if ($new_premium !== null || $new_rate !== null) {
        $premium   = $new_premium ?? (int)$current['premium_amount'];
        $rate      = $new_rate    ?? (float)$current['commission_rate'];
        $updates[] = "commission_amount = " . (int)round($premium * $rate / 100);
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET " . implode(', ', $updates) . " WHERE policy_id = '$policy_id' AND company_id = '$company_id'")) {
        // Determine event type: endorsement when financial or date fields change
        $financial_keys = ['sum_insured', 'premium_amount', 'commission_rate', 'coverage_start', 'coverage_end'];
        $is_endorsement = (bool)array_intersect($financial_keys, array_keys($input));
        $event_type     = $is_endorsement ? 'endorsement' : 'policy_updated';

        $changed_labels = [];
        $before = [];
        $after  = [];
        $label_map = [
            'object_insured'  => 'objek pertanggungan',
            'sum_insured'     => 'uang pertanggungan',
            'coverage_notes'  => 'catatan pertanggungan',
            'coverage_start'  => 'mulai pertanggungan',
            'coverage_end'    => 'akhir pertanggungan',
            'premium_amount'  => 'premi',
            'commission_rate' => 'rate komisi',
            'notes'           => 'catatan',
        ];
        foreach ($label_map as $field => $label) {
            if (isset($input[$field])) {
                $changed_labels[] = $label;
                $before[$field]   = $current[$field] ?? null;
                $after[$field]    = $input[$field];
            }
        }
        $desc = ($is_endorsement ? 'Endorsemen' : 'Polis diperbarui')
            . (!empty($changed_labels) ? ': ' . implode(', ', $changed_labels) : '');

        insertPolicyLog($conn, $policy_id, $company_id, $event_type, $desc, $username,
            null, null, null, null, ['before' => $before, 'after' => $after]);

        if ($new_premium !== null || $new_rate !== null) {
            $final_premium = $new_premium ?? (int)$current['premium_amount'];
            $final_rate    = $new_rate    ?? (float)$current['commission_rate'];
            $final_amount  = (int)round($final_premium * $final_rate / 100);
            syncCommission($conn, $policy_id, $final_premium, $final_rate, $final_amount);
        }

        jsonResponse(200, 'Policy updated successfully');
    } else {
        jsonResponse(500, 'Failed to update policy', ['error' => mysqli_error($conn)]);
    }
}

// --- UPDATE RENEWAL STATUS (PO-005) ---
function updateRenewalStatus($conn, $policy_id, $company_id, $input, $username){
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);
    $status    = isset($input['renewal_status']) ? mysqli_real_escape_string($conn, $input['renewal_status']) : null;

    if (!$status || !in_array($status, ['pending', 'renewed', 'lapsed', 'cancelled'], true)) {
        jsonResponse(400, 'renewal_status is required and must be: pending, renewed, lapsed, or cancelled');
        return;
    }

    $check = mysqli_query($conn, "SELECT renewal_status FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }
    $old_status = mysqli_fetch_assoc($check)['renewal_status'];

    $now = date('Y-m-d H:i:s');
    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET renewal_status = '$status', updated_by = '$username', updated_at = '$now' WHERE policy_id = '$policy_id' AND company_id = '$company_id'")) {
        insertPolicyLog($conn, $policy_id, $company_id, 'renewal_status_changed',
            "Status renewal diubah: $old_status → $status", $username, $old_status, $status);
        jsonResponse(200, 'Renewal status updated successfully');
    } else {
        jsonResponse(500, 'Failed to update renewal status', ['error' => mysqli_error($conn)]);
    }
}

// --- ADD FOLLOW-UP (PO-006) ---
function addFollowUp($conn, $policy_id, $company_id, $input, $username){
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $policy_res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($policy_res) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $followup_date = isset($input['follow_up_date']) ? trim(mysqli_real_escape_string($conn, $input['follow_up_date'])) : date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $followup_date)) {
        jsonResponse(400, 'follow_up_date must be YYYY-MM-DD format');
        return;
    }

    $valid_statuses  = ['not_contacted', 'contacted', 'customer_confirmed', 'customer_declined', 'no_response'];
    $followup_status = isset($input['followup_status']) && in_array($input['followup_status'], $valid_statuses, true)
        ? mysqli_real_escape_string($conn, $input['followup_status'])
        : 'not_contacted';
    $channel = isset($input['channel']) ? trim(mysqli_real_escape_string($conn, $input['channel'])) : '';
    $notes   = isset($input['notes'])   ? trim(mysqli_real_escape_string($conn, $input['notes']))   : '';

    $followup_id = 'fu_' . uniqid();
    $now         = date('Y-m-d H:i:s');
    $channel_sql = $channel !== '' ? "'$channel'" : 'NULL';
    $notes_sql   = $notes   !== '' ? "'$notes'"   : 'NULL';

    $sql = "INSERT INTO " . APP_SCHEMA . ".follow_up_logs (followup_id, policy_id, followup_status, channel, notes, followup_date, created_by, created_at)
        VALUES ('$followup_id', '$policy_id', '$followup_status', $channel_sql, $notes_sql, '$followup_date', '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        $channel_label = $channel !== '' ? " via $channel" : '';
        insertPolicyLog($conn, $policy_id, $company_id, 'followup_logged',
            "Follow-up dicatat{$channel_label}: $followup_status", $username,
            null, null, 'follow_up_logs', $followup_id);
        jsonResponse(201, 'Follow-up logged successfully', ['followup_id' => $followup_id]);
    } else {
        jsonResponse(500, 'Failed to log follow-up', ['error' => mysqli_error($conn)]);
    }
}

// --- UPDATE PAYMENT STATUS (PO-007, Main Agent only) ---
function updatePaymentStatus($conn, $policy_id, $company_id, $input, $username){
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);
    $status    = isset($input['payment_status']) ? mysqli_real_escape_string($conn, $input['payment_status']) : null;

    if (!$status || !in_array($status, ['unpaid', 'paid', 'confirmed'], true)) {
        jsonResponse(400, 'payment_status is required and must be: unpaid, paid, or confirmed');
        return;
    }

    $check = mysqli_query($conn, "SELECT payment_status FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }
    $old_status = mysqli_fetch_assoc($check)['payment_status'];

    $now = date('Y-m-d H:i:s');
    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET payment_status = '$status', updated_by = '$username', updated_at = '$now' WHERE policy_id = '$policy_id' AND company_id = '$company_id'")) {
        insertPolicyLog($conn, $policy_id, $company_id, 'payment_status_changed',
            "Status pembayaran diubah: $old_status → $status", $username, $old_status, $status);
        jsonResponse(200, 'Payment status updated successfully');
    } else {
        jsonResponse(500, 'Failed to update payment status', ['error' => mysqli_error($conn)]);
    }
}

// --- PAYMENT SUMMARY (PO-008, Main Agent only) ---
function getPaymentSummary($conn, $company_id, $params){
    $insurer_id     = isset($params['insurer_id']) ? mysqli_real_escape_string($conn, $params['insurer_id']) : '';
    $month          = isset($params['month'])      ? mysqli_real_escape_string($conn, $params['month'])      : '';
    $payment_filter = isset($params['payment_status']) ? mysqli_real_escape_string($conn, $params['payment_status']) : 'all';

    if (!$insurer_id) {
        jsonResponse(400, 'insurer_id is required');
        return;
    }

    $ins_res = mysqli_query($conn, "SELECT name, short_name, agent_code FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$insurer_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($ins_res) === 0) {
        jsonResponse(404, 'Insurer not found');
        return;
    }
    $insurer_data = mysqli_fetch_assoc($ins_res);

    $where = "p.company_id = '$company_id' AND p.insurer_id = '$insurer_id'";

    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where .= " AND DATE_FORMAT(p.coverage_start, '%Y-%m') = '$month'";
    }
    if ($payment_filter !== 'all' && in_array($payment_filter, ['unpaid', 'paid', 'confirmed'], true)) {
        $where .= " AND p.payment_status = '$payment_filter'";
    }

    $summary_res = mysqli_query($conn, "SELECT
        COUNT(*) AS total_policies,
        SUM(p.premium_amount) AS total_premium,
        SUM(p.commission_amount) AS total_commission,
        SUM(CASE WHEN p.payment_status = 'unpaid'    THEN p.premium_amount ELSE 0 END) AS unpaid_premium,
        SUM(CASE WHEN p.payment_status = 'paid'      THEN p.premium_amount ELSE 0 END) AS paid_premium,
        SUM(CASE WHEN p.payment_status = 'confirmed' THEN p.premium_amount ELSE 0 END) AS confirmed_premium,
        COUNT(CASE WHEN p.payment_status = 'unpaid'    THEN 1 END) AS unpaid_count,
        COUNT(CASE WHEN p.payment_status = 'paid'      THEN 1 END) AS paid_count,
        COUNT(CASE WHEN p.payment_status = 'confirmed' THEN 1 END) AS confirmed_count
    FROM " . APP_SCHEMA . ".policies p WHERE $where");

    $policies_res = mysqli_query($conn, "SELECT
        p.policy_id, p.policy_number, p.product_type, p.payment_status,
        p.premium_amount, p.commission_amount, p.coverage_start, p.coverage_end,
        c.display_name AS customer_name
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        WHERE $where
        ORDER BY p.coverage_start DESC");

    $summary       = mysqli_fetch_assoc($summary_res);
    $policies_list = $policies_res ? mysqli_fetch_all($policies_res, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Payment summary retrieved', [
        'insurer' => $insurer_data,
        'summary' => [
            'total_policies'    => (int)$summary['total_policies'],
            'total_premium'     => (int)$summary['total_premium'],
            'total_commission'  => (int)$summary['total_commission'],
            'unpaid_premium'    => (int)$summary['unpaid_premium'],
            'paid_premium'      => (int)$summary['paid_premium'],
            'confirmed_premium' => (int)$summary['confirmed_premium'],
            'unpaid_count'      => (int)$summary['unpaid_count'],
            'paid_count'        => (int)$summary['paid_count'],
            'confirmed_count'   => (int)$summary['confirmed_count'],
        ],
        'policies' => $policies_list,
    ]);
}

// --- GET COMMISSION (PO-009) ---
function getCommission($conn, $policy_id, $company_id) {
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $result = mysqli_query($conn,
        "SELECT cm.*,
                i.name         AS insurer_name,
                i.short_name   AS insurer_short_name,
                p.policy_number, p.product_type, p.coverage_start, p.coverage_end
         FROM " . APP_SCHEMA . ".commissions cm
         LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = cm.insurer_id
         LEFT JOIN " . APP_SCHEMA . ".policies   p ON p.policy_id   = cm.policy_id
         WHERE cm.policy_id = '$policy_id'
         LIMIT 1"
    );

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'No commission record found for this policy');
        return;
    }

    jsonResponse(200, 'Commission found', mysqli_fetch_assoc($result));
}

// --- GET POLICY LOGS (PO-010) ---
function getPolicyLogs($conn, $policy_id, $company_id, $params) {
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $page   = max(1, (int)($params['page']  ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $result = mysqli_query($conn,
        "SELECT log_id, event_type, reference_type, reference_id,
                old_value, new_value, description, metadata, created_by, created_at
         FROM " . APP_SCHEMA . ".policy_logs
         WHERE policy_id = '$policy_id'
         ORDER BY created_at DESC
         LIMIT $limit OFFSET $offset"
    );

    $countResult = mysqli_query($conn,
        "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".policy_logs WHERE policy_id = '$policy_id'"
    );

    $total = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;
    $data  = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Policy logs retrieved', [
        'data'       => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
        ],
    ]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['user_id'] ?? $authUser['sub'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

// $action     = $parts[3] — policy_id, 'payment-summary', or ''
// $sub_action = $parts[4] — 'renewal-status', 'follow-ups', 'payment-status', 'coverages', or ''
$policy_id  = (!empty($action) && $action !== 'payment-summary') ? $action : null;
$sub_action = $parts[4] ?? '';

try {
    $conn = getConn();

    // GET /api/v1/policies/payment-summary
    if ($action === 'payment-summary') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getPaymentSummary($conn, $company_id, $_GET);

    // /api/v1/policies/{policy_id}/{sub-action}
    } elseif ($policy_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'renewal-status':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                updateRenewalStatus($conn, $policy_id, $company_id, $input, $username);
                break;
            case 'follow-ups':
                if ($method !== 'POST') { jsonResponse(405, 'Method Not Allowed'); }
                addFollowUp($conn, $policy_id, $company_id, $input, $username);
                break;
            case 'payment-status':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                updatePaymentStatus($conn, $policy_id, $company_id, $input, $username);
                break;
            case 'coverages':
                require __DIR__ . '/coverages.php';
                break;
            case 'commission':
                if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
                getCommission($conn, $policy_id, $company_id);
                break;
            case 'logs':
                if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
                getPolicyLogs($conn, $policy_id, $company_id, $_GET);
                break;
            default:
                jsonResponse(404, 'Route not found');
        }

    // /api/v1/policies/{policy_id}
    } elseif ($policy_id) {
        switch ($method) {
            case 'GET':
                getDetailPolicy($conn, $policy_id, $company_id);
                break;
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePolicy($conn, $policy_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }

    // /api/v1/policies
    } else {
        switch ($method) {
            case 'GET':
                getAllPolicies($conn, $company_id, $_GET);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                createPolicy($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
