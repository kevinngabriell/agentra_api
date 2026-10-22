<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/sales.php';
require_once __DIR__ . '/../helpers/sub_agents.php';

// Leads and competitor-held policies tracked as future leads (migration_prospects_quotes.sql).
//
//   GET    /prospects                 list (filters: prospect_type, stage|open, product_interest, source, expiring_within=days, params, page, limit)
//   POST   /prospects                 add
//   GET    /prospects/summary         counts per stage, open pipeline value, competitor policies expiring, lost reasons
//   GET    /prospects/{id}            detail with its quotes
//   PUT    /prospects/{id}            edit
//   DELETE /prospects/{id}            soft delete (not once converted)
//   PUT    /prospects/{id}/stage      move to a stage; stage=lost needs lost_reason
//   POST   /prospects/{id}/convert    link a customer (customer_id) and mark won; optionally quote_id

function prospectSelectSql(): string {
    return "SELECT p.prospect_id, p.prospect_type, p.name, p.contact_name, p.phone, p.whatsapp, p.email, p.source,
                p.referred_by_agent_id, sa.full_name AS referred_by_agent_name, p.product_interest, p.expected_premium,
                p.stage, p.lost_reason, p.lost_note, p.stage_changed_at, p.follow_up_date,
                p.competitor_insurer, p.competitor_policy_number, p.competitor_expiry, p.competitor_premium,
                p.customer_id, c.display_name AS customer_name, p.notes, p.created_at, p.updated_at,
                (SELECT COUNT(*) FROM " . APP_SCHEMA . ".quotes q WHERE q.prospect_id = p.prospect_id AND q.is_deleted = 0) AS quote_count
            FROM " . APP_SCHEMA . ".prospects p
            LEFT JOIN " . APP_SCHEMA . ".sub_agents sa ON sa.sub_agent_id = p.referred_by_agent_id
            LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id";
}

function prospectNormalize(array $r): array {
    $r['expected_premium']   = (int)$r['expected_premium'];
    $r['competitor_premium'] = $r['competitor_premium'] === null ? null : (int)$r['competitor_premium'];
    $r['quote_count']        = (int)$r['quote_count'];
    return $r;
}

