<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/sales.php';
require_once __DIR__ . '/functions.php';

// Quotes with one or more insurer options to compare (migration_prospects_quotes.sql).
//
//   GET    /quotes                 list (filters: status, prospect_id, product_type, params, page, limit)
//   POST   /quotes                 create ({prospect_id, product_type, sum_insured, ..., options: [...]})
//   GET    /quotes/{id}            detail with options
//   PUT    /quotes/{id}            edit; `options`, when sent, replaces the option list (draft and sent only)
//   DELETE /quotes/{id}            soft delete (not once accepted)
//   PUT    /quotes/{id}/status     draft | sent | declined (see QUOTE_TRANSITIONS)
//   POST   /quotes/{id}/accept     {option_id}: the customer picked this insurer's offer
//   POST   /quotes/{id}/policy     {policy_id}: link the policy issued from an accepted quote

function findQuote($conn, string $quote_id, string $company_id): array {
    $id  = mysqli_real_escape_string($conn, $quote_id);
    $res = mysqli_query($conn, quoteSelectSql() . " WHERE q.quote_id = '$id' AND q.company_id = '$company_id' AND q.is_deleted = 0 LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_QUOTE_001');
    return quoteNormalize(mysqli_fetch_assoc($res));
}

function quoteWithOptions($conn, string $quote_id, string $company_id): array {
    $quote = findQuote($conn, $quote_id, $company_id);
    $quote['options'] = quoteOptionsFor($conn, $company_id, $quote_id);
    return $quote;
}

/** Validates the option list. Premium is rate-based unless a premium is typed in. */
function quoteParseOptions($conn, string $company_id, mixed $options, int $sum_insured): array {
    if (!is_array($options) || count($options) < 1 || count($options) > QUOTE_MAX_OPTIONS) {
        throw ApiException::from('ERR_QUOTE_002', ['max' => QUOTE_MAX_OPTIONS]);
    }
    $rows = [];
    $seen = [];
    foreach (array_values($options) as $i => $o) {
        if (!is_array($o)) throw ApiException::from('ERR_QUOTE_002', ['max' => QUOTE_MAX_OPTIONS]);
        $insurer_id = salesRequiredText($o['insurer_id'] ?? null, "options[$i].insurer_id", 50);
        $iid = mysqli_real_escape_string($conn, $insurer_id);
        $res = mysqli_query($conn, "SELECT insurer_id FROM " . APP_SCHEMA . ".insurers WHERE insurer_id = '$iid' AND company_id = '$company_id' LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_QUOTE_003');
        if (isset($seen[$insurer_id])) throw ApiException::from('ERR_QUOTE_004');
        $seen[$insurer_id] = true;

        $rate = null;
        if (isset($o['rate_permille']) && $o['rate_permille'] !== '') {
            if (!is_numeric($o['rate_permille']) || (float)$o['rate_permille'] < 0 || (float)$o['rate_permille'] > 1000) {
                throw ApiException::from('ERR_SALES_003', ['field' => "options[$i].rate_permille"]);
            }
            $rate = number_format((float)$o['rate_permille'], 4, '.', '');
        }
        $premium_given = isset($o['premium_amount']) && $o['premium_amount'] !== '' && $o['premium_amount'] !== null;
        if ($premium_given) {
            $premium = salesMoney($o['premium_amount'], "options[$i].premium_amount");
        } elseif ($rate !== null) {
            $premium = (int)round($sum_insured * (float)$rate / 1000);
        } else {
            throw ApiException::from('ERR_QUOTE_005');
        }
        $rows[] = [
            'insurer_id'      => $insurer_id,
            'rate_permille'   => $rate,
            'premium_amount'  => $premium,
            'biaya_polis'     => salesMoney($o['biaya_polis'] ?? 0, "options[$i].biaya_polis"),
            'materai_amount'  => salesMoney($o['materai_amount'] ?? 0, "options[$i].materai_amount"),
            'deductible_note' => salesText($o['deductible_note'] ?? null, "options[$i].deductible_note", 300),
            'terms_note'      => salesText($o['terms_note'] ?? null, "options[$i].terms_note", 4000),
            'sort_order'      => $i,
        ];
    }
    return $rows;
}

function quoteInsertOptions($conn, string $company_id, string $quote_id, array $rows): bool {
    $qid = mysqli_real_escape_string($conn, $quote_id);
    foreach ($rows as $r) {
        $id = 'qop_' . bin2hex(random_bytes(10));
        $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".quote_options
            (option_id, quote_id, company_id, insurer_id, rate_permille, premium_amount, biaya_polis, materai_amount, deductible_note, terms_note, sort_order)
            VALUES ('$id', '$qid', '$company_id', " . salesSql($conn, $r['insurer_id']) . ", " . salesSql($conn, $r['rate_permille']) . ",
                {$r['premium_amount']}, {$r['biaya_polis']}, {$r['materai_amount']}, " . salesSql($conn, $r['deductible_note']) . ",
                " . salesSql($conn, $r['terms_note']) . ", {$r['sort_order']})");
        if (!$ok) return false;
    }
    return true;
}

function quoteParseFields(array $in, ?array $current = null): array {
    $pick = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($current[$k] ?? null);
    $row = [
        'product_type'       => salesRequiredText($pick('product_type'), 'product_type', 50),
        'object_description' => salesText($pick('object_description'), 'object_description', 500),
        'sum_insured'        => salesMoney($pick('sum_insured') ?? 0, 'sum_insured'),
        'coverage_start'     => salesDate($pick('coverage_start'), 'coverage_start'),
        'coverage_end'       => salesDate($pick('coverage_end'), 'coverage_end'),
        'valid_until'        => salesDate($pick('valid_until'), 'valid_until'),
        'notes'              => salesText($pick('notes'), 'notes', 5000),
    ];
    if ($row['coverage_start'] && $row['coverage_end'] && $row['coverage_end'] <= $row['coverage_start']) {
        throw ApiException::from('ERR_QUOTE_006');
    }
    return $row;
}

function listQuotes($conn, string $company_id, array $q): void {
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(50, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "q.company_id = '$company_id' AND q.is_deleted = 0";
    if (!empty($q['status'])) $where .= " AND q.status = '" . salesEnum($q['status'], QUOTE_STATUSES, 'status') . "'";
    foreach (['prospect_id' => 'q.prospect_id', 'product_type' => 'q.product_type'] as $param => $column) {
        if (!empty($q[$param])) $where .= " AND $column = '" . mysqli_real_escape_string($conn, (string)$q[$param]) . "'";
    }
    if (!empty($q['params'])) {
        $s = mysqli_real_escape_string($conn, trim((string)$q['params']));
        $where .= " AND (q.quote_number LIKE '%$s%' OR p.name LIKE '%$s%' OR q.object_description LIKE '%$s%')";
    }

    $result = mysqli_query($conn, quoteSelectSql() . " WHERE $where ORDER BY q.created_at DESC LIMIT $limit OFFSET $offset");
    $count  = mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".quotes q JOIN " . APP_SCHEMA . ".prospects p ON p.prospect_id = q.prospect_id WHERE $where");
    if (!$result || !$count) throw new RuntimeException('listQuotes: ' . mysqli_error($conn));

    jsonResponse(200, 'Quotes retrieved', [
        'data'       => array_map('quoteNormalize', mysqli_fetch_all($result, MYSQLI_ASSOC)),
        'pagination' => salesPaginate((int)mysqli_fetch_assoc($count)['total'], $page, $limit),
    ]);
}

function createQuote($conn, string $company_id, array $input, string $username): void {
    requireFields($input, ['prospect_id', 'product_type']);
    $pid = mysqli_real_escape_string($conn, (string)$input['prospect_id']);
    $res = mysqli_query($conn, "SELECT stage FROM " . APP_SCHEMA . ".prospects WHERE prospect_id = '$pid' AND company_id = '$company_id' AND is_deleted = 0 LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_SALES_001');
    $stage = mysqli_fetch_assoc($res)['stage'];
    if (in_array($stage, ['won', 'lost'], true)) throw ApiException::from('ERR_QUOTE_007');

    $row     = quoteParseFields($input);
    $options = quoteParseOptions($conn, $company_id, $input['options'] ?? null, $row['sum_insured']);
    $usr     = mysqli_real_escape_string($conn, $username);
    $quote_id = 'qte_' . bin2hex(random_bytes(10));

    // Two agents quoting at once can draw the same number; the unique key catches it.
    for ($attempt = 1; ; $attempt++) {
        $number = nextQuoteNumber($conn, $company_id);
        try {
            mysqli_begin_transaction($conn);
            mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".quotes
                (quote_id, company_id, quote_number, prospect_id, product_type, object_description, sum_insured, coverage_start, coverage_end, valid_until, notes, created_by)
                VALUES ('$quote_id', '$company_id', '$number', '$pid', " . salesSql($conn, $row['product_type']) . ", " . salesSql($conn, $row['object_description']) . ",
                    {$row['sum_insured']}, " . salesSql($conn, $row['coverage_start']) . ", " . salesSql($conn, $row['coverage_end']) . ",
                    " . salesSql($conn, $row['valid_until']) . ", " . salesSql($conn, $row['notes']) . ", '$usr')");
            if (!quoteInsertOptions($conn, $company_id, $quote_id, $options)) throw new RuntimeException(mysqli_error($conn));
            // First quote moves a new / contacted prospect to "quoted".
            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects SET stage = 'quoted', stage_changed_at = NOW(), updated_by = '$usr'
                WHERE prospect_id = '$pid' AND company_id = '$company_id' AND stage IN ('new', 'contacted')");
            mysqli_commit($conn);
            break;
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062 && $attempt < 3) continue;
            RequestLogger::setException($e);
            throw ApiException::from('ERR_QUOTE_008');
        }
    }
    jsonResponse(201, 'Quote created', quoteWithOptions($conn, $quote_id, $company_id));
}

function updateQuote($conn, string $company_id, string $quote_id, array $input, string $username): void {
    $current = findQuote($conn, $quote_id, $company_id);
    if (!in_array($current['status'], ['draft', 'sent'], true)) throw ApiException::from('ERR_QUOTE_009', ['status' => $current['status']]);

    $row = quoteParseFields($input, $current);
    $options = array_key_exists('options', $input)
        ? quoteParseOptions($conn, $company_id, $input['options'], $row['sum_insured'])
        : null;
    // A changed sum insured makes rate-based premiums stale; require the options again.
    if ($options === null && $row['sum_insured'] !== $current['sum_insured']) throw ApiException::from('ERR_QUOTE_010');

    $id  = mysqli_real_escape_string($conn, $quote_id);
    $usr = mysqli_real_escape_string($conn, $username);
    mysqli_begin_transaction($conn);
    $ok = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".quotes SET
            product_type = " . salesSql($conn, $row['product_type']) . ", object_description = " . salesSql($conn, $row['object_description']) . ",
            sum_insured = {$row['sum_insured']}, coverage_start = " . salesSql($conn, $row['coverage_start']) . ",
            coverage_end = " . salesSql($conn, $row['coverage_end']) . ", valid_until = " . salesSql($conn, $row['valid_until']) . ",
            notes = " . salesSql($conn, $row['notes']) . ", updated_by = '$usr'
        WHERE quote_id = '$id' AND company_id = '$company_id'");
    if ($ok && $options !== null) {
        $ok = mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".quote_options WHERE quote_id = '$id' AND company_id = '$company_id'")
            && quoteInsertOptions($conn, $company_id, $quote_id, $options);
    }
    if (!$ok) {
        error_log('updateQuote: ' . mysqli_error($conn));
        mysqli_rollback($conn);
        throw ApiException::from('ERR_QUOTE_008');
    }
    mysqli_commit($conn);
    jsonResponse(200, 'Quote updated', quoteWithOptions($conn, $quote_id, $company_id));
}

function deleteQuote($conn, string $company_id, string $quote_id, string $username): void {
    $current = findQuote($conn, $quote_id, $company_id);
    if ($current['status'] === 'accepted' || $current['policy_id'] !== null) throw ApiException::from('ERR_QUOTE_011');

    $id  = mysqli_real_escape_string($conn, $quote_id);
    $usr = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".quotes SET is_deleted = 1, updated_by = '$usr' WHERE quote_id = '$id' AND company_id = '$company_id'")) {
        throw ApiException::from('ERR_QUOTE_008');
    }
    jsonResponse(200, 'Quote deleted');
}

