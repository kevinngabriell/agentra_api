<?php

// Product-specific risk details (migration_policy_risk_details.sql). Functions only; routing is in policies/index.php.
//
//   GET    /policies/{id}/risk-details                    details + members for the policy's risk group
//   PUT    /policies/{id}/risk-details                    replace details and/or members ({risk_details, members})
//   GET    /policies/{id}/declarations                    cargo open cover: declarations and totals
//   POST   /policies/{id}/declarations                    add a declaration
//   PUT    /policies/{id}/declarations/{declaration_id}   edit a declaration
//   DELETE /policies/{id}/declarations/{declaration_id}   remove a declaration

require_once __DIR__ . '/../helpers/risk_details.php';

function riskFindPolicy($conn, string $policy_id, string $company_id): array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT policy_id, policy_number, product_type
        FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_RISK_001');
    return mysqli_fetch_assoc($res);
}

function getRiskDetails($conn, string $policy_id, string $company_id): void {
    $policy = riskFindPolicy($conn, $policy_id, $company_id);
    $group  = riskGroup($policy['product_type'], $policy['policy_number'], productTypeOf($conn, $company_id, $policy['product_type']));

    $row = $group ? riskFetchDetailsRow($conn, $policy_id) : null;
    jsonResponse(200, 'Risk details retrieved', [
        'policy_id'    => $policy['policy_id'],
        'product_type' => $policy['product_type'],
        'risk_group'   => $group,
        'details'      => $row ? riskShapeDetails($row, $group) : null,
        'members'      => isset(RISK_MEMBER_ROLES_BY_GROUP[$group ?? '']) ? riskFetchMembers($conn, $policy_id) : [],
    ]);
}

// PUT: `risk_details` replaces the stored row, `members` replaces the people list. Either may be omitted.
function saveRiskDetails($conn, string $policy_id, string $company_id, array $input, string $username): void {
    $policy = riskFindPolicy($conn, $policy_id, $company_id);
    $parsed = riskParseInput($input, $policy['product_type'], $policy['policy_number'], productTypeOf($conn, $company_id, $policy['product_type']));
    if ($parsed['details'] === null && $parsed['members'] === null) throw ApiException::from('ERR_RISK_003', ['field' => 'risk_details']);

    $old     = $parsed['details'] !== null ? riskFetchDetailsRow($conn, $policy_id) : null;
    $changed = $parsed['details'] !== null ? riskChangedFields($old, $parsed['details']) : [];
    if ($parsed['members'] !== null) $changed[] = 'members';

    mysqli_begin_transaction($conn);
    $ok = true;
    if ($parsed['details'] !== null) $ok = riskUpsertDetails($conn, $policy_id, $company_id, $parsed['details'], $username);
    if ($ok && $parsed['members'] !== null) $ok = riskReplaceMembers($conn, $policy_id, $company_id, $parsed['members'], $username);
    if (!$ok) {
        error_log('saveRiskDetails failed for policy ' . $policy_id . ': ' . mysqli_error($conn));
        mysqli_rollback($conn);
        throw ApiException::from('ERR_RISK_011');
    }
    if ($changed) {
        insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated',
            'Detail risiko diperbarui: ' . implode(', ', $changed), $username,
            null, null, 'policy_risk_details', $policy_id, ['changed' => $changed]);
    }
    mysqli_commit($conn);

    getRiskDetails($conn, $policy_id, $company_id);
}

// ── Cargo open cover declarations ─────────────────────────────────────────────

/** The policy must be a cargo open cover; returns its row. */
function riskFindOpenCover($conn, string $policy_id, string $company_id): array {
    $policy = riskFindPolicy($conn, $policy_id, $company_id);
    $row    = riskGroup($policy['product_type'], $policy['policy_number'], productTypeOf($conn, $company_id, $policy['product_type'])) === 'cargo' ? riskFetchDetailsRow($conn, $policy_id) : null;
    if (!$row || !(int)$row['is_open_cover']) throw ApiException::from('ERR_RISK_009');
    return $policy;
}

function riskShapeDeclaration(array $d): array {
    $d['sum_insured']    = (int)$d['sum_insured'];
    $d['premium_amount'] = (int)$d['premium_amount'];
    unset($d['company_id'], $d['policy_id'], $d['created_by'], $d['updated_at']);
    return $d;
}

function riskParseDeclaration(array $in): array {
    $date = $in['declaration_date'] ?? null;
    return [
        'declaration_date'  => $date === null || $date === '' ? date('Y-m-d') : riskStrictDate($date, 'declaration_date'),
        'conveyance_name'   => riskText($in['conveyance_name'] ?? null, 'conveyance_name', 120),
        'voyage_from'       => riskText($in['voyage_from'] ?? null, 'voyage_from', 120),
        'voyage_to'         => riskText($in['voyage_to'] ?? null, 'voyage_to', 120),
        'bl_awb_number'     => riskParseValue('code', 60, $in['bl_awb_number'] ?? null, 'bl_awb_number'),
        'departure_date'    => riskParseValue('date', null, $in['departure_date'] ?? null, 'departure_date'),
        'goods_description' => riskText($in['goods_description'] ?? null, 'goods_description', 2000),
        'sum_insured'       => riskMoney($in['sum_insured'] ?? 0, 'sum_insured'),
        'premium_amount'    => riskMoney($in['premium_amount'] ?? 0, 'premium_amount'),
        'notes'             => riskText($in['notes'] ?? null, 'notes', 500),
    ];
}

