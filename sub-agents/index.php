<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/sub_agents.php';
require_once __DIR__ . '/../helpers/access.php';
require_once __DIR__ . '/../helpers/subscription.php';

// Sub-agent / referral network. A sub-agent is a contact record (no login): customers they
// referred carry customers.referred_by_agent_id and the policies they issued carry
// policies.issuing_agent_id, both holding sub_agents.sub_agent_id. The commission split
// (share of the gross commission passed on to the sub-agent) is a default per sub-agent
// plus optional per-insurer overrides; it is snapshotted on commissions when the policy
// is created. Tables in migration_sub_agents.sql.

// ── Validation helpers ────────────────────────────────────────────────────────

function subAgentText($conn, mixed $value, int $max): string {
    $s = trim((string)$value);
    return $s === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, mb_substr($s, 0, $max)) . "'";
}

// 0-100 with at most two decimals. Accepts numbers and numeric strings.
function subAgentRate(mixed $value, string $field): float {
    if (is_string($value)) $value = str_replace(',', '.', trim($value));
    if ($value === '' || $value === null || !is_numeric($value)) throw ApiException::from('ERR_SAGENT_004', ['field' => $field]);
    $n = round((float)$value, 2);
    if ($n < 0 || $n > 100) throw ApiException::from('ERR_SAGENT_004', ['field' => $field]);
    return $n;
}

function subAgentDate(mixed $value, string $field): ?string {
    $s = is_string($value) ? trim($value) : '';
    if ($s === '') return null;
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    $errors = DateTime::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $d->format('Y-m-d') !== $s) {
        throw ApiException::from('ERR_SAGENT_005', ['field' => $field]);
    }
    return $s;
}

