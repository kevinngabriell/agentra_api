<?php

const COVERAGE_TYPES = ['bangunan', 'stok', 'invenisi', 'mesin', 'dll'];

// Validates one item-pertanggungan payload and computes its premium.
// Returns ['row' => [...]] on success or ['error' => 'message'] on failure — the caller decides how to respond.
// Shared by POST /policies (inline coverages at creation) and POST /policies/{id}/coverages (added later, an endorsement).
function parseCoverageInput(array $input): array {
    foreach (['coverage_type', 'sum_insured', 'rate_permille'] as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            return ['error' => "$field is required"];
        }
    }

    $coverage_type = is_string($input['coverage_type']) ? strtolower(trim($input['coverage_type'])) : '';
    if (!in_array($coverage_type, COVERAGE_TYPES, true)) {
        return ['error' => 'coverage_type must be one of: ' . implode(', ', COVERAGE_TYPES)];
    }

    $sum_insured = (int)$input['sum_insured'];
    if ($sum_insured < 0) {
        return ['error' => 'sum_insured must be a non-negative integer'];
    }

    $rate_permille = $input['rate_permille'];
    if (!is_numeric($rate_permille) || $rate_permille < 0) {
        return ['error' => 'rate_permille must be a non-negative number'];
    }
    $rate_permille = number_format((float)$rate_permille, 4, '.', '');

    $coverage_label = isset($input['coverage_label']) && is_string($input['coverage_label']) && trim($input['coverage_label']) !== ''
        ? trim($input['coverage_label'])
        : null;

    return ['row' => [
        'coverage_type'  => $coverage_type,
        'coverage_label' => $coverage_label,
        'sum_insured'    => $sum_insured,
        'rate_permille'  => $rate_permille,
        'premium_amount' => (int)round($sum_insured * (float)$rate_permille / 1000),
        // count_in_tsi=false lets agents add extra clauses (RSMD, OTHERS) on the same object
        // without inflating the policy TSI — premium still calculated on the full sum_insured.
        'count_in_tsi'   => isset($input['count_in_tsi']) ? ($input['count_in_tsi'] ? 1 : 0) : 1,
    ]];
}

// Inserts one parsed coverage row and returns its coverage_id, or null if the insert failed.
// Does not touch policy totals or the audit log — the caller owns both.
function insertCoverageRow($conn, $policy_id, array $row, $username, $now) {
    $coverage_id = 'cov_' . uniqid();

    $pid   = mysqli_real_escape_string($conn, $policy_id);
    $type  = mysqli_real_escape_string($conn, $row['coverage_type']);
    $label = $row['coverage_label'] !== null ? "'" . mysqli_real_escape_string($conn, $row['coverage_label']) . "'" : 'NULL';
    $usr   = mysqli_real_escape_string($conn, $username);

    $ok = mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policy_coverages
             (coverage_id, policy_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, count_in_tsi, created_by, created_at)
         VALUES ('$coverage_id', '$pid', '$type', $label, {$row['sum_insured']}, {$row['rate_permille']}, {$row['premium_amount']}, {$row['count_in_tsi']}, '$usr', '$now')"
    );

    return $ok ? $coverage_id : null;
}
