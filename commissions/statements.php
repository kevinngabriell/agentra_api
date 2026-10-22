<?php
// [NEW v1.8.0] Commission reconciliation per insurer statement.
//   GET    /commissions/statements                          list (filters: insurer_id, month, page, limit)
//   POST   /commissions/statements                          record a statement (optional `items` to match at once)
//   GET    /commissions/statements/{id}                     statement + matched commissions
//   POST   /commissions/statements/{id}/match               match commissions: items[{commission_id, allocated_amount?}]
//   DELETE /commissions/statements/{id}/items/{commission_id}   unmatch one commission (back to pending)
//   DELETE /commissions/statements/{id}                     delete the statement, unmatching everything
//
// Matching a commission marks it received with the statement's date and reference, using the same
// received/discrepancy rule as PATCH /commissions/{id}/mark-received (±1 IDR against expected_amount).

require_once __DIR__ . '/../helpers/policy_log.php';

function statementSelectSql(): string {
    return "SELECT s.statement_id, s.insurer_id, s.statement_date, s.amount, s.reference_number, s.notes,
            s.created_by, s.created_at,
            i.name AS insurer_name, i.short_name AS insurer_short_name,
            COALESCE(m.matched_count, 0)      AS matched_count,
            COALESCE(m.matched_amount, 0)     AS matched_amount,
            COALESCE(m.expected_amount, 0)    AS matched_expected,
            COALESCE(m.discrepancy_count, 0)  AS discrepancy_count
        FROM " . APP_SCHEMA . ".commission_statements s
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = s.insurer_id
        LEFT JOIN (
            SELECT it.statement_id,
                   COUNT(*)                  AS matched_count,
                   SUM(it.allocated_amount)  AS matched_amount,
                   SUM(cm.expected_amount)   AS expected_amount,
                   SUM(CASE WHEN ABS(it.allocated_amount - cm.expected_amount) > 1 THEN 1 ELSE 0 END) AS discrepancy_count
            FROM " . APP_SCHEMA . ".commission_statement_items it
            JOIN " . APP_SCHEMA . ".commissions cm ON cm.commission_id = it.commission_id
            GROUP BY it.statement_id
        ) m ON m.statement_id = s.statement_id";
}

function shapeStatement(array $r): array {
    $amount  = (int)$r['amount'];
    $matched = (int)$r['matched_amount'];
    return [
        'statement_id'      => $r['statement_id'],
        'insurer_id'        => $r['insurer_id'],
        'insurer_name'      => $r['insurer_name'],
        'insurer_short_name'=> $r['insurer_short_name'],
        'statement_date'    => $r['statement_date'],
        'amount'            => $amount,
        'reference_number'  => $r['reference_number'],
        'notes'             => $r['notes'],
        'created_by'        => $r['created_by'],
        'created_at'        => $r['created_at'],
        'matched_count'     => (int)$r['matched_count'],
        'matched_amount'    => $matched,
        'matched_expected'  => (int)$r['matched_expected'],
        'unmatched_amount'  => $amount - $matched,
        'discrepancy_count' => (int)$r['discrepancy_count'],
    ];
}

