<?php

// kendaraan = one vehicle of a fleet schedule (policy_vehicles); berharga = high-value contents item.
const COVERAGE_TYPES = ['bangunan', 'stok', 'invenisi', 'mesin', 'kendaraan', 'berharga', 'dll'];

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
        // Risk location (policy_locations) the item belongs to; ownership is checked by the caller.
        'location_id'    => isset($input['location_id']) && is_string($input['location_id']) && trim($input['location_id']) !== ''
            ? trim($input['location_id'])
            : null,
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
    $location       = !empty($row['location_id']) ? "'" . mysqli_real_escape_string($conn, $row['location_id']) . "'" : 'NULL';

    $ok = mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policy_coverages
             (coverage_id, policy_id, coverage_type, coverage_label, sum_insured, rate_permille, premium_amount, count_in_tsi, location_id, created_by, created_at)
          VALUES ('$coverage_id', '$pid', '$type', $label, $sum_insured, $rate_permille, $premium_amount, $count_in_tsi, $location, '$usr', '$now')"
    );

    if (!$ok) {
        error_log("insertCoverageRow failed for policy $policy_id: " . mysqli_error($conn));
        return null;
    }
    return $coverage_id;
}

/**
 * Recomputes sum_insured, premium_amount and the commission / customer-premium breakdown of the policy
 * from its coverage items (count_in_tsi rows only for the TSI).
 *
 * $kind decides what else moves:
 *   correction   the agent fixed their own entry: the pending commission record and the original debit
 *                note (issued_premium_amount / issued_customer_premium) follow the new figures.
 *   endorsement  the insurer changed the contract: only the current annual figures move. The premium
 *                difference is billed through its own note (helpers/endorsements.php), so the original
 *                debit note and the policy's commission record stay as issued.
 */
function syncPolicyTotals($conn, $policy_id, string $kind = 'correction') {
    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    mysqli_begin_transaction($conn);

    // Figures before the change: a correction moves the original debit note by exactly its own difference.
    $beforeRes = mysqli_query($conn, "SELECT premium_amount, customer_premium_amount FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$policy_id' FOR UPDATE");
    $before = $beforeRes ? mysqli_fetch_assoc($beforeRes) : ['premium_amount' => 0, 'customer_premium_amount' => 0];
    $bp = (int)($before['premium_amount'] ?? 0);
    $bc = (int)($before['customer_premium_amount'] ?? 0);

    $ok1 = mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".policies p
         SET
           p.sum_insured       = COALESCE((SELECT SUM(c.sum_insured)    FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$policy_id' AND c.count_in_tsi = 1), 0),
           p.premium_amount    = COALESCE((SELECT SUM(c.premium_amount) FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$policy_id'), 0),
           p.commission_amount = ROUND(
             COALESCE((SELECT SUM(c.premium_amount) FROM " . APP_SCHEMA . ".policy_coverages c WHERE c.policy_id = '$policy_id'), 0)
             * p.commission_rate / 100
           )
         WHERE p.policy_id = '$policy_id'"
    );

    $ok2 = mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".policies p
         SET
           p.commission_tax_amount = ROUND(p.commission_amount * p.commission_tax_rate),
           p.net_commission_amount = p.commission_amount - ROUND(p.commission_amount * p.commission_tax_rate),
           p.customer_premium_amount = p.premium_amount + p.materai_amount + p.biaya_polis - p.commission_amount - p.diskon
         WHERE p.policy_id = '$policy_id'"
    );

    $ok3 = true;
    if ($kind !== 'endorsement') {
        // Correction: shift the original debit note by the difference (it may already differ from the annual
        // figures because of endorsements), then keep the pending commission on that original note.
        $ok3 = mysqli_query($conn,
            "UPDATE " . APP_SCHEMA . ".policies
             SET issued_premium_amount   = COALESCE(issued_premium_amount, $bp) + (premium_amount - $bp),
                 issued_customer_premium = COALESCE(issued_customer_premium, $bc) + (customer_premium_amount - $bc)
             WHERE policy_id = '$policy_id'"
        ) && mysqli_query($conn,
            "UPDATE " . APP_SCHEMA . ".commissions cm
             JOIN  " . APP_SCHEMA . ".policies p ON p.policy_id = cm.policy_id
             SET cm.premium_amount        = p.issued_premium_amount,
                 cm.expected_amount       = ROUND(p.issued_premium_amount * p.commission_rate / 100),
                 cm.commission_tax_amount = ROUND(ROUND(p.issued_premium_amount * p.commission_rate / 100) * p.commission_tax_rate),
                 cm.net_expected_amount   = ROUND(p.issued_premium_amount * p.commission_rate / 100)
                                            - ROUND(ROUND(p.issued_premium_amount * p.commission_rate / 100) * p.commission_tax_rate),
                 cm.sub_agent_amount      = ROUND(ROUND(p.issued_premium_amount * p.commission_rate / 100) * COALESCE(cm.sub_agent_split_rate, 0) / 100),
                 cm.updated_at            = NOW()
             WHERE cm.policy_id = '$policy_id' AND cm.status = 'pending'"
        );
    }

    if ($ok1 && $ok2 && $ok3) {
        mysqli_commit($conn);
    } else {
        mysqli_rollback($conn);
        error_log("syncPolicyTotals failed for policy $policy_id: " . mysqli_error($conn));
    }
}

/** Current TSI and annual premium of a policy, for before/after comparisons around a change. */
function policyContractTotals($conn, string $policy_id): array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT sum_insured, premium_amount FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pid'"));
    return ['sum_insured' => (int)($r['sum_insured'] ?? 0), 'premium' => (int)($r['premium_amount'] ?? 0)];
}

/** Throws unless $location_id is null or a location of this policy. */
function coverageAssertLocation($conn, string $policy_id, ?string $location_id): void {
    if ($location_id === null) return;
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $lid = mysqli_real_escape_string($conn, $location_id);
    $res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".policy_locations WHERE location_id = '$lid' AND policy_id = '$pid' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_V21_001', ['field' => 'location_id']);
}

