<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/customer_360.php';

// Groups of related customers (migration_customer_360.sql).
//
//   GET    /customer-groups          list with member counts and in-force premium
//   POST   /customer-groups          create { name, notes? }
//   GET    /customer-groups/{id}     group with its members
//   PUT    /customer-groups/{id}     rename / edit notes
//   DELETE /customer-groups/{id}     delete; members are ungrouped, not deleted
//
// Membership is set from the customer: PUT /customers/{id}/group.

function cgrpFind($conn, string $company_id, string $group_id): array {
    $gid = mysqli_real_escape_string($conn, $group_id);
    $res = mysqli_query($conn, "SELECT group_id, name, notes, created_at FROM " . APP_SCHEMA . ".customer_groups
        WHERE group_id = '$gid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_CGRP_001');
    return mysqli_fetch_assoc($res);
}

function cgrpFindOrCreateByName($conn, string $company_id, string $name, string $username): array {
    $n   = mysqli_real_escape_string($conn, $name);
    $res = mysqli_query($conn, "SELECT group_id, name, notes, created_at FROM " . APP_SCHEMA . ".customer_groups
        WHERE company_id = '$company_id' AND name = '$n' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) return mysqli_fetch_assoc($res);

    $id  = 'cgrp_' . bin2hex(random_bytes(10));
    $usr = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".customer_groups (group_id, company_id, name, created_by) VALUES ('$id', '$company_id', '$n', '$usr')")) {
        error_log('cgrpFindOrCreateByName: ' . mysqli_error($conn));
        throw ApiException::from('ERR_CGRP_005');
    }
    return cgrpFind($conn, $company_id, $id);
}

function cgrpParse(array $in, ?array $current = null): array {
    $name = array_key_exists('name', $in) ? trim((string)$in['name']) : ($current['name'] ?? '');
    if ($name === '' || mb_strlen($name) > 150) throw ApiException::from('ERR_CGRP_003');
    $notes = array_key_exists('notes', $in) ? trim((string)($in['notes'] ?? '')) : ($current['notes'] ?? '');
    if (mb_strlen($notes) > 500) $notes = mb_substr($notes, 0, 500);
    return [$name, $notes === '' ? null : $notes];
}

function listCustomerGroups($conn, string $company_id) {
    $res = mysqli_query($conn, "SELECT g.group_id, g.name, g.notes, COUNT(DISTINCT c.customer_id) AS member_count,
            COUNT(CASE WHEN " . POLICY_IN_FORCE_SQL . " THEN 1 END) AS in_force_policies,
            COALESCE(SUM(CASE WHEN " . POLICY_IN_FORCE_SQL . " THEN p.premium_amount END), 0) AS premium
        FROM " . APP_SCHEMA . ".customer_groups g
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.group_id = g.group_id
        LEFT JOIN " . APP_SCHEMA . ".policies p ON p.customer_id = c.customer_id AND p.company_id = g.company_id
        WHERE g.company_id = '$company_id'
        GROUP BY g.group_id, g.name, g.notes ORDER BY g.name");
    if (!$res) throw new RuntimeException('listCustomerGroups: ' . mysqli_error($conn));
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    foreach ($rows as &$r) { foreach (['member_count', 'in_force_policies', 'premium'] as $k) $r[$k] = (int)$r[$k]; }
    unset($r);
    jsonResponse(200, 'Customer groups', ['data' => $rows]);
}

function createCustomerGroup($conn, string $company_id, array $in, string $username) {
    [$name, $notes] = cgrpParse($in);
    $n = mysqli_real_escape_string($conn, $name);
    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer_groups WHERE company_id = '$company_id' AND name = '$n' LIMIT 1");
    if ($dup && mysqli_num_rows($dup) > 0) throw ApiException::from('ERR_CGRP_002');

    $id  = 'cgrp_' . bin2hex(random_bytes(10));
    $usr = mysqli_real_escape_string($conn, $username);
    $nt  = $notes === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $notes) . "'";
    if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".customer_groups (group_id, company_id, name, notes, created_by)
        VALUES ('$id', '$company_id', '$n', $nt, '$usr')")) {
        error_log('createCustomerGroup: ' . mysqli_error($conn));
        throw ApiException::from('ERR_CGRP_005');
    }
    jsonResponse(201, 'Customer group created', cgrpFind($conn, $company_id, $id));
}

function getCustomerGroup($conn, string $company_id, string $group_id) {
    $group = cgrpFind($conn, $company_id, $group_id);
    $group['members'] = c360GroupMembers($conn, $company_id, $group['group_id']);
    jsonResponse(200, 'Customer group', $group);
}

function updateCustomerGroup($conn, string $company_id, string $group_id, array $in) {
    $group = cgrpFind($conn, $company_id, $group_id);
    [$name, $notes] = cgrpParse($in, $group);
    $n   = mysqli_real_escape_string($conn, $name);
    $gid = mysqli_real_escape_string($conn, $group['group_id']);
    $dup = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".customer_groups WHERE company_id = '$company_id' AND name = '$n' AND group_id <> '$gid' LIMIT 1");
    if ($dup && mysqli_num_rows($dup) > 0) throw ApiException::from('ERR_CGRP_002');
    $nt = $notes === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $notes) . "'";
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".customer_groups SET name = '$n', notes = $nt WHERE group_id = '$gid' AND company_id = '$company_id'")) {
        error_log('updateCustomerGroup: ' . mysqli_error($conn));
        throw ApiException::from('ERR_CGRP_005');
    }
    jsonResponse(200, 'Customer group updated', cgrpFind($conn, $company_id, $gid));
}

function deleteCustomerGroup($conn, string $company_id, string $group_id) {
    $group = cgrpFind($conn, $company_id, $group_id);
    $gid   = mysqli_real_escape_string($conn, $group['group_id']);
    mysqli_begin_transaction($conn);
    try {
        if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".customers SET group_id = NULL WHERE group_id = '$gid' AND company_id = '$company_id'")
            || !mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".customer_groups WHERE group_id = '$gid' AND company_id = '$company_id'")) {
            throw new RuntimeException(mysqli_error($conn));
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        error_log('deleteCustomerGroup: ' . $e->getMessage());
        throw ApiException::from('ERR_CGRP_005');
    }
    jsonResponse(200, 'Customer group deleted');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
if (!defined('CUSTOMER_GROUPS_AS_LIBRARY')) {
    $authUser   = requireAuth();
    $method     = $_SERVER['REQUEST_METHOD'];
    $company_id = $authUser['company_id'] ?? null;
    $username   = $authUser['sub'] ?? $authUser['user_id'] ?? '';
    if (!$company_id) jsonResponse(400, 'company_id is required');

    try {
        $conn       = getConn();
        $company_id = mysqli_real_escape_string($conn, $company_id);

        if ($action === '') {
            switch ($method) {
                case 'GET':  listCustomerGroups($conn, $company_id); break;
                case 'POST': createCustomerGroup($conn, $company_id, input(), $username); break;
                default:     Response::methodNotAllowed();
            }
        } elseif (($parts[4] ?? '') === '') {
            switch ($method) {
                case 'GET':    getCustomerGroup($conn, $company_id, $action); break;
                case 'PUT':    updateCustomerGroup($conn, $company_id, $action, input()); break;
                case 'DELETE': deleteCustomerGroup($conn, $company_id, $action); break;
                default:       Response::methodNotAllowed();
            }
        } else {
            jsonResponse(404, 'Route not found');
        }
    } catch (ApiException $e) {
        throw $e;
    } catch (\Throwable $e) {
        RequestLogger::setException($e);
        error_log($e->getMessage());
        Response::internalError();
    }
}
