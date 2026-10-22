<?php

// Deductibles, clauses and extensions (migration_policy_terms.sql). Pure functions used by
// policies/terms.php and policies/renew.php.

const TERM_TYPES        = ['deductible', 'clause', 'extension'];
const TERM_DEDUCTIBLES  = ['fixed', 'percent_claim', 'percent_sum_insured'];
const TERM_MAX_PER_POLICY = 200;

function termText(mixed $value, string $field, int $max): ?string {
    if ($value === null) return null;
    if (!is_scalar($value) || is_bool($value)) throw ApiException::from('ERR_TERM_003', ['field' => $field]);
    $s = trim((string)$value);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) throw ApiException::from('ERR_TERM_004', ['field' => $field, 'max' => $max]);
    return $s;
}

function termMoney(mixed $value, string $field): ?int {
    if ($value === null || $value === '') return null;
    if (is_int($value)) {
        $n = $value;
    } elseif (is_string($value) && preg_match('/^\d{1,13}$/', trim($value))) {
        $n = (int)trim($value);
    } else {
        throw ApiException::from('ERR_TERM_005', ['field' => $field]);
    }
    if ($n < 0 || $n > 9_999_999_999_999) throw ApiException::from('ERR_TERM_005', ['field' => $field]);
    return $n;
}

function termPercent(mixed $value, string $field): ?string {
    if ($value === null || $value === '') return null;
    if (!is_numeric($value) || (float)$value <= 0 || (float)$value > 100) throw ApiException::from('ERR_TERM_006', ['field' => $field]);
    return number_format((float)$value, 3, '.', '');
}

/**
 * Validates a create/update payload. Returns the columns to store (nulls included, so an edit can clear a field).
 * `$current` is the stored row on edit; omitted fields fall back to it.
 */
function termParseInput(array $input, ?array $current = null): array {
    $pick = fn(string $k) => array_key_exists($k, $input) ? $input[$k] : ($current[$k] ?? null);

    $type = $current['term_type'] ?? (is_string($input['term_type'] ?? null) ? strtolower(trim($input['term_type'])) : '');
    if (!in_array($type, TERM_TYPES, true)) throw ApiException::from('ERR_TERM_002');

    $row = [
        'term_type' => $type, 'clause_code' => null, 'title' => null, 'description' => null,
        'deductible_type' => null, 'deductible_amount' => null, 'deductible_percent' => null,
    ];
    $row['clause_code'] = termText($pick('clause_code'), 'clause_code', 40);
    $row['description'] = termText($pick('description'), 'description', 4000);

    if ($type === 'deductible') {
        $dt = is_string($pick('deductible_type')) ? strtolower(trim($pick('deductible_type'))) : '';
        if (!in_array($dt, TERM_DEDUCTIBLES, true)) throw ApiException::from('ERR_TERM_007');
        $row['deductible_type']   = $dt;
        $row['deductible_amount'] = termMoney($pick('deductible_amount'), 'deductible_amount');
        if ($dt === 'fixed') {
            if ($row['deductible_amount'] === null) throw ApiException::from('ERR_TERM_008', ['field' => 'deductible_amount']);
        } else {
            $row['deductible_percent'] = termPercent($pick('deductible_percent'), 'deductible_percent');
            if ($row['deductible_percent'] === null) throw ApiException::from('ERR_TERM_008', ['field' => 'deductible_percent']);
        }
        $row['title'] = termText($pick('title'), 'title', 200) ?? 'Risiko Sendiri';
    } else {
        $row['title'] = termText($pick('title'), 'title', 200);
        if ($row['title'] === null) throw ApiException::from('ERR_TERM_008', ['field' => 'title']);
    }
    return $row;
}

function termSqlText($conn, ?string $s): string {
    return $s === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $s) . "'";
}

/** Plain-language rendering of a deductible, for the audit log. */
function termDeductibleLabel(array $t): string {
    $idr = fn($n) => 'Rp ' . number_format((int)$n, 0, ',', '.');
    if ($t['deductible_type'] === 'fixed') return $idr($t['deductible_amount']);
    $base = $t['deductible_type'] === 'percent_claim' ? 'dari nilai klaim' : 'dari nilai pertanggungan';
    $min  = $t['deductible_amount'] !== null ? ", minimum {$idr($t['deductible_amount'])}" : '';
    return ((float)$t['deductible_percent']) . "% $base$min";
}

function termNormalize(array $r): array {
    $r['deductible_amount']  = $r['deductible_amount'] === null ? null : (int)$r['deductible_amount'];
    $r['deductible_percent'] = $r['deductible_percent'] === null ? null : (float)$r['deductible_percent'];
    return $r;
}