function findProspect($conn, string $prospect_id, string $company_id): array {
    $id  = mysqli_real_escape_string($conn, $prospect_id);
    $res = mysqli_query($conn, prospectSelectSql() . " WHERE p.prospect_id = '$id' AND p.company_id = '$company_id' AND p.is_deleted = 0 LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_SALES_001');
    return prospectNormalize(mysqli_fetch_assoc($res));
}

/** Validated columns for create/update. `$current` supplies fallbacks on edit. */
function prospectParse($conn, string $company_id, array $in, ?array $current = null): array {
    $pick = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($current[$k] ?? null);

    $type = $current['prospect_type'] ?? salesEnum($in['prospect_type'] ?? 'lead', PROSPECT_TYPES, 'prospect_type');
    $row = [
        'prospect_type' => $type,
        'name'          => salesRequiredText($pick('name'), 'name', 150),
        'contact_name'  => salesText($pick('contact_name'), 'contact_name', 150),
        'phone'         => salesText($pick('phone'), 'phone', 30),
        'whatsapp'      => salesText($pick('whatsapp'), 'whatsapp', 30),
        'email'         => salesText($pick('email'), 'email', 150),
        'source'        => salesEnum($pick('source') ?? 'direct', PROSPECT_SOURCES, 'source'),
        'product_interest' => salesText($pick('product_interest'), 'product_interest', 50),
        'expected_premium' => salesMoney($pick('expected_premium') ?? 0, 'expected_premium'),
        'follow_up_date'   => salesDate($pick('follow_up_date'), 'follow_up_date'),
        'competitor_insurer'       => salesText($pick('competitor_insurer'), 'competitor_insurer', 150),
        'competitor_policy_number' => salesText($pick('competitor_policy_number'), 'competitor_policy_number', 100),
        'competitor_expiry'        => salesDate($pick('competitor_expiry'), 'competitor_expiry'),
        'competitor_premium'       => ($v = $pick('competitor_premium')) === null || $v === '' ? null : salesMoney($v, 'competitor_premium'),
        'notes'         => salesText($pick('notes'), 'notes', 5000),
    ];
    if ($row['email'] !== null && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
        throw ApiException::from('ERR_SALES_003', ['field' => 'email']);
    }
    if ($type === 'competitor_policy' && $row['competitor_expiry'] === null) {
        throw ApiException::from('ERR_SALES_005', ['field' => 'competitor_expiry']);
    }

    $agent = requireActiveSubAgentId($conn, $company_id, $pick('referred_by_agent_id'));
    $row['referred_by_agent_id'] = $agent;
    if ($agent !== null) $row['source'] = 'referral';
    return $row;
}

function listProspects($conn, string $company_id, array $q): void {
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(50, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "p.company_id = '$company_id' AND p.is_deleted = 0";
    if (!empty($q['prospect_type'])) $where .= " AND p.prospect_type = '" . salesEnum($q['prospect_type'], PROSPECT_TYPES, 'prospect_type') . "'";

    $stage = (string)($q['stage'] ?? '');
    if ($stage === 'open') {
        $where .= " AND p.stage IN ('" . implode("','", PROSPECT_OPEN_STAGES) . "')";
    } elseif ($stage !== '') {
        $where .= " AND p.stage = '" . salesEnum($stage, PROSPECT_STAGES, 'stage') . "'";
    }
    foreach (['product_interest' => 'p.product_interest', 'source' => 'p.source'] as $param => $column) {
        if (!empty($q[$param])) $where .= " AND $column = '" . mysqli_real_escape_string($conn, (string)$q[$param]) . "'";
    }
    if (!empty($q['expiring_within']) && ctype_digit((string)$q['expiring_within'])) {
        $days = min(730, (int)$q['expiring_within']);
        $where .= " AND p.competitor_expiry IS NOT NULL AND p.competitor_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $days DAY)";
    }
    if (!empty($q['params'])) {
        $s = mysqli_real_escape_string($conn, trim((string)$q['params']));
        $where .= " AND (p.name LIKE '%$s%' OR p.contact_name LIKE '%$s%' OR p.phone LIKE '%$s%' OR p.whatsapp LIKE '%$s%'
                         OR p.competitor_insurer LIKE '%$s%' OR p.competitor_policy_number LIKE '%$s%')";
    }

    // Competitor policies sort by soonest expiry; leads by the next follow-up, then newest.
    $order = ($q['prospect_type'] ?? '') === 'competitor_policy'
        ? "p.competitor_expiry IS NULL, p.competitor_expiry ASC"
        : "p.follow_up_date IS NULL, p.follow_up_date ASC, p.created_at DESC";

    $result = mysqli_query($conn, prospectSelectSql() . " WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset");
    $count  = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".prospects p WHERE $where");
    if (!$result || !$count) throw new RuntimeException('listProspects: ' . mysqli_error($conn));

    jsonResponse(200, 'Prospects retrieved', [
        'data'       => array_map('prospectNormalize', mysqli_fetch_all($result, MYSQLI_ASSOC)),
        'pagination' => salesPaginate((int)mysqli_fetch_assoc($count)['total'], $page, $limit),
    ]);
}

