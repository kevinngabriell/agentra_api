<?php
// [NEW v1.1] Renew a policy — creates the next policy_year record from an existing one.
// Route: POST /api/v1/policies/{policy_id}/renew
// $conn, $policy_id (= source policy), $company_id, $username, $method are already
// available from the parent policies/index.php dispatcher scope.

if ($method !== 'POST') {
    jsonResponse(405, 'Method Not Allowed');
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['policy_number']) || trim($input['policy_number']) === '') {
    jsonResponse(400, 'policy_number is required (the new policy number for the renewed period)');
}

$policy_id = mysqli_real_escape_string($conn, $policy_id);

$srcRes = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1");
if (!$srcRes || mysqli_num_rows($srcRes) === 0) {
    jsonResponse(404, 'Policy not found');
}
$src = mysqli_fetch_assoc($srcRes);

if (in_array($src['renewal_status'], ['renewed', 'cancelled'], true)) {
    jsonResponse(409, "Policy is already {$src['renewal_status']} and cannot be renewed again");
}

$new_policy_number = trim(mysqli_real_escape_string($conn, $input['policy_number']));
$dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policies WHERE company_id = '$company_id' AND policy_number = '$new_policy_number' LIMIT 1");
if (mysqli_num_rows($dup) > 0) {
    jsonResponse(409, 'Policy number already exists for this company');
}

// ── Periode: defaults to contiguous with the expiring policy, auto +365 days ──
$coverage_start = isset($input['coverage_start']) && trim($input['coverage_start']) !== ''
    ? trim(mysqli_real_escape_string($conn, $input['coverage_start']))
    : date('Y-m-d', strtotime($src['coverage_end'] . ' +1 day'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverage_start)) {
    jsonResponse(400, 'coverage_start must be YYYY-MM-DD format');
}

// [Auto +365] Only applied when coverage_end is not explicitly provided —
// this is the "customer agrees to renew as-is" default.
$coverage_end = isset($input['coverage_end']) && trim($input['coverage_end']) !== ''
    ? trim(mysqli_real_escape_string($conn, $input['coverage_end']))
    : date('Y-m-d', strtotime($coverage_start . ' +365 day'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $coverage_end)) {
    jsonResponse(400, 'coverage_end must be YYYY-MM-DD format');
}
if ($coverage_end <= $coverage_start) {
    jsonResponse(400, 'coverage_end must be after coverage_start');
}

// ── Rate: defaults to the expiring policy's snapshot ──
$commission_rate     = isset($input['commission_rate']) && $input['commission_rate'] !== ''
    ? round((float)$input['commission_rate'], 2) : (float)$src['commission_rate'];
$commission_tax_rate = isset($input['commission_tax_rate']) && $input['commission_tax_rate'] !== ''
    ? round((float)$input['commission_tax_rate'], 4) : (float)$src['commission_tax_rate'];

// ── Nama tertanggung (optional override) ──
$insured_name = isset($input['insured_name'])
    ? (trim($input['insured_name']) !== '' ? "'" . mysqli_real_escape_string($conn, trim($input['insured_name'])) . "'" : 'NULL')
    : ($src['insured_name'] !== null ? "'" . mysqli_real_escape_string($conn, $src['insured_name']) . "'" : 'NULL');

// ── Fields carried forward, individually overridable ──
$object_insured    = isset($input['object_insured']) ? trim($input['object_insured']) : $src['object_insured'];
$coverage_notes    = isset($input['coverage_notes']) ? trim($input['coverage_notes']) : $src['coverage_notes'];
$notes             = isset($input['notes'])          ? trim($input['notes'])          : $src['notes'];
$construction_class = $src['construction_class'];
if (isset($input['construction_class'])) {
    $cc = strtoupper(trim((string)$input['construction_class']));
    $construction_class = in_array($cc, ['I', 'II', 'III'], true) ? $cc : null;
}

// [NEW v1.2.0] Risk location — carried forward from the source policy (the insured
// building essentially never changes on renewal), individually overridable.
$risk_address  = isset($input['risk_address'])  ? trim($input['risk_address'])  : $src['risk_address'];
$risk_village  = isset($input['risk_village'])  ? trim($input['risk_village'])  : $src['risk_village'];
$risk_district = isset($input['risk_district']) ? trim($input['risk_district']) : $src['risk_district'];
$risk_city     = isset($input['risk_city'])     ? trim($input['risk_city'])     : $src['risk_city'];
$risk_province = isset($input['risk_province']) ? trim($input['risk_province']) : $src['risk_province'];

$risk_postal_code = isset($input['risk_postal_code']) ? trim($input['risk_postal_code']) : $src['risk_postal_code'];
if ($risk_postal_code !== null && $risk_postal_code !== '' && !preg_match('/^\d{5}$/', $risk_postal_code)) {
    jsonResponse(400, 'risk_postal_code must be a 5-digit Indonesian postal code (e.g. "17530")');
}

