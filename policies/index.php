<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/policy_log.php';
require_once __DIR__ . '/../helpers/commission.php';
require_once __DIR__ . '/export.php';

// Looks up commission rate and tax rate from master_products.
// Matches first by product_code (= product_type), then by policy number prefix via policy_prefixes.
// [NEW v1.1] Returns ['rate' => float, 'tax_rate' => float, 'product_name' => string] or null if no match.
function resolveCommissionRateFromDB($conn, $company_id, $product_type, $policy_number) {
    $company_id   = mysqli_real_escape_string($conn, $company_id);
    $product_code = strtolower(trim(mysqli_real_escape_string($conn, $product_type)));

    $res = mysqli_query($conn,
        "SELECT commission_rate, default_tax_rate, product_name FROM " . APP_SCHEMA . ".master_products
         WHERE company_id = '$company_id' AND product_code = '$product_code' AND is_active = 1 LIMIT 1"
    );
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
        return [
            'rate'         => (float)$row['commission_rate'],
            'tax_rate'     => (float)$row['default_tax_rate'],
            'product_name' => $row['product_name'],
        ];
    }

    // Fall back: match by policy number prefix stored in policy_prefixes
    $prefix = substr(preg_replace('/\s+/', '', $policy_number), 0, 2);
    $prefix = mysqli_real_escape_string($conn, $prefix);
    $res = mysqli_query($conn,
        "SELECT commission_rate, default_tax_rate, product_name FROM " . APP_SCHEMA . ".master_products
         WHERE company_id = '$company_id' AND is_active = 1
         AND FIND_IN_SET('$prefix', REPLACE(policy_prefixes, ' ', '')) > 0 LIMIT 1"
    );
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res);
        return [
            'rate'         => (float)$row['commission_rate'],
            'tax_rate'     => (float)$row['default_tax_rate'],
            'product_name' => $row['product_name'],
        ];
    }

    return null;
}