function getProspectSummary($conn, string $company_id): void {
    $res = mysqli_query($conn, "SELECT stage, prospect_type, COUNT(*) AS total, COALESCE(SUM(expected_premium), 0) AS premium
        FROM " . APP_SCHEMA . ".prospects WHERE company_id = '$company_id' AND is_deleted = 0 GROUP BY stage, prospect_type");
    if (!$res) throw new RuntimeException('getProspectSummary: ' . mysqli_error($conn));

    $by_stage = array_fill_keys(PROSPECT_STAGES, 0);
    $open = $open_premium = $won = $lost = 0;
    while ($r = mysqli_fetch_assoc($res)) {
        $n = (int)$r['total'];
        $by_stage[$r['stage']] += $n;
        if (in_array($r['stage'], PROSPECT_OPEN_STAGES, true)) { $open += $n; $open_premium += (int)$r['premium']; }
        if ($r['stage'] === 'won')  $won  += $n;
        if ($r['stage'] === 'lost') $lost += $n;
    }

    $comp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
            COALESCE(SUM(competitor_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)), 0) AS d30,
            COALESCE(SUM(competitor_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)), 0) AS d90,
            COALESCE(SUM(competitor_expiry < CURDATE()), 0) AS past
        FROM " . APP_SCHEMA . ".prospects
        WHERE company_id = '$company_id' AND is_deleted = 0 AND prospect_type = 'competitor_policy'
          AND stage IN ('" . implode("','", PROSPECT_OPEN_STAGES) . "')"));

    $reasons = array_fill_keys(PROSPECT_LOST_REASONS, 0);
    $lr = mysqli_query($conn, "SELECT lost_reason, COUNT(*) AS total FROM " . APP_SCHEMA . ".prospects
        WHERE company_id = '$company_id' AND is_deleted = 0 AND stage = 'lost' AND lost_reason IS NOT NULL GROUP BY lost_reason");
    while ($lr && ($r = mysqli_fetch_assoc($lr))) $reasons[$r['lost_reason']] = (int)$r['total'];

    $decided = $won + $lost;
    jsonResponse(200, 'Prospect summary retrieved', [
        'by_stage'            => $by_stage,
        'open'                => $open,
        'open_expected_premium' => $open_premium,
        'win_rate'            => $decided > 0 ? round($won / $decided * 100, 1) : null,
        'competitor_expiring_30' => (int)$comp['d30'],
        'competitor_expiring_90' => (int)$comp['d90'],
        'competitor_past_expiry' => (int)$comp['past'],
        'lost_by_reason'      => $reasons,
    ]);
}

function getProspectDetail($conn, string $company_id, string $prospect_id): void {
    $prospect = findProspect($conn, $prospect_id, $company_id);
    require_once __DIR__ . '/../quotes/functions.php';
    $prospect['quotes'] = quoteListForProspect($conn, $company_id, $prospect['prospect_id']);
    jsonResponse(200, 'Prospect found', $prospect);
}

function prospectColumnsSql($conn, array $row): array {
    $out = [];
    foreach ($row as $col => $val) $out[$col] = salesSql($conn, $val);
    return $out;
}

function createProspect($conn, string $company_id, array $input, string $username): void {
    $row  = prospectParse($conn, $company_id, $input);
    $cols = prospectColumnsSql($conn, $row);
    $id   = 'prs_' . bin2hex(random_bytes(10));
    $usr  = mysqli_real_escape_string($conn, $username);

    $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".prospects
        (prospect_id, company_id, " . implode(', ', array_keys($cols)) . ", created_by)
        VALUES ('$id', '$company_id', " . implode(', ', $cols) . ", '$usr')");
    if (!$ok) {
        error_log('createProspect: ' . mysqli_error($conn));
        throw ApiException::from('ERR_SALES_008');
    }
    jsonResponse(201, 'Prospect created', findProspect($conn, $id, $company_id));
}

function updateProspect($conn, string $company_id, string $prospect_id, array $input, string $username): void {
    $current = findProspect($conn, $prospect_id, $company_id);
    $row  = prospectParse($conn, $company_id, $input, $current);
    $sets = [];
    foreach (prospectColumnsSql($conn, $row) as $col => $sql) $sets[] = "$col = $sql";
    $sets[] = "updated_by = '" . mysqli_real_escape_string($conn, $username) . "'";

    $id = mysqli_real_escape_string($conn, $prospect_id);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects SET " . implode(', ', $sets) . " WHERE prospect_id = '$id' AND company_id = '$company_id'")) {
        error_log('updateProspect: ' . mysqli_error($conn));
        throw ApiException::from('ERR_SALES_008');
    }
    jsonResponse(200, 'Prospect updated', findProspect($conn, $prospect_id, $company_id));
}

function deleteProspect($conn, string $company_id, string $prospect_id, string $username): void {
    $current = findProspect($conn, $prospect_id, $company_id);
    if ($current['customer_id'] !== null) throw ApiException::from('ERR_SALES_009');

    $id  = mysqli_real_escape_string($conn, $prospect_id);
    $usr = mysqli_real_escape_string($conn, $username);
    $ok  = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects
        SET is_deleted = 1, deleted_at = NOW(), deleted_by = '$usr', updated_by = '$usr'
        WHERE prospect_id = '$id' AND company_id = '$company_id'");
    // Their quotes go with them.
    $ok = $ok && mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".quotes SET is_deleted = 1, updated_by = '$usr'
        WHERE prospect_id = '$id' AND company_id = '$company_id'");
    if (!$ok) throw ApiException::from('ERR_SALES_008');
    jsonResponse(200, 'Prospect deleted');
}