if (isset($input['risk_latitude']) || isset($input['risk_longitude'])) {
    $risk_lat_raw = $input['risk_latitude']  ?? null;
    $risk_lng_raw = $input['risk_longitude'] ?? null;
    $has_risk_lat = $risk_lat_raw !== null && $risk_lat_raw !== '';
    $has_risk_lng = $risk_lng_raw !== null && $risk_lng_raw !== '';
    if ($has_risk_lat xor $has_risk_lng) {
        jsonResponse(400, 'risk_latitude and risk_longitude must be provided together');
    }
    if ($has_risk_lat && $has_risk_lng) {
        if (!is_numeric($risk_lat_raw) || !is_numeric($risk_lng_raw)) {
            jsonResponse(400, 'risk_latitude and risk_longitude must be numbers');
        }
        $risk_latitude  = (float)$risk_lat_raw;
        $risk_longitude = (float)$risk_lng_raw;
        if ($risk_latitude < -90 || $risk_latitude > 90) {
            jsonResponse(400, 'risk_latitude must be between -90 and 90');
        }
        if ($risk_longitude < -180 || $risk_longitude > 180) {
            jsonResponse(400, 'risk_longitude must be between -180 and 180');
        }
    } else {
        $risk_latitude  = null;
        $risk_longitude = null;
    }
} else {
    $risk_latitude  = $src['risk_latitude']  !== null ? (float)$src['risk_latitude']  : null;
    $risk_longitude = $src['risk_longitude'] !== null ? (float)$src['risk_longitude'] : null;
}

$materai_amount = isset($input['materai_amount']) ? max(0, (int)$input['materai_amount']) : (int)$src['materai_amount'];
$biaya_polis    = isset($input['biaya_polis'])    ? max(0, (int)$input['biaya_polis'])    : (int)$src['biaya_polis'];
$diskon         = isset($input['diskon'])         ? max(0, (int)$input['diskon'])         : (int)$src['diskon'];

