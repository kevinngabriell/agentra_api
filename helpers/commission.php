<?php

// [NEW v1.1] tax_rate parameter added (decimal 0–1, e.g. 0.0250 = 2.5% PPh).
// Defaults to 0 so any caller not yet updated won't break.
function insertCommission(
    $conn, $policy_id, $company_id, $insurer_id,
    $premium_amount, $commission_rate, $commission_amount,
    $issuing_agent_id = null, $tax_rate = 0.0
) {
    $commission_id   = 'com_' . uniqid();
    $now             = date('Y-m-d H:i:s');
    $pid             = mysqli_real_escape_string($conn, $policy_id);
    $cid             = mysqli_real_escape_string($conn, $company_id);
    $iid             = mysqli_real_escape_string($conn, $insurer_id);
    $commission_type = $issuing_agent_id ? 'override' : 'direct';

    $tax_rate         = round((float)$tax_rate, 4);
    $tax_amount       = (int)round($commission_amount * $tax_rate);
    $net_expected     = $commission_amount - $tax_amount;

    mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".commissions
         (commission_id, policy_id, company_id, insurer_id, commission_type,
          premium_amount, commission_rate, commission_tax_rate,
          expected_amount, commission_tax_amount, net_expected_amount,
          received_amount, status, created_at, updated_at)
         VALUES
         ('$commission_id', '$pid', '$cid', '$iid', '$commission_type',
          $premium_amount, $commission_rate, $tax_rate,
          $commission_amount, $tax_amount, $net_expected,
          0, 'pending', '$now', '$now')"
    );
}

// [NEW v1.1] tax_rate parameter added; keeps tax breakdown in sync whenever
// premium or commission_rate changes via endorsement or coverage edits.
// Only updates rows still in 'pending' status — never touches received/discrepancy rows.
function syncCommission(
    $conn, $policy_id,
    $premium_amount, $commission_rate, $commission_amount, $tax_rate = 0.0
) {
    $pid = mysqli_real_escape_string($conn, $policy_id);

    $tax_rate     = round((float)$tax_rate, 4);
    $tax_amount   = (int)round($commission_amount * $tax_rate);
    $net_expected = $commission_amount - $tax_amount;

    mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".commissions
         SET premium_amount        = $premium_amount,
             commission_rate       = $commission_rate,
             commission_tax_rate   = $tax_rate,
             expected_amount       = $commission_amount,
             commission_tax_amount = $tax_amount,
             net_expected_amount   = $net_expected,
             updated_at            = NOW()
         WHERE policy_id = '$pid' AND status = 'pending'"
    );
}
