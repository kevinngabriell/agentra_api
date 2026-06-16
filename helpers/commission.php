<?php

/**
 * Auto-create a commission record when a policy is created.
 * commission_type = 'direct' for own policy, 'override' for sub-agent policy.
 */
function insertCommission($conn, $policy_id, $company_id, $insurer_id, $premium_amount, $commission_rate, $commission_amount, $issuing_agent_id = null) {
    $commission_id   = 'com_' . uniqid();
    $now             = date('Y-m-d H:i:s');
    $pid             = mysqli_real_escape_string($conn, $policy_id);
    $cid             = mysqli_real_escape_string($conn, $company_id);
    $iid             = mysqli_real_escape_string($conn, $insurer_id);
    $commission_type = $issuing_agent_id ? 'override' : 'direct';

    mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".commissions
         (commission_id, policy_id, company_id, insurer_id, commission_type,
          premium_amount, commission_rate, expected_amount, received_amount, status,
          created_at, updated_at)
         VALUES
         ('$commission_id', '$pid', '$cid', '$iid', '$commission_type',
          $premium_amount, $commission_rate, $commission_amount, 0, 'pending',
          '$now', '$now')"
    );
}

/**
 * Re-sync an existing pending commission when premium or rate changes on endorsement.
 * Only updates rows still in 'pending' status — never touches received/discrepancy rows.
 */
function syncCommission($conn, $policy_id, $premium_amount, $commission_rate, $commission_amount) {
    $pid = mysqli_real_escape_string($conn, $policy_id);

    mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".commissions
         SET premium_amount  = $premium_amount,
             commission_rate = $commission_rate,
             expected_amount = $commission_amount,
             updated_at      = NOW()
         WHERE policy_id = '$pid' AND status = 'pending'"
    );
}
