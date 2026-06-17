<?php
// [NEW v1.1] Co-assurance endpoints.
// Routes (all scoped under /api/v1/policies/{policy_id}/coassurance):
//   GET    /coassurance                          — list participants
//   POST   /coassurance                          — add a participant
//   PUT    /coassurance/{coassurance_id}         — update a participant
//   DELETE /coassurance/{coassurance_id}         — remove a participant
//
// $policy_id and $conn are already available from the parent policies/index.php scope.

$coassurance_id = $parts[5] ?? '';

// ── Helpers ───────────────────────────────────────────────────────────────────

function verifyPolicyForCoassurance($conn, $policy_id, $company_id) {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $company_id);
    $res = mysqli_query($conn,
        "SELECT policy_id FROM " . APP_SCHEMA . ".policies
         WHERE policy_id = '$pid' AND company_id = '$cid' LIMIT 1"
    );
    return $res && mysqli_num_rows($res) > 0;
}

// ── LIST ─────────────────────────────────────────────────────────────────────

function listCoassurance($conn, $policy_id, $company_id) {
    if (!verifyPolicyForCoassurance($conn, $policy_id, $company_id)) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $pid = mysqli_real_escape_string($conn, $policy_id);

    $res = mysqli_query($conn,
        "SELECT ca.coassurance_id, ca.policy_id, ca.co_insurer_id, ca.co_insurer_name,
                ca.is_leader, ca.share_percent, ca.sum_insured_share, ca.premium_share,
                ca.commission_rate, ca.commission_amount, ca.notes,
                ca.created_by, ca.created_at, ca.updated_by, ca.updated_at,
                i.short_name AS co_insurer_short_name
         FROM " . APP_SCHEMA . ".policy_coassurance ca
         LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = ca.co_insurer_id
         WHERE ca.policy_id = '$pid'
         ORDER BY ca.is_leader DESC, ca.share_percent DESC"
    );

    $data = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    jsonResponse(200, 'Co-assurance participants found', ['data' => $data]);
}

// ── ADD ───────────────────────────────────────────────────────────────────────

function addCoassurance($conn, $policy_id, $company_id, $input, $username) {
    if (!verifyPolicyForCoassurance($conn, $policy_id, $company_id)) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    if (empty($input['co_insurer_name']) || trim($input['co_insurer_name']) === '') {
        jsonResponse(400, 'co_insurer_name is required');
        return;
    }
    if (!isset($input['share_percent']) || $input['share_percent'] === '') {
        jsonResponse(400, 'share_percent is required');
        return;
    }

    $share = (float)$input['share_percent'];
    if ($share <= 0 || $share > 100) {
        jsonResponse(400, 'share_percent must be between 0.01 and 100');
        return;
    }

    $pid              = mysqli_real_escape_string($conn, $policy_id);
    $co_insurer_name  = mysqli_real_escape_string($conn, trim($input['co_insurer_name']));
    $co_insurer_id    = !empty($input['co_insurer_id'])
                            ? "'" . mysqli_real_escape_string($conn, $input['co_insurer_id']) . "'"
                            : 'NULL';
    $is_leader        = !empty($input['is_leader']) ? 1 : 0;
    $sum_insured_share = (int)($input['sum_insured_share'] ?? 0);
    $premium_share    = (int)($input['premium_share'] ?? 0);
    $commission_rate  = round((float)($input['commission_rate'] ?? 0), 2);
    $commission_amount = (int)round($premium_share * $commission_rate / 100);
    $notes            = !empty($input['notes'])
                            ? "'" . mysqli_real_escape_string($conn, trim($input['notes'])) . "'"
                            : 'NULL';
    $now              = date('Y-m-d H:i:s');
    $coassurance_id   = 'ca_' . uniqid();

    // Validate co_insurer_id belongs to this company if provided.
    if ($co_insurer_id !== 'NULL') {
        $raw_id = $input['co_insurer_id'];
        $eid    = mysqli_real_escape_string($conn, $raw_id);
        $cid    = mysqli_real_escape_string($conn, $company_id);
        $chk    = mysqli_query($conn,
            "SELECT 1 FROM " . APP_SCHEMA . ".insurers
             WHERE insurer_id = '$eid' AND company_id = '$cid' LIMIT 1"
        );
        if (!$chk || mysqli_num_rows($chk) === 0) {
            jsonResponse(404, 'co_insurer_id not found or does not belong to your company');
            return;
        }
    }

    $ok = mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policy_coassurance
         (coassurance_id, policy_id, co_insurer_id, co_insurer_name, is_leader,
          share_percent, sum_insured_share, premium_share,
          commission_rate, commission_amount, notes, created_by, created_at)
         VALUES
         ('$coassurance_id', '$pid', $co_insurer_id, '$co_insurer_name', $is_leader,
          $share, $sum_insured_share, $premium_share,
          $commission_rate, $commission_amount, $notes, '$username', '$now')"
    );

    if (!$ok) {
        jsonResponse(500, 'Failed to add co-assurance participant', ['error' => mysqli_error($conn)]);
        return;
    }

    // Mark the policy as co-assurance so FE can show the co-assurance badge.
    mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".policies
         SET is_coassurance = 1, updated_by = '$username', updated_at = '$now'
         WHERE policy_id = '$pid'"
    );

    jsonResponse(201, 'Co-assurance participant added', ['coassurance_id' => $coassurance_id]);
}