function changeQuoteStatus($conn, string $company_id, string $quote_id, array $input, string $username): void {
    $current = findQuote($conn, $quote_id, $company_id);
    $to = salesEnum($input['status'] ?? '', QUOTE_STATUSES, 'status');
    if (!in_array($to, QUOTE_TRANSITIONS[$current['status']], true)) {
        throw ApiException::from('ERR_QUOTE_012', ['from' => $current['status'], 'to' => $to]);
    }

    $id  = mysqli_real_escape_string($conn, $quote_id);
    $usr = mysqli_real_escape_string($conn, $username);
    $sent = $to === 'sent' ? "sent_at = NOW()," : ($to === 'draft' ? "sent_at = NULL," : '');
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".quotes SET status = '$to', $sent updated_by = '$usr' WHERE quote_id = '$id' AND company_id = '$company_id'")) {
        throw ApiException::from('ERR_QUOTE_008');
    }
    // Sending a quote to a prospect still at the start of the funnel moves it forward.
    if ($to === 'sent') {
        $pid = mysqli_real_escape_string($conn, $current['prospect_id']);
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects SET stage = 'negotiating', stage_changed_at = NOW(), updated_by = '$usr'
            WHERE prospect_id = '$pid' AND company_id = '$company_id' AND stage IN ('new', 'contacted', 'quoted')");
    }
    jsonResponse(200, 'Quote status updated', quoteWithOptions($conn, $quote_id, $company_id));
}

