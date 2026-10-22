<?php

const COVERAGE_TYPES = ['bangunan', 'stok', 'invenisi', 'mesin', 'dll'];

// Sane ceiling for rate_permille. 1000‰ = 100% — far above any real insurance
// rate. Caps the premium computation so an absurd value can't overflow int or
// distort reports.
const RATE_PERMILLE_MAX = 1000;

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

    // Strict integer validation — (int)"abc" yields 0 in PHP, which would
    // silently create a coverage row with sum_insured=0.
    $si = $input['sum_insured'];
    if (!is_int($si) && (!is_string($si) || !preg_match('/^\d+$/', $si))) {
        return ['error' => 'sum_insured must be a non-negative integer'];
    }
    $sum_insured = (int)$si;
    if ($sum_insured < 0) {
        return ['error' => 'sum_insured must be a non-negative integer'];
    }

    $rate_permille = $input['rate_permille'];
    if (!is_numeric($rate_permille) || $rate_permille < 0) {
        return ['error' => 'rate_permille must be a non-negative number'];
    }
    if ((float)$rate_permille > RATE_PERMILLE_MAX) {
        return ['error' => 'rate_permille exceeds the maximum allowed value (' . RATE_PERMILLE_MAX . '‰)'];
    }
    $rate_permille = number_format((float)$rate_permille, 4, '.', '');

    $coverage_label = isset($input['coverage_label']) && is_string($input['coverage_label']) && trim($input['coverage_label']) !== ''
        ? trim($input['coverage_label'])
        : null;

    // Overflow guard for the premium computation.
    $premium = (int)round($sum_insured * (float)$rate_permille / 1000);
    if ($sum_insured > 0 && (float)$rate_permille > 0 && $premium <= 0) {
        return ['error' => 'computed premium exceeds maximum supported value'];
    }

    return ['row' => [
        'coverage_type'  => $coverage_type,
        'coverage_label' => $coverage_label,
        'sum_insured'    => $sum_insured,
        'rate_permille'  => $rate_permille,
        'premium_amount' => $premium,
        // count_in_tsi=false lets agents add extra clauses (RSMD, OTHERS) on the same object
        // without inflating the policy TSI — premium still calculated on the full sum_insured.
        // Use filter_var so the string "false" is treated as false (PHP truthiness would treat it as true).
        'count_in_tsi'   => isset($input['count_in_tsi'])
            ? (filter_var($input['count_in_tsi'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
            : 1,
    ]];
}

// Inserts one parsed coverage row and returns its coverage_id, or null if the insert failed.
// Does not touch policy totals or the audit log — the caller owns both.
function insertCoverageRow($conn, $policy_id, array $row, $username, $now) {
    // Use random_bytes for the ID — uniqid() is microsecond-predictable and
    // collides under high concurrency, enabling IDOR via ID enumeration.
    $coverage_id = 'cov_' . bin2hex(random_bytes(10));

    $pid   = mysqli_real_escape_string($conn, $policy_id);
    $type  = mysqli_real_escape_string($conn, $row['coverage_type']);
    $label = $row['coverage_label'] !== null ? "'" . mysqli_real_escape_string($conn, $row['coverage_label']) . "'" : 'NULL';
    $usr   = mysqli_real_escape_string($conn, $username);

    // Cast numerics defensively at the helper boundary — never trust callers.
    $sum_insured    = (int)$row['sum_insured'];
    $rate_permille  = number_format((float)$row['rate_permille'], 4, '.', '');
    $premium_amount = (int)$row['premium_amount'];
    $count_in_tsi   = $row['count_in_tsi'] ? 1 : 0;

    $ok = mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policy_coverages
             (coverage_id, policy_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, count_in_tsi, created_by, created_at)
          VALUES ('$coverage_id', '$pid', '$type', $label, $sum_insured, $rate_permille, $premium_amount, $count_in_tsi, '$usr', '$now')"
    );

    if (!$ok) {
        error_log("insertCoverageRow failed for policy $policy_id: " . mysqli_error($conn));
        return null;
    }
    return $coverage_id;
}
