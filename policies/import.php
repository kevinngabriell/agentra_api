<?php
// [NEW v1.8.0] Bulk policy import (CSV / XLSX).
//   POST /policies/import                multipart: file, dry_run=1 to validate without saving
//   GET  /policies/import/template       ?format=csv|xlsx
//
// Each row becomes a policy with its commission record and a 'policy_created' log entry, exactly as
// POST /policies would create it (renewal pending, payment unpaid). Customers are matched by
// customer_identifier (NIK or NPWP) or, failing that, by an unambiguous exact customer_name; insurers by
// short name, agent code or full name. Coverage breakdowns, risk details and risk location are not imported.

require_once __DIR__ . '/../helpers/import.php';
require_once __DIR__ . '/../helpers/subscription.php';

const POLICY_IMPORT_TEMPLATE = [
    'policy_number' => '010-2026-000123', 'customer_identifier' => '3174012345678901', 'customer_name' => '',
    'insurer' => 'ACA', 'product_type' => 'fire', 'coverage_start' => '2026-01-01', 'coverage_end' => '2027-01-01',
    'sum_insured' => '500000000', 'premium_amount' => '2500000', 'commission_rate' => '', 'commission_tax_rate' => '',
    'materai_amount' => '0', 'biaya_polis' => '0', 'diskon' => '0', 'object_insured' => 'Ruko 2 lantai', 'insured_name' => '', 'notes' => '',
];

function policyImportLookups($conn, string $company_id): array {
    $ins = [];
    $res = mysqli_query($conn, "SELECT insurer_id, name, short_name, agent_code FROM " . APP_SCHEMA . ".insurers WHERE company_id = '$company_id' AND is_active = 1");
    foreach ($res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [] as $r) {
        foreach (['short_name', 'agent_code', 'name'] as $k) {
            $key = strtolower(trim((string)$r[$k]));
            if ($key !== '') $ins[$key][$r['insurer_id']] = $r;
        }
    }
    $prods = [];
    $res = mysqli_query($conn, "SELECT product_code FROM " . APP_SCHEMA . ".master_products WHERE company_id = '$company_id' AND is_active = 1");
    foreach ($res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [] as $r) $prods[strtolower($r['product_code'])] = true;
    return ['insurers' => $ins, 'products' => $prods];
}

function policyImportFindCustomer($conn, string $company_id, string $ident, string $name): array {
    $find = function (string $where) use ($conn, $company_id): array {
        $res = mysqli_query($conn, "SELECT customer_id, display_name FROM " . APP_SCHEMA . ".customers WHERE company_id = '$company_id' AND ($where) LIMIT 2");
        return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    };
    if ($ident !== '') {
        $digits = preg_replace('/\D/', '', $ident);
        $e = mysqli_real_escape_string($conn, $ident);
        $d = mysqli_real_escape_string($conn, $digits);
        $rows = $find("nik = '$e' OR npwp_company = '$e' OR npwp_personal = '$e'"
            . ($digits !== '' ? " OR REPLACE(REPLACE(REPLACE(npwp_company, '.', ''), '-', ''), ' ', '') = '$d' OR REPLACE(REPLACE(REPLACE(npwp_personal, '.', ''), '-', ''), ' ', '') = '$d'" : ''));
        if (count($rows) === 1) return ['customer' => $rows[0]];
        if (count($rows) > 1)   return ['error' => "customer_identifier \"$ident\" matches more than one customer"];
        if ($name === '')       return ['error' => "No customer with NIK/NPWP \"$ident\""];
    }
    if ($name !== '') {
        $rows = $find("LOWER(display_name) = '" . mysqli_real_escape_string($conn, strtolower($name)) . "'");
        if (count($rows) === 1) return ['customer' => $rows[0]];
        return ['error' => count($rows) > 1 ? "customer_name \"$name\" matches more than one customer; use customer_identifier" : "No customer named \"$name\""];
    }
    return ['error' => 'customer_identifier (or customer_name) is required'];
}