function loadStatement($conn, string $statement_id, string $company_id): ?array {
    $sid = mysqli_real_escape_string($conn, $statement_id);
    $cmp = mysqli_real_escape_string($conn, $company_id);
    $res = mysqli_query($conn, statementSelectSql() . " WHERE s.statement_id = '$sid' AND s.company_id = '$cmp' LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ? shapeStatement($row) : null;
}

function listStatements($conn, string $company_id, array $params): void {
    $page   = max(1, (int)($params['page'] ?? 1));
    $limit  = min(100, max(1, (int)($params['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "s.company_id = '" . mysqli_real_escape_string($conn, $company_id) . "'";
    if (!empty($params['insurer_id'])) {
        $where .= " AND s.insurer_id = '" . mysqli_real_escape_string($conn, $params['insurer_id']) . "'";
    }
    if (!empty($params['month']) && preg_match('/^\d{4}-\d{2}$/', $params['month'])) {
        $where .= " AND DATE_FORMAT(s.statement_date, '%Y-%m') = '" . $params['month'] . "'";
    }

    $res = mysqli_query($conn, statementSelectSql() . " WHERE $where ORDER BY s.statement_date DESC, s.created_at DESC LIMIT $limit OFFSET $offset");
    if (!$res) { jsonResponse(500, 'Failed to fetch statements', ['error' => mysqli_error($conn)]); return; }
    $cnt   = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".commission_statements s WHERE $where");
    $total = $cnt ? (int)mysqli_fetch_assoc($cnt)['total'] : 0;

    jsonResponse(200, 'Statements found', [
        'data'       => array_map('shapeStatement', mysqli_fetch_all($res, MYSQLI_ASSOC)),
        'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => (int)ceil($total / $limit)],
    ]);
}

function getStatementDetail($conn, string $statement_id, string $company_id): void {
    $statement = loadStatement($conn, $statement_id, $company_id);
    if (!$statement) { jsonResponse(404, 'Statement not found'); return; }

    $sid = mysqli_real_escape_string($conn, $statement_id);
    $res = mysqli_query($conn,
        "SELECT cm.commission_id, cm.policy_id, cm.expected_amount, cm.net_expected_amount, cm.received_amount, cm.status,
                it.allocated_amount, p.policy_number, p.product_type, c.display_name AS customer_name
         FROM " . APP_SCHEMA . ".commission_statement_items it
         JOIN " . APP_SCHEMA . ".commissions cm ON cm.commission_id = it.commission_id
         LEFT JOIN " . APP_SCHEMA . ".policies  p ON p.policy_id   = cm.policy_id
         LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
         WHERE it.statement_id = '$sid'
         ORDER BY it.created_at, cm.commission_id");
    $items = [];
    foreach ($res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [] as $r) {
        $r['expected_amount']  = (int)$r['expected_amount'];
        $r['received_amount']  = (int)$r['received_amount'];
        $r['allocated_amount'] = (int)$r['allocated_amount'];
        $r['difference']       = $r['allocated_amount'] - $r['expected_amount'];
        $items[] = $r;
    }
    jsonResponse(200, 'Statement found', $statement + ['items' => $items]);
}

/**
 * Matches commissions to a statement inside the caller's transaction.
 * Returns an error string, or null on success. Each item: commission_id, optional allocated_amount
 * (defaults to the commission's expected_amount).
 */
function matchCommissionsToStatement($conn, array $statement, string $company_id, array $items, string $username): ?string {
    $sid = mysqli_real_escape_string($conn, $statement['statement_id']);
    $cmp = mysqli_real_escape_string($conn, $company_id);
    $now = date('Y-m-d H:i:s');
    $uname = mysqli_real_escape_string($conn, $username);

    $remainder = (int)$statement['unmatched_amount'];
    $seen = [];

    foreach ($items as $item) {
        $commission_id = is_array($item) ? trim((string)($item['commission_id'] ?? '')) : '';
        if ($commission_id === '') return 'Each item needs a commission_id';
        if (isset($seen[$commission_id])) return "Commission $commission_id is listed twice";
        $seen[$commission_id] = true;
        $cid = mysqli_real_escape_string($conn, $commission_id);

        $res = mysqli_query($conn,
            "SELECT cm.commission_id, cm.policy_id, cm.insurer_id, cm.expected_amount, cm.status, it.statement_id AS matched_to
             FROM " . APP_SCHEMA . ".commissions cm
             LEFT JOIN " . APP_SCHEMA . ".commission_statement_items it ON it.commission_id = cm.commission_id
             WHERE cm.commission_id = '$cid' AND cm.company_id = '$cmp' LIMIT 1 FOR UPDATE");
        $cm = $res ? mysqli_fetch_assoc($res) : null;
        if (!$cm)                                        return "Commission $commission_id not found";
        if ($cm['insurer_id'] !== $statement['insurer_id']) return "Commission $commission_id belongs to a different insurer";
        if ($cm['status'] === 'cancelled')               return "Commission $commission_id is cancelled";
        if ($cm['matched_to'] !== null)                  return "Commission $commission_id is already matched to a statement";

        $expected  = (int)$cm['expected_amount'];
        $allocated = $expected;
        if (isset($item['allocated_amount']) && $item['allocated_amount'] !== '') {
            if (!is_numeric($item['allocated_amount']) || (int)$item['allocated_amount'] < 0) {
                return "allocated_amount for $commission_id must be a non-negative integer";
            }
            $allocated = (int)$item['allocated_amount'];
        }

        $remainder -= $allocated;
        if ($remainder < 0) return 'Matched commissions exceed the statement amount';

        $status = abs($allocated - $expected) <= 1 ? 'received' : 'discrepancy';
        $date = mysqli_real_escape_string($conn, $statement['statement_date']);
        $ref  = $statement['reference_number'] !== null && $statement['reference_number'] !== ''
            ? "'" . mysqli_real_escape_string($conn, $statement['reference_number']) . "'" : 'NULL';
        $note = $status === 'discrepancy'
            ? "'" . mysqli_real_escape_string($conn, 'Selisih pada rekonsiliasi statement ' . ($statement['reference_number'] ?: $statement['statement_id'])) . "'" : 'NULL';

        $ok = mysqli_query($conn,
            "INSERT INTO " . APP_SCHEMA . ".commission_statement_items (statement_id, commission_id, allocated_amount, created_at)
             VALUES ('$sid', '$cid', $allocated, '$now')")
          && mysqli_query($conn,
            "UPDATE " . APP_SCHEMA . ".commissions
             SET received_amount = $allocated, received_date = '$date', reference_number = $ref,
                 discrepancy_notes = $note, status = '$status', marked_by = '$uname', marked_at = '$now', updated_at = '$now'
             WHERE commission_id = '$cid' AND company_id = '$cmp'");
        if (!$ok) {
            error_log('matchCommissionsToStatement failed: ' . mysqli_error($conn));
            return 'Failed to match commissions';
        }

        $diff = $allocated - $expected;
        insertPolicyLog($conn, $cm['policy_id'], $company_id,
            $status === 'received' ? 'commission_marked_received' : 'commission_discrepancy',
            ($status === 'received' ? 'Komisi diterima' : 'Selisih komisi') . ' via statement: Rp ' . number_format($allocated, 0, ',', '.')
                . ($status === 'discrepancy' ? ' (selisih ' . ($diff > 0 ? '+' : '') . number_format($diff, 0, ',', '.') . ')' : ''),
            $username, null, null, 'commissions', $commission_id,
            ['expected_amount' => $expected, 'received_amount' => $allocated, 'status' => $status, 'statement_id' => $statement['statement_id']]);
    }
    return null;
}

/**
 * Resets commissions (all of a statement's, or one) back to pending and drops the match rows.
 * Runs inside the caller's transaction; throws on a failed query so the caller can roll back.
 */
function unmatchStatementCommissions($conn, string $statement_id, string $company_id, ?string $commission_id = null): void {
    $sid = mysqli_real_escape_string($conn, $statement_id);
    $cmp = mysqli_real_escape_string($conn, $company_id);
    $only   = $commission_id !== null ? " AND it.commission_id = '" . mysqli_real_escape_string($conn, $commission_id) . "'" : '';
    $onlyNa = $commission_id !== null ? " AND commission_id = '" . mysqli_real_escape_string($conn, $commission_id) . "'" : '';
    $now = date('Y-m-d H:i:s');

    $ok = mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".commissions cm
         JOIN " . APP_SCHEMA . ".commission_statement_items it ON it.commission_id = cm.commission_id
         SET cm.received_amount = 0, cm.received_date = NULL, cm.reference_number = NULL, cm.discrepancy_notes = NULL,
             cm.status = 'pending', cm.marked_by = NULL, cm.marked_at = NULL, cm.updated_at = '$now'
         WHERE it.statement_id = '$sid' AND cm.company_id = '$cmp' AND cm.status <> 'cancelled'$only")
      && mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".commission_statement_items WHERE statement_id = '$sid'$onlyNa");
    if (!$ok) throw new RuntimeException('unmatchStatementCommissions: ' . mysqli_error($conn));
}

function createStatement($conn, string $company_id, array $input, string $username): void {
    $insurer_id = trim((string)($input['insurer_id'] ?? ''));
    $date       = trim((string)($input['statement_date'] ?? ''));
    if ($insurer_id === '') { jsonResponse(400, 'insurer_id is required'); return; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { jsonResponse(400, 'statement_date is required (YYYY-MM-DD)'); return; }
    if (!isset($input['amount']) || !is_numeric($input['amount']) || (int)$input['amount'] <= 0) { jsonResponse(400, 'amount must be a positive integer'); return; }
    $amount = (int)$input['amount'];
    $items  = $input['items'] ?? [];
    if (!is_array($items)) { jsonResponse(400, 'items must be an array'); return; }

    $iid = mysqli_real_escape_string($conn, $insurer_id);
    $cmp = mysqli_real_escape_string($conn, $company_id);
    $chk = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$iid' AND company_id = '$cmp' LIMIT 1");
    if (!$chk || mysqli_num_rows($chk) === 0) { jsonResponse(400, 'Insurer not found'); return; }

    $ref   = trim((string)($input['reference_number'] ?? ''));
    $notes = trim((string)($input['notes'] ?? ''));
    $refSql   = $ref   !== '' ? "'" . mysqli_real_escape_string($conn, $ref) . "'"   : 'NULL';
    $notesSql = $notes !== '' ? "'" . mysqli_real_escape_string($conn, $notes) . "'" : 'NULL';
    $statement_id = 'stm_' . bin2hex(random_bytes(10));
    $uname = mysqli_real_escape_string($conn, $username);

    mysqli_begin_transaction($conn);
    try {
        $ok = mysqli_query($conn,
            "INSERT INTO " . APP_SCHEMA . ".commission_statements
             (statement_id, company_id, insurer_id, statement_date, amount, reference_number, notes, created_by)
             VALUES ('$statement_id', '$cmp', '$iid', '$date', $amount, $refSql, $notesSql, '$uname')");
        if (!$ok) throw new RuntimeException(mysqli_error($conn));

        if ($items) {
            $statement = loadStatement($conn, $statement_id, $company_id);
            $err = matchCommissionsToStatement($conn, $statement, $company_id, $items, $username);
            if ($err !== null) {
                mysqli_rollback($conn);
                jsonResponse(400, $err);
                return;
            }
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        error_log('createStatement failed: ' . $e->getMessage());
        Response::internalError();
    }

    jsonResponse(201, 'Statement recorded', loadStatement($conn, $statement_id, $company_id));
}

function matchStatement($conn, string $statement_id, string $company_id, array $input, string $username): void {
    $items = $input['items'] ?? null;
    if (!is_array($items) || !$items) { jsonResponse(400, 'items is required'); return; }

    mysqli_begin_transaction($conn);
    try {
        // Lock the statement row so concurrent matches can't both pass the remainder check.
        $sid = mysqli_real_escape_string($conn, $statement_id);
        $cmp = mysqli_real_escape_string($conn, $company_id);
        $lock = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".commission_statements WHERE statement_id = '$sid' AND company_id = '$cmp' FOR UPDATE");
        if (!$lock || mysqli_num_rows($lock) === 0) { mysqli_rollback($conn); jsonResponse(404, 'Statement not found'); return; }

        $statement = loadStatement($conn, $statement_id, $company_id);
        $err = matchCommissionsToStatement($conn, $statement, $company_id, $items, $username);
        if ($err !== null) { mysqli_rollback($conn); jsonResponse(400, $err); return; }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        error_log('matchStatement failed: ' . $e->getMessage());
        Response::internalError();
    }
    getStatementDetail($conn, $statement_id, $company_id);
}

function unmatchStatementItem($conn, string $statement_id, string $commission_id, string $company_id): void {
    if (!loadStatement($conn, $statement_id, $company_id)) { jsonResponse(404, 'Statement not found'); return; }
    $sid = mysqli_real_escape_string($conn, $statement_id);
    $cid = mysqli_real_escape_string($conn, $commission_id);
    $chk = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".commission_statement_items WHERE statement_id = '$sid' AND commission_id = '$cid' LIMIT 1");
    if (!$chk || mysqli_num_rows($chk) === 0) { jsonResponse(404, 'Commission is not matched to this statement'); return; }

    mysqli_begin_transaction($conn);
    try {
        unmatchStatementCommissions($conn, $statement_id, $company_id, $commission_id);
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    getStatementDetail($conn, $statement_id, $company_id);
}

function deleteStatement($conn, string $statement_id, string $company_id): void {
    if (!loadStatement($conn, $statement_id, $company_id)) { jsonResponse(404, 'Statement not found'); return; }
    $sid = mysqli_real_escape_string($conn, $statement_id);
    mysqli_begin_transaction($conn);
    try {
        unmatchStatementCommissions($conn, $statement_id, $company_id);
        if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".commission_statements WHERE statement_id = '$sid'")) {
            throw new RuntimeException('deleteStatement: ' . mysqli_error($conn));
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    jsonResponse(200, 'Statement deleted', ['statement_id' => $statement_id]);
}