function getDeclarations($conn, string $policy_id, string $company_id): void {
    riskFindOpenCover($conn, $policy_id, $company_id);
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT declaration_id, declaration_date, conveyance_name, voyage_from, voyage_to, bl_awb_number,
            departure_date, goods_description, sum_insured, premium_amount, notes, created_at
        FROM " . APP_SCHEMA . ".policy_cargo_declarations WHERE policy_id = '$pid'
        ORDER BY declaration_date DESC, created_at DESC");
    if (!$res) throw new RuntimeException('getDeclarations: ' . mysqli_error($conn));
    $items = array_map('riskShapeDeclaration', mysqli_fetch_all($res, MYSQLI_ASSOC));

    jsonResponse(200, 'Declarations retrieved', [
        'items'               => $items,
        'total_sum_insured'   => array_sum(array_column($items, 'sum_insured')),
        'total_premium'       => array_sum(array_column($items, 'premium_amount')),
    ]);
}

function riskLogDeclaration($conn, string $policy_id, string $company_id, string $what, array $d, string $declaration_id, string $username): void {
    $ref = $d['bl_awb_number'] ?? ($d['conveyance_name'] ?? $declaration_id);
    insertPolicyLog($conn, $policy_id, $company_id, 'policy_updated', "Deklarasi pengiriman $what: $ref", $username,
        null, null, 'policy_cargo_declarations', $declaration_id,
        ['sum_insured' => $d['sum_insured'], 'premium_amount' => $d['premium_amount']]);
}

function riskDeclarationColumns($conn, array $d): array {
    $cols = [];
    foreach ($d as $column => $value) $cols[$column] = riskSqlValue($conn, $value);
    return $cols;
}

function addDeclaration($conn, string $policy_id, string $company_id, array $input, string $username): void {
    riskFindOpenCover($conn, $policy_id, $company_id);
    $d   = riskParseDeclaration($input);
    $id  = 'dcl_' . bin2hex(random_bytes(10));
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $usr = mysqli_real_escape_string($conn, $username);
    $cols = riskDeclarationColumns($conn, $d);

    $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policy_cargo_declarations
        (declaration_id, company_id, policy_id, `" . implode('`, `', array_keys($cols)) . "`, created_by)
        VALUES ('$id', '" . mysqli_real_escape_string($conn, $company_id) . "', '$pid', " . implode(', ', $cols) . ", '$usr')");
    if (!$ok) {
        error_log('addDeclaration failed: ' . mysqli_error($conn));
        throw ApiException::from('ERR_RISK_011');
    }
    riskLogDeclaration($conn, $policy_id, $company_id, 'ditambahkan', $d, $id, $username);
    jsonResponse(201, 'Declaration added', ['declaration_id' => $id]);
}

function riskFindDeclaration($conn, string $policy_id, string $declaration_id): void {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $did = mysqli_real_escape_string($conn, $declaration_id);
    $res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policy_cargo_declarations WHERE declaration_id = '$did' AND policy_id = '$pid' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_RISK_010');
}

function updateDeclaration($conn, string $policy_id, string $company_id, string $declaration_id, array $input, string $username): void {
    riskFindOpenCover($conn, $policy_id, $company_id);
    riskFindDeclaration($conn, $policy_id, $declaration_id);
    $d    = riskParseDeclaration($input);
    $sets = [];
    foreach (riskDeclarationColumns($conn, $d) as $column => $sql) $sets[] = "`$column` = $sql";

    $ok = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policy_cargo_declarations SET " . implode(', ', $sets) . "
        WHERE declaration_id = '" . mysqli_real_escape_string($conn, $declaration_id) . "' AND policy_id = '" . mysqli_real_escape_string($conn, $policy_id) . "'");
    if (!$ok) {
        error_log('updateDeclaration failed: ' . mysqli_error($conn));
        throw ApiException::from('ERR_RISK_011');
    }
    riskLogDeclaration($conn, $policy_id, $company_id, 'diubah', $d, $declaration_id, $username);
    jsonResponse(200, 'Declaration updated');
}

function deleteDeclaration($conn, string $policy_id, string $company_id, string $declaration_id, string $username): void {
    riskFindOpenCover($conn, $policy_id, $company_id);
    riskFindDeclaration($conn, $policy_id, $declaration_id);
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $did = mysqli_real_escape_string($conn, $declaration_id);

    $res = mysqli_query($conn, "SELECT bl_awb_number, conveyance_name, sum_insured, premium_amount
        FROM " . APP_SCHEMA . ".policy_cargo_declarations WHERE declaration_id = '$did' AND policy_id = '$pid' LIMIT 1");
    $d = $res ? mysqli_fetch_assoc($res) : [];

    if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_cargo_declarations WHERE declaration_id = '$did' AND policy_id = '$pid'")) {
        error_log('deleteDeclaration failed: ' . mysqli_error($conn));
        throw ApiException::from('ERR_RISK_011');
    }
    riskLogDeclaration($conn, $policy_id, $company_id, 'dihapus', $d + ['sum_insured' => 0, 'premium_amount' => 0], $declaration_id, $username);
    jsonResponse(200, 'Declaration deleted');
}
