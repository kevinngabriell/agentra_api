<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../helpers/policy_log.php';
require_once __DIR__ . '/statements.php';
require_once __DIR__ . '/../helpers/subscription.php';

// ─── CM-001: List all commissions ────────────────────────────────────────────
function getAllCommissions($conn, $company_id, $params) {
    $page    = max(1, (int)($params['page']   ?? 1));
    $limit   = min(100, max(1, (int)($params['limit'] ?? 15)));
    $offset  = ($page - 1) * $limit;

    $where = "cm.company_id = '" . mysqli_real_escape_string($conn, $company_id) . "'";

    if (!empty($params['insurer_id'])) {
        $where .= " AND cm.insurer_id = '" . mysqli_real_escape_string($conn, $params['insurer_id']) . "'";
    }
    if (!empty($params['status']) && in_array($params['status'], ['pending', 'received', 'discrepancy', 'cancelled'], true)) {
        $where .= " AND cm.status = '" . $params['status'] . "'";
    }
    if (!empty($params['commission_type']) && in_array($params['commission_type'], ['direct', 'override'], true)) {
        $where .= " AND cm.commission_type = '" . $params['commission_type'] . "'";
    }
    if (!empty($params['month']) && preg_match('/^\d{4}-\d{2}$/', $params['month'])) {
        $where .= " AND DATE_FORMAT(p.coverage_start, '%Y-%m') = '" . $params['month'] . "'";
    }
    if (!empty($params['product_type'])) {
        $where .= " AND p.product_type = '" . mysqli_real_escape_string($conn, $params['product_type']) . "'";
    }
    // [NEW v1.8.0] Only commissions not yet matched to an insurer statement.
    if (!empty($params['unmatched'])) {
        $where .= " AND NOT EXISTS (SELECT 1 FROM " . APP_SCHEMA . ".commission_statement_items csi WHERE csi.commission_id = cm.commission_id)";
    }

    $rows = mysqli_query($conn,
        "SELECT
            cm.commission_id, cm.policy_id, cm.insurer_id,
            cm.commission_type, cm.premium_amount, cm.commission_rate,
            cm.expected_amount, cm.commission_tax_rate, cm.commission_tax_amount,
            cm.net_expected_amount, cm.received_amount, cm.status,
            cm.expected_date, cm.received_date, cm.reference_number,
            cm.discrepancy_notes, cm.marked_by, cm.marked_at,
            cm.created_at, cm.updated_at,
            p.policy_number, p.product_type, p.coverage_start, p.coverage_end,
            c.display_name AS customer_name,
            i.name         AS insurer_name,
            i.short_name   AS insurer_short_name
        FROM "  . APP_SCHEMA . ".commissions cm
        LEFT JOIN " . APP_SCHEMA . ".policies  p ON p.policy_id   = cm.policy_id
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = cm.insurer_id
        WHERE $where
        ORDER BY cm.created_at DESC
        LIMIT $limit OFFSET $offset"
    );

    $count = mysqli_query($conn,
        "SELECT COUNT(*) AS total
         FROM " . APP_SCHEMA . ".commissions cm
         LEFT JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cm.policy_id
         WHERE $where"
    );

    $total = $count ? (int)mysqli_fetch_assoc($count)['total'] : 0;

    jsonResponse(200, 'Commissions found', [
        'data'       => $rows ? mysqli_fetch_all($rows, MYSQLI_ASSOC) : [],
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
        ],
    ]);
}