function changeProspectStage($conn, string $company_id, string $prospect_id, array $input, string $username): void {
    $current = findProspect($conn, $prospect_id, $company_id);
    $stage   = salesEnum($input['stage'] ?? '', PROSPECT_STAGES, 'stage');
    if ($stage === 'won') throw ApiException::from('ERR_SALES_010'); // won only through convert
    if ($current['stage'] === 'won') throw ApiException::from('ERR_SALES_011');

    $reason = $note = null;
    if ($stage === 'lost') {
        $reason = salesEnum($input['lost_reason'] ?? '', PROSPECT_LOST_REASONS, 'lost_reason');
        $note   = salesText($input['lost_note'] ?? null, 'lost_note', 500);
    }

    $id  = mysqli_real_escape_string($conn, $prospect_id);
    $usr = mysqli_real_escape_string($conn, $username);
    $ok  = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects
        SET stage = '$stage', lost_reason = " . salesSql($conn, $reason) . ", lost_note = " . salesSql($conn, $note) . ",
            stage_changed_at = NOW(), updated_by = '$usr'
        WHERE prospect_id = '$id' AND company_id = '$company_id'");
    if (!$ok) throw ApiException::from('ERR_SALES_008');
    jsonResponse(200, 'Prospect stage updated', findProspect($conn, $prospect_id, $company_id));
}

// Links the prospect to a customer (existing or just created by the web app) and marks it won.
function convertProspect($conn, string $company_id, string $prospect_id, array $input, string $username): void {
    $prospect = findProspect($conn, $prospect_id, $company_id);
    if ($prospect['stage'] === 'won') throw ApiException::from('ERR_SALES_011');
    requireFields($input, ['customer_id']);

    $cid = mysqli_real_escape_string($conn, (string)$input['customer_id']);
    $res = mysqli_query($conn, "SELECT customer_id FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$cid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_SALES_012');

    $id  = mysqli_real_escape_string($conn, $prospect_id);
    $usr = mysqli_real_escape_string($conn, $username);
    $ok  = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects
        SET stage = 'won', customer_id = '$cid', lost_reason = NULL, lost_note = NULL, stage_changed_at = NOW(), updated_by = '$usr'
        WHERE prospect_id = '$id' AND company_id = '$company_id'");
    if (!$ok) throw ApiException::from('ERR_SALES_008');
    jsonResponse(200, 'Prospect converted', findProspect($conn, $prospect_id, $company_id));
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? '';
if (!$company_id) jsonResponse(400, 'company_id is required');
$sub_action = $parts[4] ?? '';

try {
    $conn       = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);

    if ($action === 'summary') {
        if ($method !== 'GET') Response::methodNotAllowed();
        getProspectSummary($conn, $company_id);

    } elseif ($action !== '' && $sub_action === 'stage') {
        if ($method !== 'PUT') Response::methodNotAllowed();
        changeProspectStage($conn, $company_id, $action, input(), $username);

    } elseif ($action !== '' && $sub_action === 'convert') {
        if ($method !== 'POST') Response::methodNotAllowed();
        convertProspect($conn, $company_id, $action, input(), $username);

    } elseif ($action !== '' && $sub_action === '') {
        switch ($method) {
            case 'GET':    getProspectDetail($conn, $company_id, $action); break;
            case 'PUT':    updateProspect($conn, $company_id, $action, input(), $username); break;
            case 'DELETE': deleteProspect($conn, $company_id, $action, $username); break;
            default:       Response::methodNotAllowed();
        }

    } elseif ($action === '') {
        switch ($method) {
            case 'GET':  listProspects($conn, $company_id, $_GET); break;
            case 'POST': createProspect($conn, $company_id, input(), $username); break;
            default:     Response::methodNotAllowed();
        }

    } else {
        jsonResponse(404, 'Route not found');
    }

} catch (ApiException $e) {
    throw $e;
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log($e->getMessage());
    Response::internalError();
}
