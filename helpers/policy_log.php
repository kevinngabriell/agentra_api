<?php

/**
 * Append one event to policy_logs.
 * Silent on failure — never let audit writes break the main operation.
 */
function insertPolicyLog(
    $conn,
    $policy_id,
    $company_id,
    $event_type,
    $description,
    $username,
    $old_value      = null,
    $new_value      = null,
    $reference_type = null,
    $reference_id   = null,
    $metadata       = null
) {
    $log_id = 'plog_' . uniqid();
    $now    = date('Y-m-d H:i:s');

    $pid  = mysqli_real_escape_string($conn, $policy_id);
    $cid  = mysqli_real_escape_string($conn, $company_id);
    $et   = mysqli_real_escape_string($conn, $event_type);
    $desc = mysqli_real_escape_string($conn, $description);
    $usr  = mysqli_real_escape_string($conn, $username);
    $ov   = $old_value      !== null ? "'" . mysqli_real_escape_string($conn, $old_value)      . "'" : 'NULL';
    $nv   = $new_value      !== null ? "'" . mysqli_real_escape_string($conn, $new_value)      . "'" : 'NULL';
    $rt   = $reference_type !== null ? "'" . mysqli_real_escape_string($conn, $reference_type) . "'" : 'NULL';
    $ri   = $reference_id   !== null ? "'" . mysqli_real_escape_string($conn, $reference_id)   . "'" : 'NULL';
    $meta = $metadata       !== null
        ? "'" . mysqli_real_escape_string($conn, json_encode($metadata, JSON_UNESCAPED_UNICODE)) . "'"
        : 'NULL';

    mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policy_logs
         (log_id, policy_id, company_id, event_type, reference_type, reference_id,
          old_value, new_value, description, metadata, created_by, created_at)
         VALUES
         ('$log_id', '$pid', '$cid', '$et', $rt, $ri, $ov, $nv, '$desc', $meta, '$usr', '$now')"
    );
}