function subAgentPaginate(int $total, int $page, int $limit): array {
    return ['total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => max(1, (int)ceil($total / $limit))];
}

function findSubAgent($conn, string $company_id, string $sub_agent_id): array {
    $row = findSubAgentRow($conn, $company_id, $sub_agent_id);
    if (!$row) throw ApiException::from('ERR_SAGENT_001');
    return $row;
}

function subAgentRates($conn, string $sub_agent_id): array {
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);
    $res = mysqli_query($conn, "SELECT r.insurer_id, i.name AS insurer_name, i.short_name AS insurer_short_name, r.split_rate
        FROM " . APP_SCHEMA . ".sub_agent_insurer_rates r
        JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = r.insurer_id
        WHERE r.sub_agent_id = '$sid' ORDER BY i.short_name, i.name");
    if (!$res) throw new RuntimeException('subAgentRates: ' . mysqli_error($conn));
    return array_map(fn($r) => ['insurer_id' => $r['insurer_id'], 'insurer_name' => $r['insurer_name'],
        'insurer_short_name' => $r['insurer_short_name'], 'split_rate' => (float)$r['split_rate']], mysqli_fetch_all($res, MYSQLI_ASSOC));
}

function normalizeSubAgent(array $row): array {
    $row['default_split_rate'] = (float)$row['default_split_rate'];
    $row['wa_digest_enabled']  = (bool)(int)$row['wa_digest_enabled'];
    $row['has_account']        = !empty($row['user_id']);
    unset($row['user_id']);
    foreach (['customers_count', 'policies_count', 'active_policies_count', 'total_premium', 'total_commission', 'sub_agent_commission'] as $k) {
        if (array_key_exists($k, $row)) $row[$k] = (int)$row[$k];
    }
    return $row;
}

// Per-agent roll-up used by the list and the profile header. In-force = not lapsed/cancelled and
// the coverage has not ended yet.
function subAgentAggregateSql(string $company_id): string {
    return "SELECT sa.*,
            (SELECT COUNT(*) FROM " . APP_SCHEMA . ".customers c
                WHERE c.referred_by_agent_id = sa.sub_agent_id AND c.company_id = sa.company_id) AS customers_count,
            (SELECT COUNT(*) FROM " . APP_SCHEMA . ".policies p
                WHERE p.issuing_agent_id = sa.sub_agent_id AND p.company_id = sa.company_id AND p.renewal_status <> 'cancelled') AS policies_count,
            (SELECT COUNT(*) FROM " . APP_SCHEMA . ".policies p
                WHERE p.issuing_agent_id = sa.sub_agent_id AND p.company_id = sa.company_id
                  AND p.renewal_status IN ('pending','renewed') AND p.coverage_end >= CURDATE()) AS active_policies_count,
            (SELECT COALESCE(SUM(p.premium_amount), 0) FROM " . APP_SCHEMA . ".policies p
                WHERE p.issuing_agent_id = sa.sub_agent_id AND p.company_id = sa.company_id AND p.renewal_status <> 'cancelled') AS total_premium,
            (SELECT COALESCE(SUM(cm.expected_amount), 0) FROM " . APP_SCHEMA . ".commissions cm
                WHERE cm.sub_agent_id = sa.sub_agent_id AND cm.company_id = sa.company_id AND cm.status <> 'cancelled') AS total_commission,
            (SELECT COALESCE(SUM(cm.sub_agent_amount), 0) FROM " . APP_SCHEMA . ".commissions cm
                WHERE cm.sub_agent_id = sa.sub_agent_id AND cm.company_id = sa.company_id AND cm.status <> 'cancelled') AS sub_agent_commission
        FROM " . APP_SCHEMA . ".sub_agents sa";
}

// Login linked to the sub-agent: email and whether it can sign in.
function subAgentAccount($conn, ?string $user_id): ?array {
    if (!$user_id) return null;
    $uid = mysqli_real_escape_string($conn, $user_id);
    $res = mysqli_query($conn, "SELECT username, account_status, last_login_at FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$uid' LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) return null;
    return ['email' => $row['username'], 'enabled' => $row['account_status'] === 'verified', 'last_login_at' => $row['last_login_at']];
}

// ── LIST ──────────────────────────────────────────────────────────────────────
function listSubAgents($conn, string $company_id, array $q): void {
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(100, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "sa.company_id = '$company_id'";
    $status = (string)($q['status'] ?? '');
    if ($status !== '') {
        if (!in_array($status, ['active', 'inactive'], true)) throw ApiException::from('ERR_SAGENT_006');
        $where .= " AND sa.status = '$status'";
    }
    if (!empty($q['params'])) {
        $s = mysqli_real_escape_string($conn, trim((string)$q['params']));
        $where .= " AND (sa.full_name LIKE '%$s%' OR sa.agent_code LIKE '%$s%' OR sa.phone_number LIKE '%$s%' OR sa.whatsapp_number LIKE '%$s%')";
    }

    $result = mysqli_query($conn, subAgentAggregateSql($company_id) . " WHERE $where ORDER BY sa.status ASC, sa.full_name ASC LIMIT $limit OFFSET $offset");
    $count  = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".sub_agents sa WHERE $where");
    if (!$result || !$count) throw new RuntimeException('listSubAgents: ' . mysqli_error($conn));

    jsonResponse(200, 'Sub-agents retrieved', [
        'data'       => array_map('normalizeSubAgent', mysqli_fetch_all($result, MYSQLI_ASSOC)),
        'pagination' => subAgentPaginate((int)mysqli_fetch_assoc($count)['total'], $page, $limit),
    ]);
}

// ── DETAIL / PROFILE ──────────────────────────────────────────────────────────
function getSubAgentDetail($conn, string $company_id, string $sub_agent_id, bool $self = false): void {
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);
    $res = mysqli_query($conn, subAgentAggregateSql($company_id) . " WHERE sa.sub_agent_id = '$sid' AND sa.company_id = '$company_id' LIMIT 1");
    if (!$res) throw new RuntimeException('getSubAgentDetail: ' . mysqli_error($conn));
    $row = mysqli_fetch_assoc($res);
    if (!$row) throw ApiException::from('ERR_SAGENT_001');

    $uid = $row['user_id'] ?? null;
    $row = normalizeSubAgent($row);
    $row['insurer_rates'] = subAgentRates($conn, $sub_agent_id);
    $row['account'] = subAgentAccount($conn, $uid);

    if ($self) {
        // The sub-agent sees what they earn, not the main agent's gross commission or private notes.
        unset($row['total_commission'], $row['notes'], $row['account']);
    }
    jsonResponse(200, 'Sub-agent retrieved', $row);
}

// ── WRITE ─────────────────────────────────────────────────────────────────────
// Replaces the per-insurer overrides with the given list ([] clears them).
function saveSubAgentRates($conn, string $company_id, string $sub_agent_id, mixed $rates): void {
    if (!is_array($rates) || !array_is_list($rates)) throw ApiException::from('ERR_SAGENT_009');

    $clean = [];
    foreach ($rates as $r) {
        if (!is_array($r) || empty($r['insurer_id']) || !is_string($r['insurer_id'])) throw ApiException::from('ERR_SAGENT_009');
        $iid = mysqli_real_escape_string($conn, trim($r['insurer_id']));
        $own = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$iid' AND company_id = '$company_id' LIMIT 1");
        if (!$own || mysqli_num_rows($own) === 0) throw ApiException::from('ERR_SAGENT_010');
        $clean[$iid] = subAgentRate($r['split_rate'] ?? null, 'split_rate');
    }

    $sid = mysqli_real_escape_string($conn, $sub_agent_id);
    if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".sub_agent_insurer_rates WHERE sub_agent_id = '$sid'")) {
        throw new RuntimeException('saveSubAgentRates delete: ' . mysqli_error($conn));
    }
    foreach ($clean as $iid => $rate) {
        $rateSql = number_format($rate, 2, '.', '');
        if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".sub_agent_insurer_rates (sub_agent_id, insurer_id, split_rate) VALUES ('$sid', '$iid', $rateSql)")) {
            throw new RuntimeException('saveSubAgentRates insert: ' . mysqli_error($conn));
        }
    }
}

