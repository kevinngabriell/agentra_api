<?php

require_once __DIR__ . '/sub_agents.php';

// [NEW v1.1] tax_rate parameter added (decimal 0–1, e.g. 0.0250 = 2.5% PPh).
// Defaults to 0 so any caller not yet updated won't break.
// Returns true on success, false on failure (previously void — silent data loss).
function insertCommission(
    $conn, $policy_id, $company_id, $insurer_id,
    $premium_amount, $commission_rate, $commission_amount,
    $issuing_agent_id = null, $tax_rate = 0.0
): bool {
    // Use random_bytes for the ID — uniqid() is microsecond-predictable and
    // collides under high concurrency.
    $commission_id = 'com_' . bin2hex(random_bytes(10));
    $now           = date('Y-m-d H:i:s');
    $pid           = mysqli_real_escape_string($conn, $policy_id);
    $cid           = mysqli_real_escape_string($conn, $company_id);
    $iid           = mysqli_real_escape_string($conn, $insurer_id);
    $commission_type = $issuing_agent_id ? 'override' : 'direct';

    // Cast numerics defensively at the helper boundary — never trust callers.
    // A future caller that passes an untrusted string would otherwise inject SQL.
    $premium_amount    = (int)$premium_amount;
    $commission_rate   = round((float)$commission_rate, 2);
    $commission_amount = (int)$commission_amount;
    $tax_rate          = round((float)$tax_rate, 4);
    $tax_amount        = (int)round($commission_amount * $tax_rate);
    $net_expected      = $commission_amount - $tax_amount;

    // Share passed on to the sub-agent, snapshotted like the rate. NULL columns = main agent's own policy.
    $splitRate = $issuing_agent_id ? resolveSubAgentSplitRate($conn, $company_id, $issuing_agent_id, $insurer_id) : null;
    $subAmount = subAgentCommissionAmount($commission_amount, $splitRate);
    $subIdSql  = $splitRate === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $issuing_agent_id) . "'";
    $splitSql  = $splitRate === null ? 'NULL' : number_format($splitRate, 2, '.', '');

    $ok = mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".commissions
         (commission_id, policy_id, company_id, insurer_id, commission_type,
          premium_amount, commission_rate, commission_tax_rate,
          expected_amount, commission_tax_amount, net_expected_amount,
          sub_agent_id, sub_agent_split_rate, sub_agent_amount,
          received_amount, status, created_at, updated_at)
         VALUES
         ('$commission_id', '$pid', '$cid', '$iid', '$commission_type',
          $premium_amount, $commission_rate, $tax_rate,
          $commission_amount, $tax_amount, $net_expected,
          $subIdSql, $splitSql, $subAmount,
          0, 'pending', '$now', '$now')"
    );

    if (!$ok) {
        error_log("insertCommission failed for policy $policy_id: " . mysqli_error($conn));
        return false;
    }
    return true;
}

// [NEW v1.1] tax_rate parameter added; keeps tax breakdown in sync whenever
// premium or commission_rate changes via endorsement or coverage edits.
// Only updates rows still in 'pending' status — never touches received/discrepancy rows.
// Returns true on success, false on failure.
function syncCommission(
    $conn, $policy_id,
    $premium_amount, $commission_rate, $commission_amount, $tax_rate = 0.0
): bool {
    $pid = mysqli_real_escape_string($conn, $policy_id);

    // Cast numerics defensively at the helper boundary.
    $premium_amount    = (int)$premium_amount;
    $commission_rate   = round((float)$commission_rate, 2);
    $commission_amount = (int)$commission_amount;
    $tax_rate          = round((float)$tax_rate, 4);
    $tax_amount        = (int)round($commission_amount * $tax_rate);
    $net_expected      = $commission_amount - $tax_amount;

    $ok = mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".commissions
         SET premium_amount        = $premium_amount,
             commission_rate       = $commission_rate,
             commission_tax_rate   = $tax_rate,
             expected_amount       = $commission_amount,
             commission_tax_amount = $tax_amount,
             net_expected_amount   = $net_expected,
             sub_agent_amount      = ROUND($commission_amount * COALESCE(sub_agent_split_rate, 0) / 100),
             updated_at            = NOW()
         WHERE policy_id = '$pid' AND status = 'pending'"
    );

    if (!$ok) {
        error_log("syncCommission failed for policy $policy_id: " . mysqli_error($conn));
        return false;
    }
    return true;
}