/**
 * Validates the optional `terms` array of POST /policies before anything is written.
 * A term may carry `coverage_index` (0-based position in the same request's `coverages`) to attach to that item.
 * Returns rows ready for termInsertRows(); throws ApiException with the index in the message on a bad row.
 */
function termsParseList(mixed $terms, int $coverage_count): array {
    if ($terms === null) return [];
    if (!is_array($terms)) throw ApiException::from('ERR_TERM_003', ['field' => 'terms']);
    if (count($terms) > TERM_MAX_PER_POLICY) throw ApiException::from('ERR_TERM_010', ['max' => TERM_MAX_PER_POLICY]);
    $rows = [];
    foreach (array_values($terms) as $i => $t) {
        if (!is_array($t)) throw ApiException::from('ERR_TERM_003', ['field' => "terms[$i]"]);
        $row = termParseInput($t);
        $idx = $t['coverage_index'] ?? null;
        if ($idx === null || $idx === '') {
            $row['coverage_index'] = null;
        } else {
            if (!is_int($idx) || $idx < 0 || $idx >= $coverage_count) throw ApiException::from('ERR_TERM_009');
            $row['coverage_index'] = $idx;
        }
        $rows[] = $row;
    }
    return $rows;
}

/** Inserts parsed terms for a new policy. `$coverage_ids` is the new coverage_id by request position. False on failure. */
function termInsertRows($conn, string $policy_id, string $company_id, array $rows, array $coverage_ids, string $username): bool {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $usr = mysqli_real_escape_string($conn, $username);
    foreach ($rows as $row) {
        $cov = $row['coverage_index'] !== null && isset($coverage_ids[$row['coverage_index']])
            ? "'" . mysqli_real_escape_string($conn, $coverage_ids[$row['coverage_index']]) . "'" : 'NULL';
        $id = 'trm_' . bin2hex(random_bytes(10));
        $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policy_terms
            (term_id, company_id, policy_id, coverage_id, term_type, clause_code, title, description, deductible_type, deductible_amount, deductible_percent, created_by)
            VALUES ('$id', '$company_id', '$pid', $cov, '{$row['term_type']}', " . termSqlText($conn, $row['clause_code']) . ", " . termSqlText($conn, $row['title']) . ",
                " . termSqlText($conn, $row['description']) . ", " . termSqlText($conn, $row['deductible_type']) . ",
                " . ($row['deductible_amount'] === null ? 'NULL' : (int)$row['deductible_amount']) . ",
                " . ($row['deductible_percent'] === null ? 'NULL' : $row['deductible_percent']) . ", '$usr')");
        if (!$ok) return false;
    }
    return true;
}

/** Copies a policy's terms onto its renewal. `$coverage_map` is old coverage_id => new coverage_id. */
function termsCopyToRenewal($conn, string $from_policy_id, string $to_policy_id, string $company_id, string $username, array $coverage_map): bool {
    $from = mysqli_real_escape_string($conn, $from_policy_id);
    $res  = mysqli_query($conn, "SELECT coverage_id, term_type, clause_code, title, description, deductible_type, deductible_amount, deductible_percent
        FROM " . APP_SCHEMA . ".policy_terms WHERE policy_id = '$from' AND company_id = '$company_id'");
    if (!$res) return false;
    $to  = mysqli_real_escape_string($conn, $to_policy_id);
    $usr = mysqli_real_escape_string($conn, $username);
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $t) {
        $cov = 'NULL';
        if ($t['coverage_id'] !== null) {
            if (!isset($coverage_map[$t['coverage_id']])) continue; // its coverage item was not copied
            $cov = "'" . mysqli_real_escape_string($conn, $coverage_map[$t['coverage_id']]) . "'";
        }
        $id = 'trm_' . bin2hex(random_bytes(10));
        $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policy_terms
            (term_id, company_id, policy_id, coverage_id, term_type, clause_code, title, description, deductible_type, deductible_amount, deductible_percent, created_by)
            VALUES ('$id', '$company_id', '$to', $cov, '{$t['term_type']}', " . termSqlText($conn, $t['clause_code']) . ", " . termSqlText($conn, $t['title']) . ",
                " . termSqlText($conn, $t['description']) . ", " . termSqlText($conn, $t['deductible_type']) . ",
                " . ($t['deductible_amount'] === null ? 'NULL' : (int)$t['deductible_amount']) . ",
                " . ($t['deductible_percent'] === null ? 'NULL' : (float)$t['deductible_percent']) . ", '$usr')");
        if (!$ok) return false;
    }
    return true;
}