function subAgentCodeTaken($conn, string $company_id, string $code, ?string $exceptId = null): bool {
    $c = mysqli_real_escape_string($conn, $code);
    $res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".sub_agents WHERE company_id = '$company_id' AND agent_code = '$c'"
        . ($exceptId !== null ? " AND sub_agent_id <> '" . mysqli_real_escape_string($conn, $exceptId) . "'" : '') . " LIMIT 1");
    return $res && mysqli_num_rows($res) > 0;
}

function createSubAgent($conn, string $company_id, array $input, string $username): void {
    $name = trim((string)($input['full_name'] ?? ''));
    $code = trim((string)($input['agent_code'] ?? ''));
    if ($name === '' || $code === '') throw ApiException::from('ERR_SAGENT_002');
    if (subAgentCodeTaken($conn, $company_id, $code)) throw ApiException::from('ERR_SAGENT_003');

    $status = (string)($input['status'] ?? 'active');
    if (!in_array($status, ['active', 'inactive'], true)) throw ApiException::from('ERR_SAGENT_006');
    $split  = number_format(subAgentRate($input['default_split_rate'] ?? 0, 'default_split_rate'), 2, '.', '');
    $waDigest = array_key_exists('wa_digest_enabled', $input) && !$input['wa_digest_enabled'] ? 0 : 1;
    $joined = subAgentDate($input['joined_date'] ?? null, 'joined_date');

    $id  = 'sag_' . bin2hex(random_bytes(10));
    $by  = mysqli_real_escape_string($conn, $username);
    $now = date('Y-m-d H:i:s');

    mysqli_begin_transaction($conn);
    try {
        $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".sub_agents
            (sub_agent_id, company_id, agent_code, full_name, phone_number, whatsapp_number, email, joined_date, status, default_split_rate, wa_digest_enabled, notes, created_by, created_at)
            VALUES ('$id', '$company_id', '" . mysqli_real_escape_string($conn, mb_substr($code, 0, 50)) . "',
                '" . mysqli_real_escape_string($conn, mb_substr($name, 0, 200)) . "',
                " . subAgentText($conn, $input['phone_number'] ?? '', 30) . ", " . subAgentText($conn, $input['whatsapp_number'] ?? '', 30) . ",
                " . subAgentText($conn, $input['email'] ?? '', 200) . ", " . ($joined ? "'$joined'" : 'NULL') . ",
                '$status', $split, $waDigest, " . subAgentText($conn, $input['notes'] ?? '', 5000) . ", '$by', '$now')");
        if (!$ok) throw new RuntimeException('createSubAgent: ' . mysqli_error($conn));
        if (array_key_exists('insurer_rates', $input)) saveSubAgentRates($conn, $company_id, $id, $input['insurer_rates']);
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    getSubAgentDetailCreated($conn, $company_id, $id);
}

function getSubAgentDetailCreated($conn, string $company_id, string $id): void {
    $res = mysqli_query($conn, subAgentAggregateSql($company_id) . " WHERE sa.sub_agent_id = '$id' AND sa.company_id = '$company_id' LIMIT 1");
    $row = normalizeSubAgent(mysqli_fetch_assoc($res));
    $row['insurer_rates'] = subAgentRates($conn, $id);
    $row['account'] = null;
    jsonResponse(201, 'Sub-agent created', $row);
}

function updateSubAgent($conn, string $company_id, string $sub_agent_id, array $input, string $username): void {
    $current = findSubAgent($conn, $company_id, $sub_agent_id);

    $updates = [];
    if (array_key_exists('full_name', $input)) {
        $name = trim((string)$input['full_name']);
        if ($name === '') throw ApiException::from('ERR_SAGENT_002');
        $updates[] = "full_name = '" . mysqli_real_escape_string($conn, mb_substr($name, 0, 200)) . "'";
    }
    if (array_key_exists('agent_code', $input)) {
        $code = trim((string)$input['agent_code']);
        if ($code === '') throw ApiException::from('ERR_SAGENT_002');
        if ($code !== $current['agent_code'] && subAgentCodeTaken($conn, $company_id, $code, $sub_agent_id)) throw ApiException::from('ERR_SAGENT_003');
        $updates[] = "agent_code = '" . mysqli_real_escape_string($conn, mb_substr($code, 0, 50)) . "'";
    }
    foreach (['phone_number' => 30, 'whatsapp_number' => 30, 'email' => 200, 'notes' => 5000] as $field => $max) {
        if (array_key_exists($field, $input)) $updates[] = "$field = " . subAgentText($conn, $input[$field], $max);
    }
    if (array_key_exists('joined_date', $input)) {
        $d = subAgentDate($input['joined_date'], 'joined_date');
        $updates[] = "joined_date = " . ($d ? "'$d'" : 'NULL');
    }
    if (array_key_exists('status', $input)) {
        if (!in_array($input['status'], ['active', 'inactive'], true)) throw ApiException::from('ERR_SAGENT_006');
        $updates[] = "status = '" . $input['status'] . "'";
    }
    if (array_key_exists('wa_digest_enabled', $input)) {
        $updates[] = "wa_digest_enabled = " . ($input['wa_digest_enabled'] ? 1 : 0);
    }
    if (array_key_exists('default_split_rate', $input)) {
        $updates[] = "default_split_rate = " . number_format(subAgentRate($input['default_split_rate'], 'default_split_rate'), 2, '.', '');
    }

    $hasRates = array_key_exists('insurer_rates', $input);
    if (!$updates && !$hasRates) throw ApiException::from('ERR_COMMON_007');

    $updates[] = "updated_by = '" . mysqli_real_escape_string($conn, $username) . "'";
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);

    mysqli_begin_transaction($conn);
    try {
        if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sub_agents SET " . implode(', ', $updates) . " WHERE sub_agent_id = '$sid' AND company_id = '$company_id'")) {
            throw new RuntimeException('updateSubAgent: ' . mysqli_error($conn));
        }
        if ($hasRates) saveSubAgentRates($conn, $company_id, $sub_agent_id, $input['insurer_rates']);
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }

    // Rates apply to commissions created from now on; ones already snapshotted keep their split.
    getSubAgentDetail($conn, $company_id, $sub_agent_id);
}

