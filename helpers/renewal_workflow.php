<?php

// Renewal quote workflow (migration_renewal_workflow.sql). Used by renewals/index.php and by
// policies/renew.php, which moves the workflow to 'issued'.

const RWF_STAGES = ['not_started', 'terms_requested', 'terms_received', 'offered', 'accepted', 'declined', 'issued'];
const RWF_LOST_REASONS = ['price', 'competitor', 'service', 'no_longer_needed', 'asset_sold', 'no_response', 'other'];

// Allowed moves. 'issued' is reached only through POST /policies/{id}/renew.
const RWF_TRANSITIONS = [
    'not_started'     => ['terms_requested', 'declined'],
    'terms_requested' => ['terms_received', 'declined'],
    'terms_received'  => ['terms_requested', 'offered', 'declined'],
    'offered'         => ['terms_received', 'accepted', 'declined'],
    'accepted'        => ['offered', 'declined'],
    'declined'        => ['terms_requested'],
    'issued'          => [],
];

/** The workflow row for a policy, or null when the renewal has not been started. */
function rwfFetch($conn, string $policy_id): ?array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".renewal_workflows WHERE policy_id = '$pid' LIMIT 1");
    if (!$res) throw new RuntimeException('rwfFetch: ' . mysqli_error($conn));
    return mysqli_fetch_assoc($res) ?: null;
}

/** Row for the policy, creating a 'not_started' one when missing. */
function rwfEnsure($conn, string $company_id, string $policy_id, string $username): array {
    $row = rwfFetch($conn, $policy_id);
    if ($row) return $row;
    $id  = 'rwf_' . bin2hex(random_bytes(10));
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $usr = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".renewal_workflows (workflow_id, company_id, policy_id, created_by)
        VALUES ('$id', '$company_id', '$pid', '$usr')")) {
        // A concurrent request may have created it between the SELECT and the INSERT.
        $row = rwfFetch($conn, $policy_id);
        if ($row) return $row;
        throw ApiException::from('ERR_RWF_006');
    }
    return rwfFetch($conn, $policy_id);
}

function rwfAddEvent($conn, string $workflow_id, string $from, string $to, ?string $note, string $username): void {
    // Time-sortable id: events written in the same second still list in the order they happened.
    $id   = 'rwe_' . sprintf('%012x', (int)(microtime(true) * 1000)) . bin2hex(random_bytes(4));
    $wid  = mysqli_real_escape_string($conn, $workflow_id);
    $usr  = mysqli_real_escape_string($conn, $username);
    $noteSql = $note !== null && $note !== '' ? "'" . mysqli_real_escape_string($conn, $note) . "'" : 'NULL';
    if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".renewal_workflow_events (event_id, workflow_id, from_stage, to_stage, note, created_by)
        VALUES ('$id', '$wid', '$from', '$to', $noteSql, '$usr')")) {
        throw new RuntimeException('rwfAddEvent: ' . mysqli_error($conn));
    }
}

/** Called by the renew endpoint inside its transaction: the old policy's workflow becomes 'issued'. */
function rwfMarkIssued($conn, string $company_id, string $policy_id, string $new_policy_id, string $username): void {
    $row  = rwfEnsure($conn, $company_id, $policy_id, $username);
    $from = $row['stage'];
    $wid  = mysqli_real_escape_string($conn, $row['workflow_id']);
    $npid = mysqli_real_escape_string($conn, $new_policy_id);
    $usr  = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".renewal_workflows
        SET stage = 'issued', stage_changed_at = NOW(), renewed_policy_id = '$npid', lost_reason = NULL, lost_note = NULL, updated_by = '$usr'
        WHERE workflow_id = '$wid'")) {
        throw new RuntimeException('rwfMarkIssued: ' . mysqli_error($conn));
    }
    if ($from !== 'issued') rwfAddEvent($conn, $row['workflow_id'], $from, 'issued', null, $username);
}

/**
 * Which kind of sum-insured review to prompt for, or null. Vehicles lose value every year and
 * property values go stale, so the agent is asked to confirm them before the terms are offered.
 */
function rwfReviewKind(string $product_type, string $policy_number, ?string $master_type = null): ?string {
    return match (riskGroup($product_type, $policy_number, $master_type)) {
        'motor' => 'vehicle_depreciation',
        'fire'  => 'property_values',
        default => null,
    };
}