// ── Item pertanggungan (policy_coverages): copy from source, recompute totals from the copy ──
$covRes = mysqli_query($conn, "SELECT coverage_type, coverage_label, sum_insured, rate_permille, count_in_tsi
    FROM " . APP_SCHEMA . ".policy_coverages WHERE policy_id = '$policy_id'");
$sourceCoverages = $covRes ? mysqli_fetch_all($covRes, MYSQLI_ASSOC) : [];

if (!empty($sourceCoverages)) {
    $sum_insured    = 0;
    $premium_amount = 0;
} else {
    // No line-item breakdown on the source policy — fall back to its scalar totals (overridable).
    $sum_insured    = isset($input['sum_insured'])    ? (int)$input['sum_insured']    : (int)$src['sum_insured'];
    $premium_amount = isset($input['premium_amount']) ? (int)$input['premium_amount'] : (int)$src['premium_amount'];
}

$commission_amount       = (int)round($premium_amount * $commission_rate / 100);
$commission_tax_amount   = (int)round($commission_amount * $commission_tax_rate);
$net_commission_amount   = $commission_amount - $commission_tax_amount;
$customer_premium_amount = $premium_amount + $materai_amount + $biaya_polis - $commission_amount - $diskon;

$new_policy_id = 'pol_' . uniqid();
$now           = date('Y-m-d H:i:s');
$policy_year   = (int)$src['policy_year'] + 1;

$oi  = $object_insured !== null && $object_insured !== '' ? "'" . mysqli_real_escape_string($conn, $object_insured) . "'" : 'NULL';
$cn  = $coverage_notes  !== null && $coverage_notes  !== '' ? "'" . mysqli_real_escape_string($conn, $coverage_notes)  . "'" : 'NULL';
$nt  = $notes           !== null && $notes           !== '' ? "'" . mysqli_real_escape_string($conn, $notes)           . "'" : 'NULL';
$cc  = $construction_class ? "'" . mysqli_real_escape_string($conn, $construction_class) . "'" : 'NULL';
$iag = $src['issuing_agent_id'] ? "'" . mysqli_real_escape_string($conn, $src['issuing_agent_id']) . "'" : 'NULL';

$ra   = $risk_address     !== null && $risk_address     !== '' ? "'" . mysqli_real_escape_string($conn, $risk_address)     . "'" : 'NULL';
$rv   = $risk_village     !== null && $risk_village     !== '' ? "'" . mysqli_real_escape_string($conn, $risk_village)     . "'" : 'NULL';
$rd   = $risk_district    !== null && $risk_district    !== '' ? "'" . mysqli_real_escape_string($conn, $risk_district)    . "'" : 'NULL';
$rc   = $risk_city        !== null && $risk_city        !== '' ? "'" . mysqli_real_escape_string($conn, $risk_city)        . "'" : 'NULL';
$rp   = $risk_province    !== null && $risk_province    !== '' ? "'" . mysqli_real_escape_string($conn, $risk_province)    . "'" : 'NULL';
$rpc  = $risk_postal_code !== null && $risk_postal_code !== '' ? "'" . mysqli_real_escape_string($conn, $risk_postal_code) . "'" : 'NULL';
$rlat = $risk_latitude    !== null ? sprintf('%.7f', $risk_latitude)  : 'NULL';
$rlng = $risk_longitude   !== null ? sprintf('%.7f', $risk_longitude) : 'NULL';

$ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policies
    (policy_id, company_id, insurer_id, customer_id, issuing_agent_id, policy_number, agent_code_used,
     product_type, policy_year, previous_policy_id, object_insured, insured_name, sum_insured, coverage_notes, construction_class,
     risk_address, risk_village, risk_district, risk_city, risk_province, risk_postal_code, risk_latitude, risk_longitude,
     coverage_start, coverage_end,
     premium_amount, materai_amount, biaya_polis, diskon,
     renewal_status, payment_status,
     commission_rate, commission_amount,
     commission_tax_rate, commission_tax_amount, net_commission_amount, customer_premium_amount,
     is_coassurance, notes, created_by, created_at)
    VALUES
    ('$new_policy_id', '$company_id', '{$src['insurer_id']}', '{$src['customer_id']}', $iag, '$new_policy_number',
     " . ($src['agent_code_used'] ? "'" . mysqli_real_escape_string($conn, $src['agent_code_used']) . "'" : 'NULL') . ",
     '{$src['product_type']}', $policy_year, '$policy_id', $oi, $insured_name, $sum_insured, $cn, $cc,
     $ra, $rv, $rd, $rc, $rp, $rpc, $rlat, $rlng,
     '$coverage_start', '$coverage_end',
     $premium_amount, $materai_amount, $biaya_polis, $diskon,
     'pending', 'unpaid',
     $commission_rate, $commission_amount,
     $commission_tax_rate, $commission_tax_amount, $net_commission_amount, $customer_premium_amount,
     0, $nt, '$username', '$now')");

if (!$ok) {
    jsonResponse(500, 'Failed to create renewed policy', ['error' => mysqli_error($conn)]);
}

// Copy coverage line items onto the new policy, then derive sum_insured/premium_amount from them.
foreach ($sourceCoverages as $cov) {
    $coverage_id = 'cov_' . uniqid();
    $label = $cov['coverage_label'] !== null ? "'" . mysqli_real_escape_string($conn, $cov['coverage_label']) . "'" : 'NULL';
    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policy_coverages
        (coverage_id, policy_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, count_in_tsi, created_by, created_at)
        VALUES ('$coverage_id', '$new_policy_id', '{$cov['coverage_type']}', $label, {$cov['sum_insured']}, {$cov['rate_permille']}, {$cov['premium_amount']}, {$cov['count_in_tsi']}, '$username', '$now')");
}

if (!empty($sourceCoverages)) {
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies p
        SET p.sum_insured    = COALESCE((SELECT SUM(c.sum_insured)    FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$new_policy_id' AND c.count_in_tsi = 1), 0),
            p.premium_amount = COALESCE((SELECT SUM(c.premium_amount) FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$new_policy_id'), 0)
        WHERE p.policy_id = '$new_policy_id'");

    // Re-derive commission breakdown from the coverage-sourced premium.
    $totRes = mysqli_query($conn, "SELECT premium_amount FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$new_policy_id'");
    $finalPremium = (int)mysqli_fetch_assoc($totRes)['premium_amount'];
    $finalCommission = (int)round($finalPremium * $commission_rate / 100);
    $finalTax        = (int)round($finalCommission * $commission_tax_rate);
    $finalNet         = $finalCommission - $finalTax;
    $finalCustomer    = $finalPremium + $materai_amount + $biaya_polis - $finalCommission - $diskon;

    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies
        SET commission_amount = $finalCommission, commission_tax_amount = $finalTax,
            net_commission_amount = $finalNet, customer_premium_amount = $finalCustomer
        WHERE policy_id = '$new_policy_id'");

    $premium_amount     = $finalPremium;
    $commission_amount  = $finalCommission;
}

insertCommission($conn, $new_policy_id, $company_id, $src['insurer_id'], $premium_amount, $commission_rate, $commission_amount,
    $src['issuing_agent_id'] ?: null, $commission_tax_rate);

insertPolicyLog($conn, $new_policy_id, $company_id, 'policy_created',
    "Polis diperpanjang dari {$src['policy_number']}", $username,
    null, null, 'policies', $policy_id);

// Mark the expiring policy as renewed and link it forward.
mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies
    SET renewal_status = 'renewed', updated_by = '$username', updated_at = '$now'
    WHERE policy_id = '$policy_id'");

insertPolicyLog($conn, $policy_id, $company_id, 'renewal_status_changed',
    "Status renewal diubah: {$src['renewal_status']} → renewed (diperpanjang menjadi {$new_policy_number})",
    $username, $src['renewal_status'], 'renewed', 'policies', $new_policy_id);

jsonResponse(201, 'Policy renewed successfully', [
    'policy_id'          => $new_policy_id,
    'policy_number'      => $new_policy_number,
    'previous_policy_id' => $policy_id,
    'coverage_start'     => $coverage_start,
    'coverage_end'       => $coverage_end,
]);