/** Validates a row. Returns ['errors' => [], 'row' => [...]] where row carries the resolved values and display info. */
function validatePolicyImportRow($conn, string $company_id, array $d, array $lookups, array &$seenNumbers, int $rowNum): array {
    $errors = [];
    $policy_number = trim((string)($d['policy_number'] ?? ''));
    if ($policy_number === '') $errors[] = 'policy_number is empty';
    elseif (isset($seenNumbers[strtolower($policy_number)])) $errors[] = "Duplicate policy_number in file (also on row {$seenNumbers[strtolower($policy_number)]})";
    elseif ($policy_number !== '') {
        $pn = mysqli_real_escape_string($conn, $policy_number);
        $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policies WHERE company_id = '$company_id' AND policy_number = '$pn' LIMIT 1");
        if ($dup && mysqli_num_rows($dup) > 0) $errors[] = 'Policy number already exists';
    }

    $cust = policyImportFindCustomer($conn, $company_id, trim((string)($d['customer_identifier'] ?? '')), trim((string)($d['customer_name'] ?? '')));
    if (isset($cust['error'])) $errors[] = $cust['error'];

    $insRef = trim((string)($d['insurer'] ?? ''));
    $insurer = null;
    if ($insRef === '') $errors[] = 'insurer is required';
    else {
        $matches = $lookups['insurers'][strtolower($insRef)] ?? [];
        if (count($matches) === 1) $insurer = reset($matches);
        else $errors[] = count($matches) > 1 ? "insurer \"$insRef\" matches more than one insurer" : "Unknown or inactive insurer \"$insRef\"";
    }

    $product = strtolower(trim((string)($d['product_type'] ?? '')));
    if ($product === '') $errors[] = 'product_type is required';
    elseif (!isset($lookups['products'][$product])) $errors[] = "Unknown product_type \"$product\"";

    $start = importNormalizeDate($d['coverage_start'] ?? '');
    $end   = importNormalizeDate($d['coverage_end'] ?? '');
    if ($start === null) $errors[] = 'coverage_start must be a valid date (YYYY-MM-DD)';
    if ($end === null)   $errors[] = 'coverage_end must be a valid date (YYYY-MM-DD)';
    if ($start && $end && $end <= $start) $errors[] = 'coverage_end must be after coverage_start';

    $int = function (string $key, bool $required) use ($d, &$errors): int {
        $v = trim((string)($d[$key] ?? ''));
        if ($v === '') { if ($required) $errors[] = "$key is required"; return 0; }
        $clean = preg_replace('/[.,\s]/', '', $v);
        if (!preg_match('/^\d+$/', $clean)) { $errors[] = "$key must be a non-negative integer"; return 0; }
        return (int)$clean;
    };
    $sum_insured = $int('sum_insured', true);
    $premium     = $int('premium_amount', true);
    $materai     = $int('materai_amount', false);
    $biaya       = $int('biaya_polis', false);
    $diskon      = $int('diskon', false);

    $rate = null;
    $rateRaw = trim((string)($d['commission_rate'] ?? ''));
    if ($rateRaw !== '') {
        if (!is_numeric($rateRaw) || (float)$rateRaw < 0 || (float)$rateRaw > 100) $errors[] = 'commission_rate must be a percentage between 0 and 100';
        else $rate = round((float)$rateRaw, 2);
    }
    $taxRate = null;
    $taxRaw = trim((string)($d['commission_tax_rate'] ?? ''));
    if ($taxRaw !== '') {
        if (!is_numeric($taxRaw) || (float)$taxRaw < 0 || (float)$taxRaw > 1) $errors[] = 'commission_tax_rate must be a decimal between 0 and 1 (0.025 = 2.5%)';
        else $taxRate = round((float)$taxRaw, 4);
    }
    if (!$errors && $rate === null) {
        $resolved = resolveCommissionRateFromDB($conn, $company_id, $product, $policy_number);
        if ($resolved === null) $errors[] = 'commission_rate is required: no commission rule found for this product or policy number prefix';
        else {
            $rate = $resolved['rate'];
            if ($taxRate === null) $taxRate = round((float)$resolved['tax_rate'], 4);
        }
    }
    if (!$errors && $taxRate === null) {
        $resolved = resolveCommissionRateFromDB($conn, $company_id, $product, $policy_number);
        $taxRate = $resolved ? round((float)$resolved['tax_rate'], 4) : 0.0;
    }

    return ['errors' => $errors, 'row' => [
        'policy_number' => $policy_number, 'customer_id' => $cust['customer']['customer_id'] ?? null,
        'customer_name' => $cust['customer']['display_name'] ?? null,
        'insurer_id' => $insurer['insurer_id'] ?? null, 'insurer_name' => $insurer ? ($insurer['short_name'] ?: $insurer['name']) : null,
        'agent_code' => $insurer['agent_code'] ?? null,
        'product_type' => $product, 'coverage_start' => $start, 'coverage_end' => $end,
        'sum_insured' => $sum_insured, 'premium_amount' => $premium, 'materai_amount' => $materai, 'biaya_polis' => $biaya, 'diskon' => $diskon,
        'commission_rate' => $rate, 'commission_tax_rate' => $taxRate,
        'object_insured' => trim((string)($d['object_insured'] ?? '')), 'insured_name' => trim((string)($d['insured_name'] ?? '')),
        'notes' => trim((string)($d['notes'] ?? '')),
    ]];
}