// ── UPDATE ────────────────────────────────────────────────────────────────────

function updateCoassurance($conn, $policy_id, $coassurance_id, $company_id, $input, $username) {
    if (!verifyPolicyForCoassurance($conn, $policy_id, $company_id)) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $coassurance_id);

    $check = mysqli_query($conn,
        "SELECT premium_share, commission_rate FROM " . APP_SCHEMA . ".policy_coassurance
         WHERE coassurance_id = '$cid' AND policy_id = '$pid' LIMIT 1"
    );
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Co-assurance record not found');
        return;
    }
    $current = mysqli_fetch_assoc($check);
    $updates = [];

    if (isset($input['co_insurer_name'])) {
        $val = trim(mysqli_real_escape_string($conn, $input['co_insurer_name']));
        $updates[] = "co_insurer_name = '$val'";
    }
    if (array_key_exists('co_insurer_id', $input)) {
        $updates[] = $input['co_insurer_id']
            ? "co_insurer_id = '" . mysqli_real_escape_string($conn, $input['co_insurer_id']) . "'"
            : "co_insurer_id = NULL";
    }
    if (isset($input['is_leader'])) {
        $updates[] = "is_leader = " . ($input['is_leader'] ? 1 : 0);
    }
    if (isset($input['share_percent'])) {
        $share = (float)$input['share_percent'];
        if ($share <= 0 || $share > 100) {
            jsonResponse(400, 'share_percent must be between 0.01 and 100');
            return;
        }
        $updates[] = "share_percent = $share";
    }
    if (isset($input['sum_insured_share'])) {
        $updates[] = "sum_insured_share = " . (int)$input['sum_insured_share'];
    }
    if (isset($input['notes'])) {
        $val = trim($input['notes']);
        $updates[] = $val !== ''
            ? "notes = '" . mysqli_real_escape_string($conn, $val) . "'"
            : "notes = NULL";
    }

    $new_premium_share    = isset($input['premium_share'])   ? (int)$input['premium_share']                   : null;
    $new_commission_rate  = isset($input['commission_rate']) ? round((float)$input['commission_rate'], 2)      : null;

    if ($new_premium_share !== null)   { $updates[] = "premium_share = $new_premium_share"; }
    if ($new_commission_rate !== null) { $updates[] = "commission_rate = $new_commission_rate"; }

    if ($new_premium_share !== null || $new_commission_rate !== null) {
        $p = $new_premium_share   ?? (int)$current['premium_share'];
        $r = $new_commission_rate ?? (float)$current['commission_rate'];
        $updates[] = "commission_amount = " . (int)round($p * $r / 100);
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    $now = date('Y-m-d H:i:s');
    $uname = mysqli_real_escape_string($conn, $username);
    $updates[] = "updated_by = '$uname'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policy_coassurance SET " . implode(', ', $updates) . " WHERE coassurance_id = '$cid' AND policy_id = '$pid'")) {
        jsonResponse(200, 'Co-assurance participant updated');
    } else {
        jsonResponse(500, 'Failed to update co-assurance participant', ['error' => mysqli_error($conn)]);
    }
}

// ── DELETE ────────────────────────────────────────────────────────────────────

function deleteCoassurance($conn, $policy_id, $coassurance_id, $company_id, $username) {
    if (!verifyPolicyForCoassurance($conn, $policy_id, $company_id)) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $coassurance_id);

    $check = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".policy_coassurance
         WHERE coassurance_id = '$cid' AND policy_id = '$pid' LIMIT 1"
    );
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Co-assurance record not found');
        return;
    }

    if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_coassurance WHERE coassurance_id = '$cid' AND policy_id = '$pid'")) {
        jsonResponse(500, 'Failed to delete co-assurance participant', ['error' => mysqli_error($conn)]);
        return;
    }

    // Clear the flag if no participants remain.
    $remaining = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".policy_coassurance WHERE policy_id = '$pid' LIMIT 1"
    );
    if ($remaining && mysqli_num_rows($remaining) === 0) {
        $now   = date('Y-m-d H:i:s');
        $uname = mysqli_real_escape_string($conn, $username);
        mysqli_query($conn,
            "UPDATE " . APP_SCHEMA . ".policies
             SET is_coassurance = 0, updated_by = '$uname', updated_at = '$now'
             WHERE policy_id = '$pid'"
        );
    }

    jsonResponse(200, 'Co-assurance participant removed');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$input = in_array($method, ['POST', 'PUT', 'PATCH'])
    ? (json_decode(file_get_contents('php://input'), true) ?? [])
    : [];

if ($coassurance_id !== '') {
    switch ($method) {
        case 'PUT':
            updateCoassurance($conn, $policy_id, $coassurance_id, $company_id, $input, $username);
            break;
        case 'DELETE':
            deleteCoassurance($conn, $policy_id, $coassurance_id, $company_id, $username);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} else {
    switch ($method) {
        case 'GET':
            listCoassurance($conn, $policy_id, $company_id);
            break;
        case 'POST':
            addCoassurance($conn, $policy_id, $company_id, $input, $username);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
}
