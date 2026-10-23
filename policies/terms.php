<?php

// Deductibles, clauses and extensions (migration_policy_terms.sql). Functions only; routing is in policies/index.php.
//
//   GET    /policies/{id}/terms             all terms, each with its coverage item label (null = whole policy)
//   POST   /policies/{id}/terms             add one (term_type, title, description, clause_code, coverage_id, deductible_*)
//   PUT    /policies/{id}/terms/{term_id}   edit one (term_type cannot change)
//   DELETE /policies/{id}/terms/{term_id}   remove one
//
// A change after issuance is an endorsement unless the request says change_kind = correction (with a reason); either way it is written to the policy log.

require_once __DIR__ . '/../helpers/policy_terms.php';
require_once __DIR__ . '/../helpers/policy_change.php';

function termFindPolicy($conn, string $policy_id, string $company_id): void {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT policy_id FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_TERM_001');
}

function termFetch($conn, string $policy_id, string $company_id, ?string $term_id = null): array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $and = $term_id !== null ? " AND t.term_id = '" . mysqli_real_escape_string($conn, $term_id) . "'" : '';
    $res = mysqli_query($conn, "SELECT t.term_id, t.coverage_id, c.coverage_type, c.coverage_label, t.term_type, t.clause_code, t.title,
            t.description, t.deductible_type, t.deductible_amount, t.deductible_percent, t.created_at, t.updated_at
        FROM " . APP_SCHEMA . ".policy_terms t
        LEFT JOIN " . APP_SCHEMA . ".policy_coverages c ON c.coverage_id = t.coverage_id
        WHERE t.policy_id = '$pid' AND t.company_id = '$company_id'$and
        ORDER BY FIELD(t.term_type, 'deductible', 'clause', 'extension'), t.created_at, t.term_id");
    if (!$res) throw new RuntimeException('termFetch: ' . mysqli_error($conn));
    return array_map('termNormalize', mysqli_fetch_all($res, MYSQLI_ASSOC));
}

/** A coverage item id must belong to this policy; '' and null mean "whole policy". */
function termResolveCoverage($conn, string $policy_id, mixed $coverage_id): ?string {
    if ($coverage_id === null || $coverage_id === '') return null;
    $cid = mysqli_real_escape_string($conn, (string)$coverage_id);
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT coverage_id FROM " . APP_SCHEMA . ".policy_coverages WHERE coverage_id = '$cid' AND policy_id = '$pid' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_TERM_009');
    return $cid;
}

function getPolicyTerms($conn, string $policy_id, string $company_id): void {
    termFindPolicy($conn, $policy_id, $company_id);
    jsonResponse(200, 'Policy terms retrieved', ['items' => termFetch($conn, $policy_id, $company_id)]);
}

function termLogLabel(array $row): string {
    $names = ['deductible' => 'risiko sendiri', 'clause' => 'klausula', 'extension' => 'perluasan'];
    $detail = $row['term_type'] === 'deductible' ? ' ' . termDeductibleLabel($row) : " {$row['title']}";
    return $names[$row['term_type']] . $detail;
}

function addPolicyTerm($conn, string $policy_id, string $company_id, array $input, string $username): void {
    termFindPolicy($conn, $policy_id, $company_id);
    $row = termParseInput($input);
    $coverage_id = termResolveCoverage($conn, $policy_id, $input['coverage_id'] ?? null);
    $change = policyChangeParse($input);

    $count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".policy_terms
        WHERE policy_id = '" . mysqli_real_escape_string($conn, $policy_id) . "'"))['n'];
    if ((int)$count >= TERM_MAX_PER_POLICY) throw ApiException::from('ERR_TERM_010', ['max' => TERM_MAX_PER_POLICY]);

    $id  = 'trm_' . bin2hex(random_bytes(10));
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $usr = mysqli_real_escape_string($conn, $username);
    $ok  = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policy_terms
        (term_id, company_id, policy_id, coverage_id, term_type, clause_code, title, description, deductible_type, deductible_amount, deductible_percent, created_by)
        VALUES ('$id', '$company_id', '$pid', " . ($coverage_id ? "'$coverage_id'" : 'NULL') . ", '{$row['term_type']}',
            " . termSqlText($conn, $row['clause_code']) . ", " . termSqlText($conn, $row['title']) . ", " . termSqlText($conn, $row['description']) . ",
            " . termSqlText($conn, $row['deductible_type']) . ", " . ($row['deductible_amount'] === null ? 'NULL' : $row['deductible_amount']) . ",
            " . ($row['deductible_percent'] === null ? 'NULL' : $row['deductible_percent']) . ", '$usr')");
    if (!$ok) {
        error_log('addPolicyTerm: ' . mysqli_error($conn));
        throw ApiException::from('ERR_TERM_011');
    }

    policyChangeLog($conn, $policy_id, $company_id, $change,
        termLogLabel($row) . ' ditambahkan', $username, 'policy_terms', $id, ['term_type' => $row['term_type']]);
    jsonResponse(201, 'Policy term added', termFetch($conn, $policy_id, $company_id, $id)[0]);
}

