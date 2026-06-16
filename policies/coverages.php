<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/policy_log.php';

const COVERAGE_TYPES = ['bangunan', 'stok', 'invenisi', 'mesin', 'dll'];

// Recomputes sum_insured, premium_amount, and commission_amount on the parent policy from its coverages.
// Also syncs the pending commission record so expected_amount stays accurate.
function syncPolicyTotals($conn, $policy_id) {
    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".policies p
         SET
           p.sum_insured       = COALESCE((SELECT SUM(c.sum_insured)    FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$policy_id'), 0),
           p.premium_amount    = COALESCE((SELECT SUM(c.premium_amount) FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$policy_id'), 0),
           p.commission_amount = ROUND(
             COALESCE((SELECT SUM(c.premium_amount) FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$policy_id'), 0)
             * p.commission_rate / 100
           )
         WHERE p.policy_id = '$policy_id'"
    );

    // Keep the commissions record in sync with the updated policy totals
    mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".commissions cm
         JOIN  " . APP_SCHEMA . ".policies p ON p.policy_id = cm.policy_id
         SET cm.premium_amount  = p.premium_amount,
             cm.expected_amount = p.commission_amount,
             cm.updated_at      = NOW()
         WHERE cm.policy_id = '$policy_id' AND cm.status = 'pending'"
    );
}

// --- GET ALL coverages for a policy ---
function getCoverages($conn, $policy_id, $company_id) {
    $policy_id  = mysqli_real_escape_string($conn, $policy_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $policy = mysqli_query($conn,
        "SELECT policy_id FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1"
    );
    if (mysqli_num_rows($policy) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $result = mysqli_query($conn,
        "SELECT coverage_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, created_at, updated_at
         FROM " . APP_SCHEMA . ".policy_coverages
         WHERE policy_id = '$policy_id'
         ORDER BY FIELD(coverage_type, 'bangunan','stok','invenisi','mesin','dll'), coverage_label ASC"
    );

    $rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    $totals = mysqli_query($conn,
        "SELECT SUM(sum_insured) AS total_sum_insured, SUM(premium_amount) AS total_premium
         FROM " . APP_SCHEMA . ".policy_coverages WHERE policy_id = '$policy_id'"
    );
    $t = $totals ? mysqli_fetch_assoc($totals) : [];

    jsonResponse(200, 'Coverages found', [
        'items'               => $rows,
        'total_sum_insured'   => (int)($t['total_sum_insured']  ?? 0),
        'total_premium'       => (int)($t['total_premium']      ?? 0),
    ]);
}

// --- ADD a coverage item ---
function addCoverage($conn, $policy_id, $input, $username, $company_id) {
    $policy_id  = mysqli_real_escape_string($conn, $policy_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $policy = mysqli_query($conn,
        "SELECT policy_id FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' AND company_id = '$company_id' LIMIT 1"
    );
    if (mysqli_num_rows($policy) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    foreach (['coverage_type', 'sum_insured', 'rate_permille'] as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            jsonResponse(400, "$field is required");
            return;
        }
    }

    $coverage_type  = strtolower(trim(mysqli_real_escape_string($conn, $input['coverage_type'])));
    $coverage_label = isset($input['coverage_label']) && trim($input['coverage_label']) !== ''
        ? "'" . mysqli_real_escape_string($conn, trim($input['coverage_label'])) . "'"
        : 'NULL';

    if (!in_array($coverage_type, COVERAGE_TYPES, true)) {
        jsonResponse(400, 'coverage_type must be one of: ' . implode(', ', COVERAGE_TYPES));
        return;
    }

    $sum_insured   = (int)$input['sum_insured'];
    $rate_permille = $input['rate_permille'];

    if ($sum_insured < 0) {
        jsonResponse(400, 'sum_insured must be a non-negative integer');
        return;
    }
    if (!is_numeric($rate_permille) || $rate_permille < 0) {
        jsonResponse(400, 'rate_permille must be a non-negative number');
        return;
    }

    $rate_permille  = number_format((float)$rate_permille, 4, '.', '');
    $premium_amount = (int)round($sum_insured * (float)$rate_permille / 1000);
    $coverage_id    = 'cov_' . uniqid();
    $now            = date('Y-m-d H:i:s');

    $sql = "INSERT INTO " . APP_SCHEMA . ".policy_coverages
                (coverage_id, policy_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, created_by, created_at)
            VALUES ('$coverage_id', '$policy_id', '$coverage_type', $coverage_label, $sum_insured, $rate_permille, $premium_amount, '$username', '$now')";

    if (mysqli_query($conn, $sql)) {
        syncPolicyTotals($conn, $policy_id);
        $label_text = isset($input['coverage_label']) && trim($input['coverage_label']) !== ''
            ? ' (' . trim($input['coverage_label']) . ')' : '';
        insertPolicyLog($conn, $policy_id, $company_id, 'endorsement',
            "Endorsemen: item pertanggungan ditambahkan — {$coverage_type}{$label_text}", $username,
            null, null, 'policy_coverages', $coverage_id,
            ['coverage_type' => $coverage_type, 'sum_insured' => $sum_insured,
             'rate_permille' => (float)$rate_permille, 'premium_amount' => $premium_amount]);
        jsonResponse(201, 'Coverage item added', ['coverage_id' => $coverage_id, 'premium_amount' => $premium_amount]);
    } else {
        jsonResponse(500, 'Failed to add coverage', ['error' => mysqli_error($conn)]);
    }
}

// --- UPDATE a coverage item ---
function updateCoverage($conn, $policy_id, $coverage_id, $input, $username, $company_id) {
    $policy_id   = mysqli_real_escape_string($conn, $policy_id);
    $coverage_id = mysqli_real_escape_string($conn, $coverage_id);
    $company_id  = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT c.sum_insured, c.rate_permille
         FROM " . APP_SCHEMA . ".policy_coverages c
         JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = c.policy_id
         WHERE c.coverage_id = '$coverage_id' AND c.policy_id = '$policy_id' AND p.company_id = '$company_id'
         LIMIT 1"
    );
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Coverage item not found');
        return;
    }

    $current = mysqli_fetch_assoc($check);
    $updates = [];

    if (isset($input['coverage_type'])) {
        $ct = strtolower(trim(mysqli_real_escape_string($conn, $input['coverage_type'])));
        if (!in_array($ct, COVERAGE_TYPES, true)) {
            jsonResponse(400, 'coverage_type must be one of: ' . implode(', ', COVERAGE_TYPES));
            return;
        }
        $updates[] = "coverage_type = '$ct'";
    }
    if (isset($input['coverage_label'])) {
        $label     = trim(mysqli_real_escape_string($conn, $input['coverage_label']));
        $updates[] = "coverage_label = " . ($label !== '' ? "'$label'" : 'NULL');
    }

    $new_sum  = isset($input['sum_insured'])   ? (int)$input['sum_insured']               : null;
    $new_rate = isset($input['rate_permille'])  ? (float)$input['rate_permille']           : null;

    if ($new_sum !== null) {
        if ($new_sum < 0) { jsonResponse(400, 'sum_insured must be non-negative'); return; }
        $updates[] = "sum_insured = $new_sum";
    }
    if ($new_rate !== null) {
        if ($new_rate < 0) { jsonResponse(400, 'rate_permille must be non-negative'); return; }
        $new_rate  = number_format($new_rate, 4, '.', '');
        $updates[] = "rate_permille = $new_rate";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
        return;
    }

    // Recompute premium
    $final_sum  = $new_sum  ?? (int)$current['sum_insured'];
    $final_rate = $new_rate ?? (float)$current['rate_permille'];
    $premium    = (int)round($final_sum * $final_rate / 1000);
    $updates[]  = "premium_amount = $premium";

    $now       = date('Y-m-d H:i:s');
    $updates[] = "updated_by = '$username'";
    $updates[] = "updated_at = '$now'";

    if (mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policy_coverages SET " . implode(', ', $updates) . " WHERE coverage_id = '$coverage_id'")) {
        syncPolicyTotals($conn, $policy_id);
        $before = ['sum_insured' => (int)$current['sum_insured'], 'rate_permille' => (float)$current['rate_permille']];
        $after  = ['sum_insured' => $final_sum, 'rate_permille' => (float)$final_rate, 'premium_amount' => $premium];
        insertPolicyLog($conn, $policy_id, $company_id, 'endorsement',
            'Endorsemen: item pertanggungan diperbarui', $username,
            null, null, 'policy_coverages', $coverage_id,
            ['before' => $before, 'after' => $after]);
        jsonResponse(200, 'Coverage item updated', ['premium_amount' => $premium]);
    } else {
        jsonResponse(500, 'Failed to update coverage', ['error' => mysqli_error($conn)]);
    }
}

// --- DELETE a coverage item ---
function deleteCoverage($conn, $policy_id, $coverage_id, $company_id, $username) {
    $policy_id   = mysqli_real_escape_string($conn, $policy_id);
    $coverage_id = mysqli_real_escape_string($conn, $coverage_id);
    $company_id  = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT c.coverage_type, c.coverage_label, c.sum_insured, c.premium_amount
         FROM " . APP_SCHEMA . ".policy_coverages c
         JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = c.policy_id
         WHERE c.coverage_id = '$coverage_id' AND c.policy_id = '$policy_id' AND p.company_id = '$company_id'
         LIMIT 1"
    );
    if (mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Coverage item not found');
        return;
    }
    $cov = mysqli_fetch_assoc($check);

    if (mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_coverages WHERE coverage_id = '$coverage_id'")) {
        syncPolicyTotals($conn, $policy_id);
        $label_text = $cov['coverage_label'] ? ' (' . $cov['coverage_label'] . ')' : '';
        insertPolicyLog($conn, $policy_id, $company_id, 'endorsement',
            "Endorsemen: item pertanggungan dihapus — {$cov['coverage_type']}{$label_text}", $username,
            null, null, 'policy_coverages', $coverage_id,
            ['coverage_type' => $cov['coverage_type'], 'coverage_label' => $cov['coverage_label'],
             'sum_insured' => (int)$cov['sum_insured'], 'premium_amount' => (int)$cov['premium_amount']]);
        jsonResponse(200, 'Coverage item deleted');
    } else {
        jsonResponse(500, 'Failed to delete coverage', ['error' => mysqli_error($conn)]);
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser    = requireAuth();
$method      = $_SERVER['REQUEST_METHOD'];
$company_id  = $authUser['company_id'] ?? null;
$username    = $authUser['user_id']    ?? $authUser['sub'] ?? null;
// $policy_id is passed in from the parent dispatcher via $action
// $coverage_id is $parts[5] from the parent dispatcher
$coverage_id = $parts[5] ?? '';

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

try {
    $conn = getConn();

    if ($coverage_id !== '') {
        switch ($method) {
            case 'PUT':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                updateCoverage($conn, $policy_id, $coverage_id, $input, $username, $company_id);
                break;
            case 'DELETE':
                deleteCoverage($conn, $policy_id, $coverage_id, $company_id, $username);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    } else {
        switch ($method) {
            case 'GET':
                getCoverages($conn, $policy_id, $company_id);
                break;
            case 'POST':
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
                addCoverage($conn, $policy_id, $input, $username, $company_id);
                break;
            default:
                jsonResponse(405, 'Method Not Allowed');
        }
    }

} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