function deleteSubAgent($conn, string $company_id, string $sub_agent_id): void {
    findSubAgent($conn, $company_id, $sub_agent_id);
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);

    $used = mysqli_query($conn, "SELECT
        (SELECT COUNT(*) FROM " . APP_SCHEMA . ".customers WHERE referred_by_agent_id = '$sid' AND company_id = '$company_id')
      + (SELECT COUNT(*) FROM " . APP_SCHEMA . ".policies  WHERE issuing_agent_id = '$sid'     AND company_id = '$company_id') AS n");
    if (!$used) throw new RuntimeException('deleteSubAgent: ' . mysqli_error($conn));
    if ((int)mysqli_fetch_assoc($used)['n'] > 0) throw ApiException::from('ERR_SAGENT_007');

    if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".sub_agents WHERE sub_agent_id = '$sid' AND company_id = '$company_id'")) {
        throw new RuntimeException('deleteSubAgent: ' . mysqli_error($conn));
    }
    jsonResponse(200, 'Sub-agent deleted', ['sub_agent_id' => $sub_agent_id]);
}

// ── PROFILE TABS ──────────────────────────────────────────────────────────────
function listSubAgentCustomers($conn, string $company_id, string $sub_agent_id, array $q): void {
    findSubAgent($conn, $company_id, $sub_agent_id);
    $sid    = mysqli_real_escape_string($conn, $sub_agent_id);
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(100, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "c.referred_by_agent_id = '$sid' AND c.company_id = '$company_id'";
    $result = mysqli_query($conn, "SELECT c.customer_id, c.display_name, c.customer_type, c.status, c.created_at,
            COUNT(p.policy_id) AS policies_count, COALESCE(SUM(p.premium_amount), 0) AS total_premium
        FROM " . APP_SCHEMA . ".customers c
        LEFT JOIN " . APP_SCHEMA . ".policies p ON p.customer_id = c.customer_id AND p.renewal_status <> 'cancelled'
        WHERE $where GROUP BY c.customer_id ORDER BY c.display_name ASC LIMIT $limit OFFSET $offset");
    $count = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".customers c WHERE $where");
    if (!$result || !$count) throw new RuntimeException('listSubAgentCustomers: ' . mysqli_error($conn));

    $rows = array_map(function ($r) {
        $r['policies_count'] = (int)$r['policies_count'];
        $r['total_premium']  = (int)$r['total_premium'];
        return $r;
    }, mysqli_fetch_all($result, MYSQLI_ASSOC));

    jsonResponse(200, 'Sub-agent customers retrieved', [
        'data'       => $rows,
        'pagination' => subAgentPaginate((int)mysqli_fetch_assoc($count)['total'], $page, $limit),
    ]);
}

function listSubAgentPolicies($conn, string $company_id, string $sub_agent_id, array $q, bool $self = false): void {
    findSubAgent($conn, $company_id, $sub_agent_id);
    $sid    = mysqli_real_escape_string($conn, $sub_agent_id);
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(100, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "p.issuing_agent_id = '$sid' AND p.company_id = '$company_id'";
    if (!empty($q['insurer_id'])) $where .= " AND p.insurer_id = '" . mysqli_real_escape_string($conn, (string)$q['insurer_id']) . "'";

    $result = mysqli_query($conn, "SELECT p.policy_id, p.policy_number, p.product_type, p.renewal_status, p.payment_status,
            p.coverage_start, p.coverage_end, p.premium_amount, p.insurer_id, i.short_name AS insurer_short_name,
            p.customer_id, c.display_name AS customer_name,
            COALESCE(cm.expected_amount, p.commission_amount) AS commission_amount, COALESCE(cm.sub_agent_amount, 0) AS sub_agent_amount
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        LEFT JOIN " . APP_SCHEMA . ".commissions cm ON cm.policy_id = p.policy_id
        WHERE $where ORDER BY p.coverage_start DESC LIMIT $limit OFFSET $offset");
    $count = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".policies p WHERE $where");
    if (!$result || !$count) throw new RuntimeException('listSubAgentPolicies: ' . mysqli_error($conn));

    $rows = array_map(function ($r) use ($self) {
        foreach (['premium_amount', 'commission_amount', 'sub_agent_amount'] as $k) $r[$k] = (int)$r[$k];
        if ($self) unset($r['commission_amount']);
        return $r;
    }, mysqli_fetch_all($result, MYSQLI_ASSOC));

    jsonResponse(200, 'Sub-agent policies retrieved', [
        'data'       => $rows,
        'pagination' => subAgentPaginate((int)mysqli_fetch_assoc($count)['total'], $page, $limit),
    ]);
}

// Premium and commission by insurer.
function getSubAgentSummary($conn, string $company_id, string $sub_agent_id, bool $self = false): void {
    findSubAgent($conn, $company_id, $sub_agent_id);
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);

    $res = mysqli_query($conn, "SELECT p.insurer_id, i.name AS insurer_name, i.short_name AS insurer_short_name,
            COUNT(*) AS policies_count, COALESCE(SUM(p.premium_amount), 0) AS total_premium,
            COALESCE(SUM(COALESCE(cm.expected_amount, p.commission_amount)), 0) AS total_commission,
            COALESCE(SUM(cm.sub_agent_amount), 0) AS sub_agent_commission
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        LEFT JOIN " . APP_SCHEMA . ".commissions cm ON cm.policy_id = p.policy_id AND cm.status <> 'cancelled'
        WHERE p.issuing_agent_id = '$sid' AND p.company_id = '$company_id' AND p.renewal_status <> 'cancelled'
        GROUP BY p.insurer_id, i.name, i.short_name ORDER BY total_premium DESC");
    if (!$res) throw new RuntimeException('getSubAgentSummary: ' . mysqli_error($conn));

    $byInsurer = [];
    $totals = ['policies_count' => 0, 'total_premium' => 0, 'total_commission' => 0, 'sub_agent_commission' => 0];
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) {
        foreach (array_keys($totals) as $k) {
            $r[$k] = (int)$r[$k];
            $totals[$k] += $r[$k];
        }
        $r['retained_commission'] = $r['total_commission'] - $r['sub_agent_commission'];
        if ($self) unset($r['total_commission'], $r['retained_commission']);
        $byInsurer[] = $r;
    }
    $totals['retained_commission'] = $totals['total_commission'] - $totals['sub_agent_commission'];
    if ($self) unset($totals['total_commission'], $totals['retained_commission']);

    jsonResponse(200, 'Sub-agent summary retrieved', ['totals' => $totals, 'by_insurer' => $byInsurer]);
}

// Active policies, renewal rate and month-over-month premium growth.
function getSubAgentPerformance($conn, string $company_id, string $sub_agent_id): void {
    findSubAgent($conn, $company_id, $sub_agent_id);
    $sid = mysqli_real_escape_string($conn, $sub_agent_id);
    $base = "p.issuing_agent_id = '$sid' AND p.company_id = '$company_id'";

    $res = mysqli_query($conn, "SELECT
            SUM(p.renewal_status IN ('pending','renewed') AND p.coverage_end >= CURDATE()) AS active_policies,
            SUM(p.coverage_end < CURDATE() AND p.renewal_status = 'renewed') AS renewed,
            SUM(p.coverage_end < CURDATE() AND p.renewal_status = 'lapsed')  AS lapsed
        FROM " . APP_SCHEMA . ".policies p WHERE $base");
    if (!$res) throw new RuntimeException('getSubAgentPerformance: ' . mysqli_error($conn));
    $agg = mysqli_fetch_assoc($res);
    $renewed = (int)$agg['renewed'];
    $lapsed  = (int)$agg['lapsed'];

    // Last 12 calendar months, oldest first, zero-filled.
    $start = (new DateTimeImmutable('first day of this month'))->modify('-11 months');
    $rows = mysqli_query($conn, "SELECT DATE_FORMAT(p.coverage_start, '%Y-%m') AS month, COUNT(*) AS policies_count,
            COALESCE(SUM(p.premium_amount), 0) AS premium
        FROM " . APP_SCHEMA . ".policies p
        WHERE $base AND p.renewal_status <> 'cancelled' AND p.coverage_start >= '" . $start->format('Y-m-d') . "'
        GROUP BY month");
    if (!$rows) throw new RuntimeException('getSubAgentPerformance months: ' . mysqli_error($conn));
    $byMonth = [];
    foreach (mysqli_fetch_all($rows, MYSQLI_ASSOC) as $r) $byMonth[$r['month']] = $r;

    $monthly = [];
    for ($i = 0; $i < 12; $i++) {
        $m = $start->modify("+$i months")->format('Y-m');
        $monthly[] = ['month' => $m, 'policies_count' => (int)($byMonth[$m]['policies_count'] ?? 0), 'premium' => (int)($byMonth[$m]['premium'] ?? 0)];
    }

    $cur  = $monthly[11]['premium'];
    $prev = $monthly[10]['premium'];
    jsonResponse(200, 'Sub-agent performance retrieved', [
        'active_policies'   => (int)$agg['active_policies'],
        'renewed_count'     => $renewed,
        'lapsed_count'      => $lapsed,
        // renewed ÷ (renewed + lapsed) among policies whose term has ended; null until one has.
        'renewal_rate'      => ($renewed + $lapsed) > 0 ? round($renewed / ($renewed + $lapsed) * 100, 1) : null,
        'premium_this_month' => $cur,
        'premium_last_month' => $prev,
        // null when last month had no premium to compare against.
        'mom_growth'        => $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : null,
        'monthly'           => $monthly,
    ]);
}

// ── LOGIN ACCOUNT ─────────────────────────────────────────────────────────────
// Creates the sub-agent's login, or (when one exists) resets its password and re-enables it.
function saveSubAgentAccount($conn, string $company_id, string $sub_agent_id, array $input, string $username): void {
    $agent = findSubAgent($conn, $company_id, $sub_agent_id);
    if ($agent['status'] !== 'active') throw ApiException::from('ERR_SAGENT_011');

    $password = (string)($input['password'] ?? '');
    if ($password === '') throw ApiException::from('ERR_AUTH_005');
    if (strlen($password) < 8) throw ApiException::from('ERR_AUTH_007');

    $sid = mysqli_real_escape_string($conn, $sub_agent_id);
    $hash = mysqli_real_escape_string($conn, password_hash($password, PASSWORD_DEFAULT));
    $by = mysqli_real_escape_string($conn, $username);

    if (!empty($agent['user_id'])) {
        $uid = mysqli_real_escape_string($conn, $agent['user_id']);
        if (!mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET password = '$hash', account_status = 'verified', updated_by = '$by' WHERE user_id = '$uid'")) {
            throw new RuntimeException('saveSubAgentAccount update: ' . mysqli_error($conn));
        }
    } else {
        $email = trim((string)($input['email'] ?? $agent['email'] ?? ''));
        if ($email === '') throw ApiException::from('ERR_AUTH_005');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw ApiException::from('ERR_AUTH_006');
        $emailEsc = mysqli_real_escape_string($conn, $email);

        $dup = mysqli_query($conn, "SELECT 1 FROM " . CORE_SCHEMA . ".app_user WHERE app_id = '" . AGENTRA_APP_ID . "' AND username = '$emailEsc' LIMIT 1");
        if (!$dup) throw new RuntimeException('saveSubAgentAccount dup: ' . mysqli_error($conn));
        if (mysqli_num_rows($dup) > 0) throw ApiException::from('ERR_AUTH_010');

        // Same role as the agent creating the login: app_role is shared with the Movira portal.
        $role = mysqli_query($conn, "SELECT app_role_id FROM " . CORE_SCHEMA . ".app_user WHERE user_id = '$by' LIMIT 1");
        $roleId = $role ? (mysqli_fetch_assoc($role)['app_role_id'] ?? null) : null;
        if (!$roleId) throw new RuntimeException('saveSubAgentAccount: creating user has no role');

        $uid = generateUUID();
        $nameEsc = mysqli_real_escape_string($conn, $agent['full_name']);
        $phoneEsc = subAgentText($conn, $agent['phone_number'] ?? '', 50);

        mysqli_begin_transaction($conn);
        try {
            $ok = mysqli_query($conn, "INSERT INTO " . CORE_SCHEMA . ".app_user
                (user_id, username, first_name, email, password, phone_number, account_status, app_id, app_role_id, company_id)
                VALUES ('$uid', '$emailEsc', '$nameEsc', '$emailEsc', '$hash', $phoneEsc, 'verified', '" . AGENTRA_APP_ID . "', '" . mysqli_real_escape_string($conn, $roleId) . "', '$company_id')");
            if (!$ok) throw new RuntimeException('saveSubAgentAccount insert: ' . mysqli_error($conn));
            if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".sub_agents SET user_id = '$uid', updated_by = '$by' WHERE sub_agent_id = '$sid' AND company_id = '$company_id'")) {
                throw new RuntimeException('saveSubAgentAccount link: ' . mysqli_error($conn));
            }
            mysqli_commit($conn);
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            throw $e;
        }
    }

    getSubAgentDetail($conn, $company_id, $sub_agent_id);
}

// Disables the login. The link stays so the access gate keeps recognising (and blocking) the user.
function disableSubAgentAccount($conn, string $company_id, string $sub_agent_id, string $username): void {
    $agent = findSubAgent($conn, $company_id, $sub_agent_id);
    if (empty($agent['user_id'])) throw ApiException::from('ERR_SAGENT_012');

    $uid = mysqli_real_escape_string($conn, $agent['user_id']);
    $by  = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "UPDATE " . CORE_SCHEMA . ".app_user SET account_status = 'disabled', updated_by = '$by' WHERE user_id = '$uid'")) {
        throw new RuntimeException('disableSubAgentAccount: ' . mysqli_error($conn));
    }
    getSubAgentDetail($conn, $company_id, $sub_agent_id);
}