// Resolves the "owner" of a policy for subagent write-scoping: the issuing agent,
// falling back to whoever created the record. Returns null if the policy doesn't
// exist under this company (the underlying write function will 404 on its own
// existence check, so letting the write through here is safe).
function resolvePolicyOwnerId($conn, $policy_id, $company_id) {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $company_id);
    $res = mysqli_query($conn, "SELECT issuing_agent_id, created_by FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pid' AND company_id = '$cid' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) {
        return null;
    }
    $row = mysqli_fetch_assoc($res);
    return $row['issuing_agent_id'] ?: $row['created_by'];
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
                p.sum_insured, p.premium_amount, p.materai_amount,
                p.biaya_polis, p.diskon,
                p.commission_rate, p.commission_amount,
                p.commission_tax_rate, p.commission_tax_amount,
                p.net_commission_amount, p.customer_premium_amount,
                p.is_coassurance,
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

    // Always resolve master product to get both commission rate and default tax rate.
    $resolved = resolveCommissionRateFromDB($conn, $company_id, $product_type, $policy_number);

    if (isset($input['commission_rate']) && $input['commission_rate'] !== '') {
        $commission_rate = round((float)$input['commission_rate'], 2);
    } elseif ($resolved !== null) {
        $commission_rate = $resolved['rate'];
    } else {
        jsonResponse(400, 'commission_rate is required: no commission rule found for this product type or policy number prefix');
        return;
    }

    // [NEW v1.1] Tax rate: explicit input overrides master product default.
    // Input as decimal 0–1 (e.g. 0.025 = 2.5% PPh). Defaults to master product value or 0.
    if (isset($input['commission_tax_rate']) && $input['commission_tax_rate'] !== '') {
        $commission_tax_rate = round((float)$input['commission_tax_rate'], 4);
        if ($commission_tax_rate < 0 || $commission_tax_rate > 1) {
            jsonResponse(400, 'commission_tax_rate must be a decimal between 0 and 1 (e.g. 0.025 = 2.5%)');
            return;
        }
    } else {
        $commission_tax_rate = $resolved ? round((float)$resolved['tax_rate'], 4) : 0.0;
    }

    // [NEW v1.1] Materai: stamp duty in IDR, per-policy, optional.
    $materai_amount = isset($input['materai_amount']) ? max(0, (int)$input['materai_amount']) : 0;

    // Biaya polis: admin/policy fee in IDR, optional.
    $biaya_polis = isset($input['biaya_polis']) ? max(0, (int)$input['biaya_polis']) : 0;

    // Diskon: discount in IDR, optional.
    $diskon = isset($input['diskon']) ? max(0, (int)$input['diskon']) : 0;

    // Derived commission breakdown:
    //   commission_amount       = premium × rate / 100                          (gross, before tax)
    //   commission_tax_amount   = commission_amount × tax_rate                  (PPh withheld)
    //   net_commission_amount   = commission_amount − tax_amount                (agent nets this)
    //   customer_premium_amount = premium + materai + biaya_polis − commission − diskon (billed to customer)
    $commission_amount       = (int)round($premium_amount * $commission_rate / 100);
    $commission_tax_amount   = (int)round($commission_amount * $commission_tax_rate);
    $net_commission_amount   = $commission_amount - $commission_tax_amount;
    $customer_premium_amount = $premium_amount + $materai_amount + $biaya_polis - $commission_amount - $diskon;

    // [NEW v1.1] Co-assurance flag: FE sets this to true when adding co-insurers after creation.
    $is_coassurance = !empty($input['is_coassurance']) ? 1 : 0;

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

    $valid_classes       = ['I', 'II', 'III'];
    $construction_class  = isset($input['construction_class']) && in_array(strtoupper(trim($input['construction_class'])), $valid_classes, true)
                            ? "'" . strtoupper(trim($input['construction_class'])) . "'" : 'NULL';

    $sql = "INSERT INTO " . APP_SCHEMA . ".policies
        (policy_id, company_id, insurer_id, customer_id, issuing_agent_id, policy_number, agent_code_used,
         product_type, policy_year, previous_policy_id, object_insured, sum_insured, coverage_notes, construction_class,
         coverage_start, coverage_end,
         premium_amount, materai_amount, biaya_polis, diskon,
         renewal_status, payment_status,
         commission_rate, commission_amount,
         commission_tax_rate, commission_tax_amount, net_commission_amount, customer_premium_amount,
         is_coassurance, notes, created_by, created_at)
        VALUES
        ('$policy_id', '$company_id', '$insurer_id', '$customer_id', $issuing_agent_id, '$policy_number', $agent_code_used,
         '$product_type', $policy_year, $previous_policy_id, $object_insured, $sum_insured, $coverage_notes, $construction_class,
         '$coverage_start', '$coverage_end',
         $premium_amount, $materai_amount, $biaya_polis, $diskon,
         'pending', 'unpaid',
         $commission_rate, $commission_amount,
         $commission_tax_rate, $commission_tax_amount, $net_commission_amount, $customer_premium_amount,
         $is_coassurance, $notes, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        insertCommission($conn, $policy_id, $company_id, $insurer_id, $premium_amount, $commission_rate, $commission_amount, $issuing_agent_id !== 'NULL' ? $input['issuing_agent_id'] ?? null : null, $commission_tax_rate);
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

// --- DELETE (PO-004) ---
function deletePolicy($conn, $policy_id, $company_id) {
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $check = mysqli_query($conn,
        "SELECT policy_id FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_logs     WHERE policy_id = '$policy_id'");
    mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".follow_up_logs  WHERE policy_id = '$policy_id'");
    mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_coverages WHERE policy_id = '$policy_id'");
    mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_coassurance WHERE policy_id = '$policy_id'");
    mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".commissions      WHERE policy_id = '$policy_id'");

    if (mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id'")) {
        jsonResponse(200, 'Policy deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete policy', ['error' => mysqli_error($conn)]);
    }
}

