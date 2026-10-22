<?php

// Shared sub-agent rules: lookup/validation of the id stored in
// customers.referred_by_agent_id and policies.issuing_agent_id, and the commission
// split. Tables in migration_sub_agents.sql.

/**
 * Returns the sub-agent row, or null when it does not exist for this company.
 * With $activeOnly, inactive sub-agents also count as missing.
 */
function findSubAgentRow($conn, string $company_id, string $sub_agent_id, bool $activeOnly = false): ?array {
    $cid = mysqli_real_escape_string($conn, $company_id);
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);
    $res = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".sub_agents
        WHERE sub_agent_id = '$sid' AND company_id = '$cid'" . ($activeOnly ? " AND status = 'active'" : '') . " LIMIT 1");
    if (!$res) throw new RuntimeException('findSubAgentRow: ' . mysqli_error($conn));
    return mysqli_fetch_assoc($res) ?: null;
}

/**
 * Normalises a sub-agent id from request input: '' / null → null (main agent),
 * otherwise it must be an active sub-agent of the company.
 */
function requireActiveSubAgentId($conn, string $company_id, mixed $value): ?string {
    $id = is_string($value) ? trim($value) : '';
    if ($id === '') return null;
    if (!findSubAgentRow($conn, $company_id, $id, true)) throw ApiException::from('ERR_SAGENT_008');
    return $id;
}

/**
 * Share (%) of the gross commission passed on to the sub-agent for an insurer:
 * the per-insurer override if there is one, else the sub-agent default.
 */
function resolveSubAgentSplitRate($conn, string $company_id, string $sub_agent_id, string $insurer_id): ?float {
    $agent = findSubAgentRow($conn, $company_id, $sub_agent_id);
    if (!$agent) return null;
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);
    $iid = mysqli_real_escape_string($conn, $insurer_id);
    $res = mysqli_query($conn, "SELECT split_rate FROM " . APP_SCHEMA . ".sub_agent_insurer_rates
        WHERE sub_agent_id = '$sid' AND insurer_id = '$iid' LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return round((float)($row['split_rate'] ?? $agent['default_split_rate']), 2);
}

/** IDR owed to the sub-agent out of the gross commission. */
function subAgentCommissionAmount(int $commission_amount, ?float $split_rate): int {
    return $split_rate === null ? 0 : (int)round($commission_amount * $split_rate / 100);
}

/**
 * Re-points a policy's commission at another sub-agent (or the main agent when null)
 * and re-snapshots the split. Only touches commissions still 'pending'; settled rows
 * keep the split they were received under.
 */
function reassignCommissionSubAgent($conn, string $company_id, string $policy_id, ?string $sub_agent_id): void {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $company_id);
    $res = mysqli_query($conn, "SELECT insurer_id, expected_amount FROM " . APP_SCHEMA . ".commissions
        WHERE policy_id = '$pid' AND company_id = '$cid' AND status = 'pending' LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) return;

    $splitRate = $sub_agent_id ? resolveSubAgentSplitRate($conn, $company_id, $sub_agent_id, $row['insurer_id']) : null;
    $amount    = subAgentCommissionAmount((int)$row['expected_amount'], $splitRate);
    $ok = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".commissions SET
            commission_type      = '" . ($sub_agent_id ? 'override' : 'direct') . "',
            sub_agent_id         = " . ($splitRate === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $sub_agent_id) . "'") . ",
            sub_agent_split_rate = " . ($splitRate === null ? 'NULL' : number_format($splitRate, 2, '.', '')) . ",
            sub_agent_amount     = $amount
        WHERE policy_id = '$pid' AND company_id = '$cid' AND status = 'pending'");
    if (!$ok) throw new RuntimeException('reassignCommissionSubAgent: ' . mysqli_error($conn));
}
