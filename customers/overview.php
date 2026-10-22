<?php

require_once __DIR__ . '/../helpers/customer_360.php';
require_once __DIR__ . '/../helpers/risk_details.php';

// --- GET /api/v1/customers/{customer_id}/overview?scope=customer|group ---
// Every product the customer (or its whole group) holds, across insurers.
function getCustomerOverview($conn, $company_id, $customer_id, $params) {
    $customer = c360FindCustomer($conn, $company_id, $customer_id);
    $scope    = ($params['scope'] ?? '') === 'group' && $customer['group_id'] ? 'group' : 'customer';
    $ids      = c360ScopeIds($conn, $company_id, $customer, $scope);
    $policies = c360Policies($conn, $company_id, $ids);

    jsonResponse(200, 'Customer overview', array_merge([
        'customer' => $customer,
        'scope'    => $scope,
        'members'  => c360GroupMembers($conn, $company_id, $customer['group_id']),
        'policies' => $policies,
    ], c360Summaries($policies)));
}

// --- GET /api/v1/customers/{customer_id}/cross-sell?scope=customer|group ---
function getCustomerCrossSell($conn, $company_id, $customer_id, $params) {
    $customer = c360FindCustomer($conn, $company_id, $customer_id);
    $scope    = ($params['scope'] ?? '') === 'group' && $customer['group_id'] ? 'group' : 'customer';
    $policies = c360Policies($conn, $company_id, c360ScopeIds($conn, $company_id, $customer, $scope));

    jsonResponse(200, 'Cross-sell suggestions', [
        'scope'       => $scope,
        'suggestions' => c360CrossSell($conn, $company_id, $customer, $policies),
    ]);
}

// --- PUT /api/v1/customers/{customer_id}/group ---
// Body: { group_id: string|null } to move the customer, or { group_name: string } to create-or-join a group by name.
function setCustomerGroup($conn, $company_id, $customer_id, $in, $username) {
    c360FindCustomer($conn, $company_id, $customer_id);
    $cid = mysqli_real_escape_string($conn, $customer_id);

    if (!empty($in['group_name'])) {
        $name = trim((string)$in['group_name']);
        if ($name === '' || mb_strlen($name) > 150) throw ApiException::from('ERR_CGRP_003');
        $group = cgrpFindOrCreateByName($conn, $company_id, $name, $username);
        $gid = $group['group_id'];
    } elseif (!empty($in['group_id'])) {
        $gid = cgrpFind($conn, $company_id, (string)$in['group_id'])['group_id'];
    } else {
        $gid = null;
    }

    $gidSql = $gid === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $gid) . "'";
    $usr    = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".customers SET group_id = $gidSql, updated_by = '$usr'
        WHERE customer_id = '$cid' AND company_id = '$company_id'")) {
        error_log('setCustomerGroup: ' . mysqli_error($conn));
        throw ApiException::from('ERR_CGRP_005');
    }
    jsonResponse(200, 'Customer group updated', c360FindCustomer($conn, $company_id, $customer_id));
}