function insertImportedPolicy($conn, string $company_id, string $username, array $r): bool {
    $e   = fn($v) => mysqli_real_escape_string($conn, (string)$v);
    $n   = fn($v) => $v === '' || $v === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
    $policy_id = 'pol_' . bin2hex(random_bytes(10));
    $now = date('Y-m-d H:i:s');

    // Same derivations as createPolicy.
    $commission_amount = (int)round($r['premium_amount'] * $r['commission_rate'] / 100);
    $tax_amount        = (int)round($commission_amount * $r['commission_tax_rate']);
    $net_commission    = $commission_amount - $tax_amount;
    $customer_premium  = $r['premium_amount'] + $r['materai_amount'] + $r['biaya_polis'] - $commission_amount - $r['diskon'];

    $ok = mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policies
         (policy_id, company_id, insurer_id, customer_id, policy_number, agent_code_used, product_type, policy_year,
          object_insured, insured_name, sum_insured, coverage_start, coverage_end,
          premium_amount, materai_amount, biaya_polis, diskon, renewal_status, payment_status,
          commission_rate, commission_amount, commission_tax_rate, commission_tax_amount, net_commission_amount, customer_premium_amount,
          is_coassurance, notes, created_by, created_at)
         VALUES
         ('$policy_id', '$company_id', '{$e($r['insurer_id'])}', '{$e($r['customer_id'])}', '{$e($r['policy_number'])}', {$n($r['agent_code'])}, '{$e($r['product_type'])}', 1,
          {$n($r['object_insured'])}, {$n($r['insured_name'])}, {$r['sum_insured']}, '{$r['coverage_start']}', '{$r['coverage_end']}',
          {$r['premium_amount']}, {$r['materai_amount']}, {$r['biaya_polis']}, {$r['diskon']}, 'pending', 'unpaid',
          {$r['commission_rate']}, $commission_amount, {$r['commission_tax_rate']}, $tax_amount, $net_commission, $customer_premium,
          0, {$n($r['notes'])}, '{$e($username)}', '$now')");
    if (!$ok) { error_log('importPolicies insert failed: ' . mysqli_error($conn)); return false; }

    if (!insertCommission($conn, $policy_id, $company_id, $r['insurer_id'], $r['premium_amount'], $r['commission_rate'], $commission_amount, null, $r['commission_tax_rate'])) return false;
    insertPolicyLog($conn, $policy_id, $company_id, 'policy_created', 'Polis dibuat (import)', $username);
    return true;
}

function importPolicies($conn, string $company_id, string $username): void {
    $table = importReadUpload();
    foreach (['policy_number', 'insurer', 'product_type', 'coverage_start', 'coverage_end', 'sum_insured', 'premium_amount'] as $required) {
        if (!in_array($required, $table['headers'], true)) jsonResponse(400, "Missing required column: $required");
    }
    if (!in_array('customer_identifier', $table['headers'], true) && !in_array('customer_name', $table['headers'], true)) {
        jsonResponse(400, 'Missing customer column: customer_identifier or customer_name');
    }
    $dryRun = in_array(strtolower((string)($_POST['dry_run'] ?? '')), ['1', 'true'], true);

    $lookups = policyImportLookups($conn, $company_id);
    $seenNumbers = [];
    $valid = $failed = $preview = [];

    foreach ($table['rows'] as $r) {
        $res = validatePolicyImportRow($conn, $company_id, $r['data'], $lookups, $seenNumbers, $r['row']);
        if ($res['errors']) {
            $failed[] = ['row' => $r['row'], 'reason' => implode('; ', $res['errors'])];
        } else {
            $valid[$r['row']] = $res['row'];
            $seenNumbers[strtolower($res['row']['policy_number'])] = $r['row'];
        }
        if (count($preview) < IMPORT_PREVIEW_ROWS) {
            $x = $res['row'];
            $preview[] = [
                'row' => $r['row'], 'policy_number' => $x['policy_number'], 'customer_name' => $x['customer_name'],
                'insurer_name' => $x['insurer_name'], 'product_type' => $x['product_type'],
                'premium_amount' => $x['premium_amount'], 'errors' => $res['errors'],
            ];
        }
    }

    if ($dryRun) {
        jsonResponse(200, 'Import preview', [
            'dry_run' => true, 'total' => count($table['rows']), 'valid_count' => count($valid),
            'failed_count' => count($failed), 'failed' => $failed, 'preview' => $preview,
        ]);
    }

    assertPolicyQuota($conn, $company_id, count($valid));

    $inserted = 0;
    mysqli_begin_transaction($conn);
    try {
        foreach ($valid as $rowNum => $row) {
            // Savepoint per row so one failing insert doesn't discard the policies that already went in.
            mysqli_query($conn, 'SAVEPOINT import_row');
            if (insertImportedPolicy($conn, $company_id, $username, $row)) {
                mysqli_query($conn, 'RELEASE SAVEPOINT import_row');
                $inserted++;
            } else {
                mysqli_query($conn, 'ROLLBACK TO SAVEPOINT import_row');
                $failed[] = ['row' => $rowNum, 'reason' => 'Database error'];
            }
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        error_log('importPolicies exception: ' . $e->getMessage());
        Response::error('ERR_COMMON_005', 'Import gagal — tidak ada baris yang berhasil disimpan', 500);
    }

    jsonResponse(200, "Import completed. $inserted policy(ies) imported.", [
        'dry_run' => false, 'total' => count($table['rows']), 'inserted' => $inserted,
        'failed_count' => count($failed), 'failed' => $failed,
    ]);
}