function updatePolicyTerm($conn, string $policy_id, string $company_id, string $term_id, array $input, string $username): void {
    termFindPolicy($conn, $policy_id, $company_id);
    $current = termFetch($conn, $policy_id, $company_id, $term_id)[0] ?? null;
    if ($current === null) throw ApiException::from('ERR_TERM_012');
    if (isset($input['term_type']) && $input['term_type'] !== $current['term_type']) throw ApiException::from('ERR_TERM_013');

    $row = termParseInput($input, $current);
    $change = policyChangeParse($input);
    $coverage_id = array_key_exists('coverage_id', $input)
        ? termResolveCoverage($conn, $policy_id, $input['coverage_id'])
        : $current['coverage_id'];

    $tid = mysqli_real_escape_string($conn, $term_id);
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $usr = mysqli_real_escape_string($conn, $username);
    $ok  = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policy_terms SET
            coverage_id = " . ($coverage_id ? "'" . mysqli_real_escape_string($conn, $coverage_id) . "'" : 'NULL') . ",
            clause_code = " . termSqlText($conn, $row['clause_code']) . ", title = " . termSqlText($conn, $row['title']) . ",
            description = " . termSqlText($conn, $row['description']) . ", deductible_type = " . termSqlText($conn, $row['deductible_type']) . ",
            deductible_amount = " . ($row['deductible_amount'] === null ? 'NULL' : $row['deductible_amount']) . ",
            deductible_percent = " . ($row['deductible_percent'] === null ? 'NULL' : $row['deductible_percent']) . ",
            updated_by = '$usr'
        WHERE term_id = '$tid' AND policy_id = '$pid' AND company_id = '$company_id'");
    if (!$ok) {
        error_log('updatePolicyTerm: ' . mysqli_error($conn));
        throw ApiException::from('ERR_TERM_011');
    }

    policyChangeLog($conn, $policy_id, $company_id, $change,
        termLogLabel($row) . ' diperbarui', $username, 'policy_terms', $term_id, ['term_type' => $row['term_type']]);
    jsonResponse(200, 'Policy term updated', termFetch($conn, $policy_id, $company_id, $term_id)[0]);
}

function deletePolicyTerm($conn, string $policy_id, string $company_id, string $term_id, string $username, array $input = []): void {
    termFindPolicy($conn, $policy_id, $company_id);
    $current = termFetch($conn, $policy_id, $company_id, $term_id)[0] ?? null;
    if ($current === null) throw ApiException::from('ERR_TERM_012');
    $change = policyChangeParse($input);

    $ok = mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_terms
        WHERE term_id = '" . mysqli_real_escape_string($conn, $term_id) . "' AND policy_id = '" . mysqli_real_escape_string($conn, $policy_id) . "' AND company_id = '$company_id'");
    if (!$ok) {
        error_log('deletePolicyTerm: ' . mysqli_error($conn));
        throw ApiException::from('ERR_TERM_011');
    }
    policyChangeLog($conn, $policy_id, $company_id, $change,
        termLogLabel($current) . ' dihapus', $username, 'policy_terms', $term_id, ['term_type' => $current['term_type']]);
    jsonResponse(200, 'Policy term deleted');
}