// ─── CM-002: Summary stats ────────────────────────────────────────────────────
function getCommissionSummary($conn, $company_id, $params) {
    $where = "cm.company_id = '" . mysqli_real_escape_string($conn, $company_id) . "'";

    if (!empty($params['insurer_id'])) {
        $where .= " AND cm.insurer_id = '" . mysqli_real_escape_string($conn, $params['insurer_id']) . "'";
    }
    if (!empty($params['month']) && preg_match('/^\d{4}-\d{2}$/', $params['month'])) {
        $where .= " AND DATE_FORMAT(p.coverage_start, '%Y-%m') = '" . $params['month'] . "'";
    }
    if (!empty($params['product_type'])) {
        $where .= " AND p.product_type = '" . mysqli_real_escape_string($conn, $params['product_type']) . "'";
    }

    $result = mysqli_query($conn,
        "SELECT
            COUNT(*)                                                          AS total_count,
            COALESCE(SUM(cm.expected_amount), 0)                              AS total_expected,
            SUM(CASE WHEN cm.status = 'pending'     THEN 1    ELSE 0 END)    AS pending_count,
            COALESCE(SUM(CASE WHEN cm.status = 'pending'     THEN cm.expected_amount  ELSE 0 END), 0) AS pending_amount,
            SUM(CASE WHEN cm.status = 'received'    THEN 1    ELSE 0 END)    AS received_count,
            COALESCE(SUM(CASE WHEN cm.status = 'received'    THEN cm.received_amount  ELSE 0 END), 0) AS received_amount,
            SUM(CASE WHEN cm.status = 'discrepancy' THEN 1    ELSE 0 END)    AS discrepancy_count,
            COALESCE(SUM(CASE WHEN cm.status = 'discrepancy' THEN cm.expected_amount  ELSE 0 END), 0) AS discrepancy_expected,
            COALESCE(SUM(CASE WHEN cm.status = 'discrepancy' THEN cm.received_amount  ELSE 0 END), 0) AS discrepancy_received
        FROM " . APP_SCHEMA . ".commissions cm
        LEFT JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cm.policy_id
        WHERE $where"
    );

    if (!$result) {
        jsonResponse(500, 'Failed to fetch commission summary', ['error' => mysqli_error($conn)]);
        return;
    }

    $r = mysqli_fetch_assoc($result);

    jsonResponse(200, 'Commission summary retrieved', [
        'total_count'    => (int)$r['total_count'],
        'total_expected' => (int)$r['total_expected'],
        'pending' => [
            'count'  => (int)$r['pending_count'],
            'amount' => (int)$r['pending_amount'],
        ],
        'received' => [
            'count'  => (int)$r['received_count'],
            'amount' => (int)$r['received_amount'],
        ],
        'discrepancy' => [
            'count'    => (int)$r['discrepancy_count'],
            'expected' => (int)$r['discrepancy_expected'],
            'received' => (int)$r['discrepancy_received'],
        ],
    ]);
}

// ─── CM-003: Get single commission ───────────────────────────────────────────
function getCommissionDetail($conn, $commission_id, $company_id) {
    $cid = mysqli_real_escape_string($conn, $commission_id);
    $cmp = mysqli_real_escape_string($conn, $company_id);

    $result = mysqli_query($conn,
        "SELECT
            cm.commission_id, cm.policy_id, cm.insurer_id,
            cm.commission_type, cm.premium_amount, cm.commission_rate,
            cm.expected_amount, cm.commission_tax_rate, cm.commission_tax_amount,
            cm.net_expected_amount, cm.received_amount, cm.status,
            cm.expected_date, cm.received_date, cm.reference_number,
            cm.discrepancy_notes, cm.marked_by, cm.marked_at,
            cm.created_at, cm.updated_at,
            p.policy_number, p.product_type, p.coverage_start, p.coverage_end,
            c.display_name AS customer_name,
            i.name         AS insurer_name,
            i.short_name   AS insurer_short_name
        FROM " . APP_SCHEMA . ".commissions cm
        LEFT JOIN " . APP_SCHEMA . ".policies  p ON p.policy_id   = cm.policy_id
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = cm.insurer_id
        WHERE cm.commission_id = '$cid' AND cm.company_id = '$cmp'
        LIMIT 1"
    );

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Commission not found');
        return;
    }

    jsonResponse(200, 'Commission found', mysqli_fetch_assoc($result));
}