function acceptQuote($conn, string $company_id, string $quote_id, array $input, string $username): void {
    $current = findQuote($conn, $quote_id, $company_id);
    if (!in_array($current['status'], ['draft', 'sent'], true)) throw ApiException::from('ERR_QUOTE_012', ['from' => $current['status'], 'to' => 'accepted']);
    requireFields($input, ['option_id']);

    $oid = mysqli_real_escape_string($conn, (string)$input['option_id']);
    $qid = mysqli_real_escape_string($conn, $quote_id);
    $res = mysqli_query($conn, "SELECT option_id FROM " . APP_SCHEMA . ".quote_options WHERE option_id = '$oid' AND quote_id = '$qid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_QUOTE_013');

    $usr = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".quotes SET status = 'accepted', accepted_option_id = '$oid', updated_by = '$usr'
            WHERE quote_id = '$qid' AND company_id = '$company_id'")) {
        throw ApiException::from('ERR_QUOTE_008');
    }
    $pid = mysqli_real_escape_string($conn, $current['prospect_id']);
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects SET stage = 'negotiating', stage_changed_at = NOW(), updated_by = '$usr'
        WHERE prospect_id = '$pid' AND company_id = '$company_id' AND stage IN ('new', 'contacted', 'quoted')");
    jsonResponse(200, 'Quote accepted', quoteWithOptions($conn, $quote_id, $company_id));
}

