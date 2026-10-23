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
    // Use random_bytes for the ID — uniqid() is microsecond-predictable.
    $log_id = 'plog_' . bin2hex(random_bytes(10));
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

    // Guard json_encode against non-UTF-8 bytes (legacy data, Windows-1252 names).
    // json_encode returns false on error — without this guard the audit row would
    // store the literal string "false" instead of structured metadata.
    $meta = 'NULL';
    if ($metadata !== null) {
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = json_encode(['__encode_error' => json_last_error_msg()]);
        }
        $meta = "'" . mysqli_real_escape_string($conn, $json) . "'";
    }

    $ok = mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policy_logs
         (log_id, policy_id, company_id, event_type, reference_type, reference_id,
          old_value, new_value, description, metadata, created_by, created_at)
         VALUES
         ('$log_id', '$pid', '$cid', '$et', $rt, $ri, $ov, $nv, '$desc', $meta, '$usr', '$now')"
    );

    if (!$ok) {
        // Audit writes must never break the main operation — log and continue.
        error_log("insertPolicyLog failed for policy $policy_id: " . mysqli_error($conn));
    }
}