// ── ROUTER ────────────────────────────────────────────────────────────────────
$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? '';

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
}
$sub_action = $parts[4] ?? '';

try {
    $conn       = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);

    // A sub-agent login reaches only /sub-agents/me/* (read-only, enforced by helpers/access.php);
    // "me" is its own record, and the commission fields that belong to the main agent are left out.
    $self = false;
    $login = findSubAgentLogin($conn, $authUser);
    // Managing sub-agents (multi-agent, commission split, team analytics) is a Tim feature; a sub-agent's
    // own portal keeps working so a lapsed plan never locks the sub-agents out of their data.
    if ($login === null) requirePlanFeature($conn, $company_id, 'sub_agents');
    if ($login !== null) {
        if ($method !== 'GET' || $action !== 'me') Response::forbidden();
        $action = $login['sub_agent_id'];
        $self = true;
    }

    if ($action !== '' && $sub_action === 'account') {
        switch ($method) {
            case 'POST':   saveSubAgentAccount($conn, $company_id, $action, input(), $username); break;
            case 'DELETE': disableSubAgentAccount($conn, $company_id, $action, $username); break;
            default:       Response::methodNotAllowed();
        }

    } elseif ($action !== '' && $sub_action !== '') {
        if ($method !== 'GET') Response::methodNotAllowed();
        switch ($sub_action) {
            case 'customers':   listSubAgentCustomers($conn, $company_id, $action, $_GET); break;
            case 'policies':    listSubAgentPolicies($conn, $company_id, $action, $_GET, $self); break;
            case 'summary':     getSubAgentSummary($conn, $company_id, $action, $self); break;
            case 'performance': getSubAgentPerformance($conn, $company_id, $action); break;
            default:            jsonResponse(404, 'Route not found');
        }

    } elseif ($action !== '') {
        switch ($method) {
            case 'GET':    getSubAgentDetail($conn, $company_id, $action, $self); break;
            case 'PUT':    updateSubAgent($conn, $company_id, $action, input(), $username); break;
            case 'DELETE': deleteSubAgent($conn, $company_id, $action); break;
            default:       Response::methodNotAllowed();
        }

    } else {
        switch ($method) {
            case 'GET':  listSubAgents($conn, $company_id, $_GET); break;
            case 'POST': createSubAgent($conn, $company_id, input(), $username); break;
            default:     Response::methodNotAllowed();
        }
    }

} catch (ApiException $e) {
    throw $e;
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log($e->getMessage());
    Response::internalError();
}
