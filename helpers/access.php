<?php

// Role gate for sub-agent logins. A sub-agent is an app_user linked through
// sub_agents.user_id (migration_sub_agents_login.sql). Every module trusts company_id from the
// token, so instead of scoping each query this gate lets a sub-agent reach only its own
// read-only portal and rejects everything else before the module runs.
//
//   GET  /sub-agents/me[/customers|policies|summary|performance]
//   GET  /users/me            PUT /users/me            PUT /users/me/password
//
// Anything else is 403. The link is looked up in the database on every request, so deactivating
// the sub-agent or disabling the account takes effect immediately, not when the token expires.

const SUB_AGENT_PORTAL_ROUTES = ['', 'customers', 'policies', 'summary', 'performance'];

/** Whether a sub-agent login may call this route at all (the allow-list above). */
function subAgentMayAccess(string $module, string $action, string $sub_action, string $method): bool {
    $portal = $module === 'sub-agents' && $method === 'GET' && $action === 'me'
        && in_array($sub_action, SUB_AGENT_PORTAL_ROUTES, true);
    $profile = $module === 'users' && $action === 'me'
        && (($sub_action === '' && in_array($method, ['GET', 'PUT'], true)) || ($sub_action === 'password' && $method === 'PUT'));
    return $portal || $profile;
}

/** The sub-agent row for a login token, or null when the user is not a sub-agent. */
function findSubAgentLogin($conn, array $auth): ?array {
    $uid = trim((string)($auth['sub'] ?? ''));
    if ($uid === '') return null;
    $uid = mysqli_real_escape_string($conn, $uid);

    $res = mysqli_query($conn, "SELECT sa.sub_agent_id, sa.company_id, sa.status, u.account_status
        FROM " . APP_SCHEMA . ".sub_agents sa
        JOIN " . CORE_SCHEMA . ".app_user u ON u.user_id COLLATE utf8mb4_unicode_ci = sa.user_id
        WHERE sa.user_id = '$uid' LIMIT 1");
    if (!$res) throw new RuntimeException('findSubAgentLogin: ' . mysqli_error($conn));
    return mysqli_fetch_assoc($res) ?: null;
}

function enforceSubAgentAccess($conn, string $module, string $action, string $sub_action, string $method): void {
    try {
        $auth = JWT::fromRequest();
    } catch (\Throwable $e) {
        return; // no valid user token: the module's own requireAuth() answers
    }

    $agent = findSubAgentLogin($conn, $auth);
    if ($agent === null) return;

    if (($auth['company_id'] ?? null) !== $agent['company_id']
        || $agent['status'] !== 'active'
        || in_array($agent['account_status'], ['suspended', 'disabled', 'rejected'], true)) {
        throw ApiException::from('ERR_AUTH_003');
    }

    if (!subAgentMayAccess($module, $action, $sub_action, $method)) throw ApiException::from('ERR_COMMON_003');
}