// --- UPDATE (PO-005) ---
function updatePolicy($conn, $policy_id, $input, $username, $company_id){
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $check = mysqli_query($conn,
        "SELECT premium_amount, commission_rate, commission_tax_rate, materai_amount, biaya_polis, diskon,
                object_insured, sum_insured, coverage_notes, construction_class, coverage_start, coverage_end, notes
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
    if (isset($input['construction_class'])) {
        $valid_classes = ['I', 'II', 'III'];
        $cc = strtoupper(trim($input['construction_class']));
        if ($input['construction_class'] === null || $input['construction_class'] === '') {
            $updates[] = "construction_class = NULL";
        } elseif (in_array($cc, $valid_classes, true)) {
            $updates[] = "construction_class = '$cc'";
        } else {
            jsonResponse(400, 'construction_class must be I, II, or III');
            return;
        }
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

    $new_premium    = isset($input['premium_amount'])      ? (int)$input['premium_amount']                      : null;
    $new_rate       = isset($input['commission_rate'])     ? round((float)$input['commission_rate'], 2)           : null;
    $new_materai    = isset($input['materai_amount'])      ? max(0, (int)$input['materai_amount'])                : null;
    $new_tax_rate   = isset($input['commission_tax_rate']) ? round((float)$input['commission_tax_rate'], 4)       : null;
    $new_biaya      = isset($input['biaya_polis'])         ? max(0, (int)$input['biaya_polis'])                   : null;
    $new_diskon     = isset($input['diskon'])              ? max(0, (int)$input['diskon'])                        : null;

    if ($new_premium  !== null) { $updates[] = "premium_amount = $new_premium"; }
    if ($new_rate     !== null) { $updates[] = "commission_rate = $new_rate"; }
    if ($new_materai  !== null) { $updates[] = "materai_amount = $new_materai"; }
    if ($new_tax_rate !== null) { $updates[] = "commission_tax_rate = $new_tax_rate"; }
    if ($new_biaya    !== null) { $updates[] = "biaya_polis = $new_biaya"; }
    if ($new_diskon   !== null) { $updates[] = "diskon = $new_diskon"; }

    if ($new_premium !== null || $new_rate !== null || $new_materai !== null || $new_tax_rate !== null || $new_biaya !== null || $new_diskon !== null) {
        $premium     = $new_premium  ?? (int)$current['premium_amount'];
        $rate        = $new_rate     ?? (float)$current['commission_rate'];
        $materai     = $new_materai  ?? (int)$current['materai_amount'];
        $tax_rate    = $new_tax_rate ?? (float)$current['commission_tax_rate'];
        $biaya_polis = $new_biaya    ?? (int)$current['biaya_polis'];
        $diskon      = $new_diskon   ?? (int)$current['diskon'];

        $comm_amount  = (int)round($premium * $rate / 100);
        $tax_amount   = (int)round($comm_amount * $tax_rate);
        $net_comm     = $comm_amount - $tax_amount;
        $cust_premium = $premium + $materai + $biaya_polis - $comm_amount - $diskon;

        $updates[] = "commission_amount = $comm_amount";
        $updates[] = "commission_tax_amount = $tax_amount";
        $updates[] = "net_commission_amount = $net_comm";
        $updates[] = "customer_premium_amount = $cust_premium";
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
        $financial_keys = ['sum_insured', 'premium_amount', 'commission_rate', 'commission_tax_rate', 'materai_amount', 'biaya_polis', 'diskon', 'coverage_start', 'coverage_end'];
        $is_endorsement = (bool)array_intersect($financial_keys, array_keys($input));
        $event_type     = $is_endorsement ? 'endorsement' : 'policy_updated';

        $changed_labels = [];
        $before = [];
        $after  = [];
        $label_map = [
            'object_insured'      => 'objek pertanggungan',
            'sum_insured'         => 'uang pertanggungan',
            'coverage_notes'      => 'catatan pertanggungan',
            'construction_class'  => 'kelas konstruksi',
            'coverage_start'      => 'mulai pertanggungan',
            'coverage_end'        => 'akhir pertanggungan',
            'premium_amount'      => 'premi',
            'materai_amount'      => 'materai',
            'biaya_polis'         => 'biaya polis',
            'diskon'              => 'diskon',
            'commission_rate'     => 'rate komisi',
            'commission_tax_rate' => 'rate pajak komisi',
            'notes'               => 'catatan',
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

        if ($new_premium !== null || $new_rate !== null || $new_materai !== null || $new_tax_rate !== null) {
            $final_premium  = $new_premium  ?? (int)$current['premium_amount'];
            $final_rate     = $new_rate     ?? (float)$current['commission_rate'];
            $final_tax_rate = $new_tax_rate ?? (float)$current['commission_tax_rate'];
            $final_amount   = (int)round($final_premium * $final_rate / 100);
            syncCommission($conn, $policy_id, $final_premium, $final_rate, $final_amount, $final_tax_rate);
        }

        jsonResponse(200, 'Policy updated successfully');
    } else {
        jsonResponse(500, 'Failed to update policy', ['error' => mysqli_error($conn)]);
    }
}

// --- DIRECT UPDATE WITHOUT ENDORSEMENT (PO-006) ---
function directUpdatePolicy($conn, $policy_id, $input, $username, $company_id) {
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $check = mysqli_query($conn,
        "SELECT premium_amount, commission_rate, commission_tax_rate, materai_amount, biaya_polis, diskon,
                object_insured, sum_insured, coverage_notes, construction_class, coverage_start, coverage_end, notes
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
    if (isset($input['construction_class'])) {
        $valid_classes = ['I', 'II', 'III'];
        $cc = strtoupper(trim($input['construction_class']));
        if ($input['construction_class'] === null || $input['construction_class'] === '') {
            $updates[] = "construction_class = NULL";
        } elseif (in_array($cc, $valid_classes, true)) {
            $updates[] = "construction_class = '$cc'";
        } else {
            jsonResponse(400, 'construction_class must be I, II, or III');
            return;
        }
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

    $new_premium  = isset($input['premium_amount'])      ? (int)$input['premium_amount']                 : null;
    $new_rate     = isset($input['commission_rate'])     ? round((float)$input['commission_rate'], 2)     : null;
    $new_materai  = isset($input['materai_amount'])      ? max(0, (int)$input['materai_amount'])           : null;
    $new_tax_rate = isset($input['commission_tax_rate']) ? round((float)$input['commission_tax_rate'], 4) : null;
    $new_biaya    = isset($input['biaya_polis'])         ? max(0, (int)$input['biaya_polis'])              : null;
    $new_diskon   = isset($input['diskon'])              ? max(0, (int)$input['diskon'])                   : null;

    if ($new_premium  !== null) { $updates[] = "premium_amount = $new_premium"; }
    if ($new_rate     !== null) { $updates[] = "commission_rate = $new_rate"; }
    if ($new_materai  !== null) { $updates[] = "materai_amount = $new_materai"; }
    if ($new_tax_rate !== null) { $updates[] = "commission_tax_rate = $new_tax_rate"; }
    if ($new_biaya    !== null) { $updates[] = "biaya_polis = $new_biaya"; }
    if ($new_diskon   !== null) { $updates[] = "diskon = $new_diskon"; }

    if ($new_premium !== null || $new_rate !== null || $new_materai !== null || $new_tax_rate !== null || $new_biaya !== null || $new_diskon !== null) {
        $premium     = $new_premium  ?? (int)$current['premium_amount'];
        $rate        = $new_rate     ?? (float)$current['commission_rate'];
        $materai     = $new_materai  ?? (int)$current['materai_amount'];
        $tax_rate    = $new_tax_rate ?? (float)$current['commission_tax_rate'];
        $biaya_polis = $new_biaya    ?? (int)$current['biaya_polis'];
        $diskon      = $new_diskon   ?? (int)$current['diskon'];

        $comm_amount  = (int)round($premium * $rate / 100);
        $tax_amount   = (int)round($comm_amount * $tax_rate);
        $net_comm     = $comm_amount - $tax_amount;
        $cust_premium = $premium + $materai + $biaya_polis - $comm_amount - $diskon;

        $updates[] = "commission_amount = $comm_amount";
        $updates[] = "commission_tax_amount = $tax_amount";
        $updates[] = "net_commission_amount = $net_comm";
        $updates[] = "customer_premium_amount = $cust_premium";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET " . implode(', ', $updates) . " WHERE policy_id = '$policy_id' AND company_id = '$company_id'")) {
        $label_map = [
            'object_insured'      => 'objek pertanggungan',
            'sum_insured'         => 'uang pertanggungan',
            'coverage_notes'      => 'catatan pertanggungan',
            'construction_class'  => 'kelas konstruksi',
            'coverage_start'      => 'mulai pertanggungan',
            'coverage_end'        => 'akhir pertanggungan',
            'premium_amount'      => 'premi',
            'materai_amount'      => 'materai',
            'biaya_polis'         => 'biaya polis',
            'diskon'              => 'diskon',
            'commission_rate'     => 'rate komisi',
            'commission_tax_rate' => 'rate pajak komisi',
            'notes'               => 'catatan',
        ];
        $changed_labels = [];
        $before = [];
        $after  = [];
        foreach ($label_map as $field => $label) {
            if (isset($input[$field])) {
                $changed_labels[] = $label;
                $before[$field]   = $current[$field] ?? null;
                $after[$field]    = $input[$field];
            }
        }
        $desc = 'Polis diperbarui (koreksi)' . (!empty($changed_labels) ? ': ' . implode(', ', $changed_labels) : '');

        insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', $desc, $username,
            null, null, null, null, ['before' => $before, 'after' => $after]);

        if ($new_premium !== null || $new_rate !== null || $new_materai !== null || $new_tax_rate !== null) {
            $final_premium  = $new_premium  ?? (int)$current['premium_amount'];
            $final_rate     = $new_rate     ?? (float)$current['commission_rate'];
            $final_tax_rate = $new_tax_rate ?? (float)$current['commission_tax_rate'];
            $final_amount   = (int)round($final_premium * $final_rate / 100);
            syncCommission($conn, $policy_id, $final_premium, $final_rate, $final_amount, $final_tax_rate);
        }

        jsonResponse(200, 'Policy updated successfully');
    } else {
        jsonResponse(500, 'Failed to update policy', ['error' => mysqli_error($conn)]);
    }
}

// --- UPDATE RENEWAL STATUS (PO-007) ---
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

// --- CONFIRM RENEWAL (PO-009) ---
// Creates the next-term policy for a renewal, linking it back via previous_policy_id,
// then marks the expiring policy as 'renewed'. Any field not supplied in $input is
// carried over from the policy being renewed — only the fields that actually differ
// need to be sent: policy_number, coverage_start/coverage_end (periode), coverages
// (rate + coverage item breakdown), and customer_id (nama tertanggung).
function confirmRenewal($conn, $policy_id, $company_id, $input, $username) {
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $old = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
    if (!$old || mysqli_num_rows($old) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }
    $prev = mysqli_fetch_assoc($old);

    if ($prev['renewal_status'] === 'renewed') {
        jsonResponse(409, 'Policy has already been renewed');
        return;
    }

    $already = mysqli_query($conn, "SELECT policy_id FROM " . APP_SCHEMA . ".policies WHERE previous_policy_id = '$policy_id' LIMIT 1");
    if ($already && mysqli_num_rows($already) > 0) {
        jsonResponse(409, 'A renewal policy already exists for this policy');
        return;
    }

    if (!isset($input['policy_number']) || trim($input['policy_number']) === '') {
        jsonResponse(400, 'policy_number is required for the renewed policy');
        return;
    }
    $policy_number = trim(mysqli_real_escape_string($conn, $input['policy_number']));

    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policies WHERE company_id = '$company_id' AND policy_number = '$policy_number' LIMIT 1");
    if (mysqli_num_rows($dup) > 0) {
        jsonResponse(409, 'Policy number already exists for this company');
        return;
    }

    // Periode: auto-extend 1 year from the previous term unless explicitly overridden
    if (isset($input['coverage_start']) || isset($input['coverage_end'])) {
        $coverage_start = isset($input['coverage_start']) ? trim(mysqli_real_escape_string($conn, $input['coverage_start'])) : '';
        $coverage_end   = isset($input['coverage_end'])   ? trim(mysqli_real_escape_string($conn, $input['coverage_end']))   : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverage_start)) {
            jsonResponse(400, 'coverage_start must be YYYY-MM-DD format');
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverage_end)) {
            jsonResponse(400, 'coverage_end must be YYYY-MM-DD format');
            return;
        }
    } else {
        $coverage_start = date('Y-m-d', strtotime($prev['coverage_start'] . ' +1 year'));
        $coverage_end   = date('Y-m-d', strtotime($prev['coverage_end']   . ' +1 year'));
    }
    if ($coverage_end <= $coverage_start) {
        jsonResponse(400, 'coverage_end must be after coverage_start');
        return;
    }

    // Nama tertanggung: defaults to the same customer, override only when it changes
    $customer_id = isset($input['customer_id']) && $input['customer_id'] !== ''
        ? mysqli_real_escape_string($conn, $input['customer_id'])
        : $prev['customer_id'];

    if ($customer_id !== $prev['customer_id']) {
        $cust_check = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' AND company_id = '$company_id' LIMIT 1");
        if (mysqli_num_rows($cust_check) === 0) {
            jsonResponse(404, 'Customer not found');
            return;
        }
    }

    // Previous coverage breakdown — used both as the default and as the "before" side of the diff
    $prev_cov_res  = mysqli_query($conn,
        "SELECT coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, count_in_tsi
         FROM " . APP_SCHEMA . ".policy_coverages WHERE policy_id = '$policy_id'
         ORDER BY FIELD(coverage_type, 'bangunan','stok','invenisi','mesin','dll'), coverage_label ASC");
    $prev_coverages = $prev_cov_res ? mysqli_fetch_all($prev_cov_res, MYSQLI_ASSOC) : [];

    // Coverage item(s) + rate: override with `coverages`, or carry over the previous breakdown
    if (isset($input['coverages']) && is_array($input['coverages'])) {
        $coverage_items = [];
        foreach ($input['coverages'] as $c) {
            foreach (['coverage_type', 'sum_insured', 'rate_permille'] as $f) {
                if (!isset($c[$f]) || $c[$f] === '') {
                    jsonResponse(400, "coverages[].$f is required");
                    return;
                }
            }
            $type = strtolower(trim(mysqli_real_escape_string($conn, $c['coverage_type'])));
            $valid_coverage_types = ['bangunan', 'stok', 'invenisi', 'mesin', 'dll'];
            if (!in_array($type, $valid_coverage_types, true)) {
                jsonResponse(400, 'coverage_type must be one of: ' . implode(', ', $valid_coverage_types));
                return;
            }
            $sum_insured   = (int)$c['sum_insured'];
            $rate_permille = (float)$c['rate_permille'];
            if ($sum_insured < 0 || $rate_permille < 0) {
                jsonResponse(400, 'coverages[].sum_insured and rate_permille must be non-negative');
                return;
            }
            $coverage_items[] = [
                'coverage_type'  => $type,
                'coverage_label' => isset($c['coverage_label']) && trim($c['coverage_label']) !== ''
                    ? trim($c['coverage_label']) : null,
                'sum_insured'    => $sum_insured,
                'rate_permille'  => number_format($rate_permille, 4, '.', ''),
                'premium_amount' => (int)round($sum_insured * $rate_permille / 1000),
                'count_in_tsi'   => isset($c['count_in_tsi']) ? ($c['count_in_tsi'] ? 1 : 0) : 1,
            ];
        }
    } else {
        $coverage_items = $prev_coverages;
    }

    // Sum insured / premium derived from the coverage breakdown (falls back to the previous
    // policy's own totals if it had no coverage rows at all).
    if (!empty($coverage_items)) {
        $sum_insured    = array_sum(array_map(fn($c) => $c['count_in_tsi'] ? (int)$c['sum_insured'] : 0, $coverage_items));
        $premium_amount = array_sum(array_map(fn($c) => (int)$c['premium_amount'], $coverage_items));
    } else {
        $sum_insured    = (int)$prev['sum_insured'];
        $premium_amount = (int)$prev['premium_amount'];
    }

    $commission_rate     = isset($input['commission_rate'])     ? round((float)$input['commission_rate'], 2)     : (float)$prev['commission_rate'];
    $commission_tax_rate = isset($input['commission_tax_rate']) ? round((float)$input['commission_tax_rate'], 4) : (float)$prev['commission_tax_rate'];
    $materai_amount      = isset($input['materai_amount'])      ? max(0, (int)$input['materai_amount'])          : (int)$prev['materai_amount'];
    $biaya_polis         = isset($input['biaya_polis'])         ? max(0, (int)$input['biaya_polis'])             : (int)$prev['biaya_polis'];
    $diskon              = isset($input['diskon'])              ? max(0, (int)$input['diskon'])                 : (int)$prev['diskon'];

    $commission_amount       = (int)round($premium_amount * $commission_rate / 100);
    $commission_tax_amount   = (int)round($commission_amount * $commission_tax_rate);
    $net_commission_amount   = $commission_amount - $commission_tax_amount;
    $customer_premium_amount = $premium_amount + $materai_amount + $biaya_polis - $commission_amount - $diskon;

    $object_insured = isset($input['object_insured'])
        ? (trim($input['object_insured']) !== '' ? "'" . mysqli_real_escape_string($conn, trim($input['object_insured'])) . "'" : 'NULL')
        : ($prev['object_insured'] !== null ? "'" . mysqli_real_escape_string($conn, $prev['object_insured']) . "'" : 'NULL');
    $coverage_notes = isset($input['coverage_notes'])
        ? (trim($input['coverage_notes']) !== '' ? "'" . mysqli_real_escape_string($conn, trim($input['coverage_notes'])) . "'" : 'NULL')
        : ($prev['coverage_notes'] !== null ? "'" . mysqli_real_escape_string($conn, $prev['coverage_notes']) . "'" : 'NULL');
    $notes = isset($input['notes'])
        ? (trim($input['notes']) !== '' ? "'" . mysqli_real_escape_string($conn, trim($input['notes'])) . "'" : 'NULL')
        : ($prev['notes'] !== null ? "'" . mysqli_real_escape_string($conn, $prev['notes']) . "'" : 'NULL');

    $valid_classes = ['I', 'II', 'III'];
    $construction_class = isset($input['construction_class']) && in_array(strtoupper(trim($input['construction_class'])), $valid_classes, true)
        ? "'" . strtoupper(trim($input['construction_class'])) . "'"
        : ($prev['construction_class'] !== null ? "'" . mysqli_real_escape_string($conn, $prev['construction_class']) . "'" : 'NULL');

    $issuing_agent_id = isset($input['issuing_agent_id'])
        ? ($input['issuing_agent_id'] !== '' ? "'" . mysqli_real_escape_string($conn, $input['issuing_agent_id']) . "'" : 'NULL')
        : ($prev['issuing_agent_id'] !== null ? "'" . mysqli_real_escape_string($conn, $prev['issuing_agent_id']) . "'" : 'NULL');

    $agent_code_used = $prev['agent_code_used'] !== null
        ? "'" . mysqli_real_escape_string($conn, $prev['agent_code_used']) . "'" : 'NULL';
    $insurer_id   = mysqli_real_escape_string($conn, $prev['insurer_id']);
    $product_type = mysqli_real_escape_string($conn, $prev['product_type']);

    $new_policy_id = 'pol_' . uniqid();
    $now           = date('Y-m-d H:i:s');
    $policy_year   = (int)$prev['policy_year'] + 1;

    $sql = "INSERT INTO " . APP_SCHEMA . ".policies
        (policy_id, company_id, insurer_id, customer_id, issuing_agent_id, policy_number, agent_code_used,
         product_type, policy_year, previous_policy_id, object_insured, sum_insured, coverage_notes, construction_class,
         coverage_start, coverage_end,
         premium_amount, materai_amount, biaya_polis, diskon,
         renewal_status, payment_status,
         commission_rate, commission_amount,
         commission_tax_rate, commission_tax_amount, net_commission_amount, customer_premium_amount,
         is_coassurance, notes, created_by, created_at)
        VALUES
        ('$new_policy_id', '$company_id', '$insurer_id', '$customer_id', $issuing_agent_id, '$policy_number', $agent_code_used,
         '$product_type', $policy_year, '$policy_id', $object_insured, $sum_insured, $coverage_notes, $construction_class,
         '$coverage_start', '$coverage_end',
         $premium_amount, $materai_amount, $biaya_polis, $diskon,
         'pending', 'unpaid',
         $commission_rate, $commission_amount,
         $commission_tax_rate, $commission_tax_amount, $net_commission_amount, $customer_premium_amount,
         " . (int)$prev['is_coassurance'] . ", $notes, '$username', '$now')";

    if (!mysqli_query($conn, $sql)) {
        jsonResponse(500, 'Failed to create renewed policy', ['error' => mysqli_error($conn)]);
        return;
    }

    foreach ($coverage_items as $c) {
        $coverage_id = 'cov_' . uniqid();
        $label = $c['coverage_label'] !== null && $c['coverage_label'] !== ''
            ? "'" . mysqli_real_escape_string($conn, $c['coverage_label']) . "'" : 'NULL';
        mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policy_coverages
            (coverage_id, policy_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, count_in_tsi, created_by, created_at)
            VALUES ('$coverage_id', '$new_policy_id', '{$c['coverage_type']}', $label, {$c['sum_insured']}, {$c['rate_permille']}, {$c['premium_amount']}, {$c['count_in_tsi']}, '$username', '$now')");
    }

    insertCommission($conn, $new_policy_id, $company_id, $prev['insurer_id'], $premium_amount, $commission_rate, $commission_amount,
        $prev['issuing_agent_id'] ?? null, $commission_tax_rate);

    // Mark the expiring policy as renewed
    mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".policies SET renewal_status = 'renewed', updated_by = '$username', updated_at = '$now'
         WHERE policy_id = '$policy_id' AND company_id = '$company_id'");

    // Diff of the fields that are known to change on renewal — only included when they actually differ
    $normalizeCoverages = fn($items) => array_map(
        fn($c) => [
            'coverage_type'  => $c['coverage_type'],
            'coverage_label' => $c['coverage_label'],
            'sum_insured'    => (int)$c['sum_insured'],
            'rate_permille'  => (float)$c['rate_permille'],
        ],
        $items
    );

    $changes = [];
    if ($policy_number !== $prev['policy_number']) {
        $changes['policy_number'] = ['from' => $prev['policy_number'], 'to' => $policy_number];
    }
    if ($coverage_start !== $prev['coverage_start'] || $coverage_end !== $prev['coverage_end']) {
        $changes['periode'] = [
            'from' => ['coverage_start' => $prev['coverage_start'], 'coverage_end' => $prev['coverage_end']],
            'to'   => ['coverage_start' => $coverage_start, 'coverage_end' => $coverage_end],
        ];
    }
    $prevCoverageSummary = $normalizeCoverages($prev_coverages);
    $newCoverageSummary  = $normalizeCoverages($coverage_items);
    if (json_encode($prevCoverageSummary) !== json_encode($newCoverageSummary)) {
        $changes['coverage_item'] = ['from' => $prevCoverageSummary, 'to' => $newCoverageSummary];
    }
    if ($customer_id !== $prev['customer_id']) {
        $name_res = mysqli_query($conn, "SELECT display_name, company_legal_name FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$customer_id' LIMIT 1");
        $name_row = $name_res ? mysqli_fetch_assoc($name_res) : null;
        $changes['nama_tertanggung'] = [
            'from' => $prev['customer_id'],
            'to'   => $customer_id,
            'to_name' => $name_row ? ($name_row['company_legal_name'] ?: $name_row['display_name']) : null,
        ];
    }

    insertPolicyLog($conn, $policy_id, $company_id, 'renewal_status_changed',
        "Renewal dikonfirmasi → polis baru $policy_number ($new_policy_id)", $username,
        'pending', 'renewed', 'policy', $new_policy_id, ['changes' => $changes]);
    insertPolicyLog($conn, $new_policy_id, $company_id, 'policy_created',
        "Polis dibuat dari renewal polis {$prev['policy_number']}", $username,
        null, null, 'policy', $policy_id);

    jsonResponse(201, 'Renewal confirmed, new policy created', [
        'policy_id'          => $new_policy_id,
        'previous_policy_id' => $policy_id,
        'policy_number'      => $policy_number,
        'coverage_start'     => $coverage_start,
        'coverage_end'       => $coverage_end,
        'changes'            => $changes,
    ]);
}

// --- ADD FOLLOW-UP (PO-008) ---
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

// --- UPDATE PAYMENT STATUS (PO-009, Main Agent only) ---
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

// --- PAYMENT SUMMARY (PO-010, Main Agent only) ---
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

// --- GET COMMISSION (PO-011) ---
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

// --- GET POLICY LOGS (PO-012) ---
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

// $action     = $parts[3] — policy_id, 'payment-summary', 'export', or ''
// $sub_action = $parts[4] — 'renewal-status', 'follow-ups', 'payment-status', 'coverages', etc.
$policy_id  = (!empty($action) && !in_array($action, ['payment-summary', 'export'], true)) ? $action : null;
$sub_action = $parts[4] ?? '';

try {
    $conn = getConn();

    // GET /api/v1/policies/payment-summary
    if ($action === 'payment-summary') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getPaymentSummary($conn, $company_id, $_GET);

    // GET /api/v1/policies/export
    } elseif ($action === 'export') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        exportPolicies($conn, $company_id, $_GET);

    // /api/v1/policies/{policy_id}/{sub-action}
    } elseif ($policy_id && $sub_action !== '') {
        $input = in_array($method, ['POST', 'PUT', 'PATCH'])
            ? (json_decode(file_get_contents('php://input'), true) ?? [])
            : [];

        switch ($sub_action) {
            case 'renewal-status':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                updateRenewalStatus($conn, $policy_id, $company_id, $input, $username);
                break;
            case 'confirm-renewal':
                if ($method !== 'POST') { jsonResponse(405, 'Method Not Allowed'); }
                requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                confirmRenewal($conn, $policy_id, $company_id, $input, $username);
                break;
            case 'follow-ups':
                if ($method !== 'POST') { jsonResponse(405, 'Method Not Allowed'); }
                requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                addFollowUp($conn, $policy_id, $company_id, $input, $username);
                break;
            case 'payment-status':
                if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
                requireRole($authUser, ['owner', 'admin']); // financial reconciliation — not a subagent action
                updatePaymentStatus($conn, $policy_id, $company_id, $input, $username);
                break;
            case 'coverages':
                if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                    requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                }
                require __DIR__ . '/coverages.php';
                break;
            case 'commission':
                if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
                getCommission($conn, $policy_id, $company_id);
                break;
            // [NEW v1.1] Co-assurance participants for this policy.
            case 'coassurance':
                if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                    requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                }
                require __DIR__ . '/coassurance.php';
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
                requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updatePolicy($conn, $policy_id, $input, $username, $company_id);
                break;
            case 'PATCH':
                requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                directUpdatePolicy($conn, $policy_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                requireOwnRecordOrAdmin($authUser, resolvePolicyOwnerId($conn, $policy_id, $company_id));
                deletePolicy($conn, $policy_id, $company_id);
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
                // Subagents may only create policies assigned to themselves.
                if (($authUser['agentra_role'] ?? 'owner') === 'subagent') {
                    $input['issuing_agent_id'] = $username;
                }
                createPolicy($conn, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