// ─── CM-004: Mark commission as received ─────────────────────────────────────
function markCommissionReceived($conn, $commission_id, $company_id, $input, $username) {
    $cid = mysqli_real_escape_string($conn, $commission_id);
    $cmp = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT commission_id, policy_id, expected_amount, status
         FROM " . APP_SCHEMA . ".commissions
         WHERE commission_id = '$cid' AND company_id = '$cmp'
         LIMIT 1"
    );

    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Commission not found');
        return;
    }

    $current = mysqli_fetch_assoc($check);

    if ($current['status'] === 'cancelled') {
        jsonResponse(400, 'Cannot mark a cancelled commission as received');
        return;
    }

    $matched = mysqli_query($conn,
        "SELECT 1 FROM " . APP_SCHEMA . ".commission_statement_items WHERE commission_id = '$cid' LIMIT 1");
    if ($matched && mysqli_num_rows($matched) > 0) {
        jsonResponse(400, 'This commission is matched to an insurer statement; unmatch it from the statement first');
        return;
    }

    if (!isset($input['received_amount']) || $input['received_amount'] === '') {
        jsonResponse(400, 'received_amount is required');
        return;
    }

    $received_amount = (int)$input['received_amount'];
    if ($received_amount < 0) {
        jsonResponse(400, 'received_amount must be a non-negative integer');
        return;
    }

    $received_date = !empty($input['received_date'])
        ? "'" . mysqli_real_escape_string($conn, trim($input['received_date'])) . "'"
        : "'" . date('Y-m-d') . "'";

    $reference_number = !empty($input['reference_number'])
        ? "'" . mysqli_real_escape_string($conn, trim($input['reference_number'])) . "'"
        : 'NULL';

    $discrepancy_notes = !empty($input['discrepancy_notes'])
        ? "'" . mysqli_real_escape_string($conn, trim($input['discrepancy_notes'])) . "'"
        : 'NULL';

    $expected_amount = (int)$current['expected_amount'];
    $new_status      = abs($received_amount - $expected_amount) <= 1 ? 'received' : 'discrepancy';
    $now             = date('Y-m-d H:i:s');
    $uname           = mysqli_real_escape_string($conn, $username);

    $ok = mysqli_query($conn,
        "UPDATE " . APP_SCHEMA . ".commissions
         SET received_amount   = $received_amount,
             received_date     = $received_date,
             reference_number  = $reference_number,
             discrepancy_notes = $discrepancy_notes,
             status            = '$new_status',
             marked_by         = '$uname',
             marked_at         = '$now',
             updated_at        = '$now'
         WHERE commission_id = '$cid' AND company_id = '$cmp'"
    );

    if (!$ok) {
        jsonResponse(500, 'Failed to update commission', ['error' => mysqli_error($conn)]);
        return;
    }

    $policy_id  = $current['policy_id'];
    $event_type = $new_status === 'received' ? 'commission_marked_received' : 'commission_discrepancy';
    $diff       = $received_amount - $expected_amount;

    if ($new_status === 'received') {
        $desc = "Komisi diterima: Rp " . number_format($received_amount, 0, ',', '.');
    } else {
        $sign = $diff > 0 ? '+' : '';
        $desc = "Selisih komisi: ekspektasi Rp " . number_format($expected_amount, 0, ',', '.') .
                ", diterima Rp " . number_format($received_amount, 0, ',', '.') .
                " (selisih {$sign}" . number_format($diff, 0, ',', '.') . ")";
    }

    insertPolicyLog($conn, $policy_id, $company_id, $event_type, $desc, $username,
        null, null, 'commissions', $commission_id,
        ['expected_amount' => $expected_amount, 'received_amount' => $received_amount, 'status' => $new_status]);

    jsonResponse(200, 'Commission marked as ' . $new_status, [
        'commission_id'   => $commission_id,
        'status'          => $new_status,
        'expected_amount' => $expected_amount,
        'received_amount' => $received_amount,
        'difference'      => $diff,
    ]);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

// Wrap the entire dispatch in try/catch so an uncaught exception or DB
// connection failure returns a JSON 500 instead of an HTML error page,
// matching every other module's convention.
try {
    $authUser   = requireAuth();
    $method     = $_SERVER['REQUEST_METHOD'];
    $company_id = $authUser['company_id'] ?? null;
    $username   = $authUser['user_id']    ?? $authUser['sub'] ?? null;

    if (!$company_id) {
        jsonResponse(400, 'company_id is required');
        exit;
    }

    // URL shape: /api/v1/commissions[/{commission_id}[/mark-received]]
    // $action     = $parts[3]  →  '' | 'summary' | commission_id
    // $sub_action = $parts[4]  →  '' | 'mark-received'
    $sub_action    = $parts[4] ?? '';
    $commission_id = (!empty($action) && !in_array($action, ['summary', 'statements'], true)) ? $action : null;

    if ($action === 'statements') {
        requirePlanFeature($conn, $company_id, 'commission_statements');
        // /commissions/statements[/{id}[/match | /items/{commission_id}]]
        $statement_id = $parts[4] ?? '';
        $stmt_sub     = $parts[5] ?? '';
        if ($statement_id === '') {
            if ($method === 'GET')       listStatements($conn, $company_id, $_GET);
            elseif ($method === 'POST')  createStatement($conn, $company_id, input(), $username);
            else                         jsonResponse(405, 'Method Not Allowed');
        } elseif ($stmt_sub === 'match') {
            if ($method !== 'POST') { jsonResponse(405, 'Method Not Allowed'); }
            matchStatement($conn, $statement_id, $company_id, input(), $username);
        } elseif ($stmt_sub === 'items' && !empty($parts[6])) {
            if ($method !== 'DELETE') { jsonResponse(405, 'Method Not Allowed'); }
            unmatchStatementItem($conn, $statement_id, $parts[6], $company_id);
        } elseif ($stmt_sub === '') {
            if ($method === 'GET')         getStatementDetail($conn, $statement_id, $company_id);
            elseif ($method === 'DELETE')  deleteStatement($conn, $statement_id, $company_id);
            else                           jsonResponse(405, 'Method Not Allowed');
        } else {
            jsonResponse(404, 'Route not found');
        }

    } elseif ($action === 'summary') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getCommissionSummary($conn, $company_id, $_GET);

    } elseif ($commission_id && $sub_action === 'mark-received') {
        if ($method !== 'PATCH') { jsonResponse(405, 'Method Not Allowed'); }
        $input = input();
        markCommissionReceived($conn, $commission_id, $company_id, $input, $username);

    } elseif ($commission_id) {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getCommissionDetail($conn, $commission_id, $company_id);

    } else {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        getAllCommissions($conn, $company_id, $_GET);
    }
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log('commissions dispatch exception: ' . $e->getMessage());
    Response::internalError();
}