function linkQuotePolicy($conn, string $company_id, string $quote_id, array $input, string $username): void {
    $current = findQuote($conn, $quote_id, $company_id);
    if ($current['status'] !== 'accepted') throw ApiException::from('ERR_QUOTE_014');
    if ($current['policy_id'] !== null) throw ApiException::from('ERR_QUOTE_015');
    requireFields($input, ['policy_id']);

    $pol = mysqli_real_escape_string($conn, (string)$input['policy_id']);
    $res = mysqli_query($conn, "SELECT customer_id FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pol' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_QUOTE_016');

    $id  = mysqli_real_escape_string($conn, $quote_id);
    $usr = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".quotes SET policy_id = '$pol', updated_by = '$usr' WHERE quote_id = '$id' AND company_id = '$company_id'")) {
        throw ApiException::from('ERR_QUOTE_008');
    }
    jsonResponse(200, 'Quote linked to policy', quoteWithOptions($conn, $quote_id, $company_id));
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

    if ($action !== '' && $sub_action === 'status') {
        if ($method !== 'PUT') Response::methodNotAllowed();
        changeQuoteStatus($conn, $company_id, $action, input(), $username);

    } elseif ($action !== '' && $sub_action === 'accept') {
        if ($method !== 'POST') Response::methodNotAllowed();
        acceptQuote($conn, $company_id, $action, input(), $username);

    } elseif ($action !== '' && $sub_action === 'policy') {
        if ($method !== 'POST') Response::methodNotAllowed();
        linkQuotePolicy($conn, $company_id, $action, input(), $username);

    } elseif ($action !== '' && $sub_action === '') {
        switch ($method) {
            case 'GET':    jsonResponse(200, 'Quote found', quoteWithOptions($conn, $action, $company_id)); break;
            case 'PUT':    updateQuote($conn, $company_id, $action, input(), $username); break;
            case 'DELETE': deleteQuote($conn, $company_id, $action, $username); break;
            default:       Response::methodNotAllowed();
        }

    } elseif ($action === '') {
        switch ($method) {
            case 'GET':  listQuotes($conn, $company_id, $_GET); break;
            case 'POST': createQuote($conn, $company_id, input(), $username); break;
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
