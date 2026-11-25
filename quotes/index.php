<?php

require_once __DIR__ . '/../helpers/db.php';

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
    $quote['items'] = quoteItemsFor($conn, $quote_id);
    $quote['options'] = quoteOptionsFor($conn, $company_id, $quote_id);
    return $quote;
}

// ── [v2.1.0] Itemised quotes, placement tracking, structured terms, co-insurance panels ──

const QUOTE_PLACEMENT_STATUSES = ['draft', 'submitted', 'quoted', 'declined', 'referred', 'awaiting_ri'];
const QUOTE_MAX_ITEMS = 60;

/** Items of an itemised quote: [{coverage_type, label, sum_insured, count_in_tsi}]. Null when not sent. */
function quoteParseItems(mixed $items): ?array {
    if ($items === null) return null;
    if (!is_array($items) || count($items) > QUOTE_MAX_ITEMS) throw ApiException::from('ERR_V21_012', ['max' => QUOTE_MAX_ITEMS]);
    $out = [];
    foreach (array_values($items) as $i => $it) {
        $it = is_array($it) ? $it : [];
        $type = inText($it, 'coverage_type', 30, true);
        $out[] = [
            'coverage_type' => $type,
            'label' => inText($it, 'label', 150),
            'sum_insured' => inMoney($it, 'sum_insured', true),
            'count_in_tsi' => inBool($it, 'count_in_tsi') ?? 1,
            'sort_order' => $i,
        ];
    }
    return $out;
}

/** Inserts items; returns their new ids by position. */
function quoteInsertItems($conn, string $company_id, string $quote_id, array $items): array {
    $ids = [];
    foreach ($items as $it) {
        $id = dbNewId('qit');
        dbInsert($conn, 'quote_items', $it + ['item_id' => $id, 'quote_id' => $quote_id, 'company_id' => $company_id]);
        $ids[] = $id;
    }
    return $ids;
}

/** Structured terms offered by an option (same shape as policy terms; coverage_index refers to the quote items). */
function quoteParseOptionTerms(mixed $terms, int $item_count): ?string {
    if ($terms === null || $terms === []) return null;
    require_once __DIR__ . '/../helpers/policy_terms.php';
    $rows = termsParseList($terms, $item_count);
    return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

/** Validates the option list. Premium is rate-based unless a premium is typed in. */
function quoteParseOptions($conn, string $company_id, mixed $options, int $sum_insured, ?array $items = null): array {
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
        // [v2.1.0] Itemised quotes: one rate per item, premium = Σ item SI × rate.
        $item_rates = [];
        if ($items) {
            $given = $o['item_rates'] ?? null;
            if (!is_array($given) || count($given) !== count($items)) {
                if (($o['placement_status'] ?? 'quoted') === 'quoted' && !isset($o['premium_amount'])) {
                    throw ApiException::from('ERR_V21_002', ['field' => "options[$i].item_rates"]);
                }
                $given = [];
            }
            foreach (array_values($given) as $k => $r) {
                $rv = is_array($r) ? ($r['rate_permille'] ?? null) : $r;
                if (!is_numeric($rv) || (float)$rv < 0 || (float)$rv > 1000) throw ApiException::from('ERR_SALES_003', ['field' => "options[$i].item_rates[$k]"]);
                $item_rates[] = ['index' => $k, 'rate_permille' => round((float)$rv, 4), 'premium_amount' => (int)round($items[$k]['sum_insured'] * (float)$rv / 1000)];
            }
        }
        $placement = inEnum($o, 'placement_status', QUOTE_PLACEMENT_STATUSES) ?? 'quoted';
        $premium_given = isset($o['premium_amount']) && $o['premium_amount'] !== '' && $o['premium_amount'] !== null;
        if ($premium_given) {
            $premium = salesMoney($o['premium_amount'], "options[$i].premium_amount");
        } elseif ($item_rates) {
            $premium = array_sum(array_column($item_rates, 'premium_amount'));
        } elseif ($rate !== null) {
            $premium = (int)round($sum_insured * (float)$rate / 1000);
        } elseif ($placement !== 'quoted') {
            $premium = 0; // still with the insurer, or declined: no price yet
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
            'placement_status' => $placement,
            'submitted_date'  => inDate($o, 'submitted_date'),
            'responded_date'  => inDate($o, 'responded_date'),
            'decline_reason'  => inText($o, 'decline_reason', 300),
            'info_supplied'   => inText($o, 'info_supplied', 500),
            'share_percent'   => inNumber($o, 'share_percent', 0.01, 100),
            'is_leader'       => inBool($o, 'is_leader') ?? 0,
            'terms_json'      => quoteParseOptionTerms($o['terms'] ?? null, $items ? count($items) : 0),
            'item_rates'      => $item_rates,
        ];
    }
    return $rows;
}

function quoteInsertOptions($conn, string $company_id, string $quote_id, array $rows, array $item_ids = []): bool {
    foreach ($rows as $r) {
        $id = 'qop_' . bin2hex(random_bytes(10));
        $item_rates = $r['item_rates'] ?? [];
        unset($r['item_rates']);
        dbInsert($conn, 'quote_options', $r + ['option_id' => $id, 'quote_id' => $quote_id, 'company_id' => $company_id]);
        foreach ($item_rates as $ir) {
            if (!isset($item_ids[$ir['index']])) continue;
            dbInsert($conn, 'quote_option_items', ['option_id' => $id, 'item_id' => $item_ids[$ir['index']],
                'rate_permille' => $ir['rate_permille'], 'premium_amount' => $ir['premium_amount']]);
        }
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
    $items   = quoteParseItems($input['items'] ?? null);
    if ($items) $row['sum_insured'] = array_sum(array_map(fn($i) => $i['count_in_tsi'] ? $i['sum_insured'] : 0, $items));
    $options = quoteParseOptions($conn, $company_id, $input['options'] ?? null, $row['sum_insured'], $items);
    $currency = inCurrency($input);
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
            $item_ids = $items ? quoteInsertItems($conn, $company_id, $quote_id, $items) : [];
            if (!quoteInsertOptions($conn, $company_id, $quote_id, $options, $item_ids)) throw new RuntimeException(mysqli_error($conn));
            if ($currency) dbUpdate($conn, 'quotes', $currency, ['quote_id' => $quote_id]);
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
    $items = array_key_exists('items', $input) ? (quoteParseItems($input['items']) ?? []) : null;
    if ($items) $row['sum_insured'] = array_sum(array_map(fn($i) => $i['count_in_tsi'] ? $i['sum_insured'] : 0, $items));
    // Option rates refer to items, so new items need the options again.
    if ($items !== null && !array_key_exists('options', $input)) throw ApiException::from('ERR_QUOTE_010');
    $currentItems = $items === null ? quoteItemsFor($conn, $quote_id) : null;
    $options = array_key_exists('options', $input)
        ? quoteParseOptions($conn, $company_id, $input['options'], $row['sum_insured'], $items ?? ($currentItems ?: null))
        : null;
    // A changed sum insured makes rate-based premiums stale; require the options again.
    if ($options === null && $row['sum_insured'] !== $current['sum_insured']) throw ApiException::from('ERR_QUOTE_010');
    $currency = inCurrency($input);

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
        // Item ids: new items are re-inserted; otherwise the existing ones keep their ids.
        if ($items !== null) {
            mysqli_query($conn, "DELETE oi FROM " . APP_SCHEMA . ".quote_option_items oi JOIN " . APP_SCHEMA . ".quote_options o ON o.option_id = oi.option_id WHERE o.quote_id = '$id'");
            mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".quote_items WHERE quote_id = '$id'");
            $item_ids = $items ? quoteInsertItems($conn, $company_id, $quote_id, $items) : [];
        } else {
            mysqli_query($conn, "DELETE oi FROM " . APP_SCHEMA . ".quote_option_items oi JOIN " . APP_SCHEMA . ".quote_options o ON o.option_id = oi.option_id WHERE o.quote_id = '$id'");
            $item_ids = array_column($currentItems ?? [], 'item_id');
        }
        $ok = mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".quote_options WHERE quote_id = '$id' AND company_id = '$company_id'")
            && quoteInsertOptions($conn, $company_id, $quote_id, $options, $item_ids);
    }
    if ($ok && $currency) dbUpdate($conn, 'quotes', $currency, ['quote_id' => $quote_id]);
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

    // [v2.1.0] Co-insurance: the accepted option leads; panel_option_ids are the followers. Shares must total 100%.
    $panel = null;
    if (!empty($input['panel_option_ids'])) {
        if (!is_array($input['panel_option_ids'])) throw ApiException::from('ERR_V21_001', ['field' => 'panel_option_ids']);
        $ids = array_values(array_unique(array_merge([(string)$input['option_id']], array_map('strval', $input['panel_option_ids']))));
        $opts = dbAll($conn, "SELECT option_id, share_percent FROM {app}.quote_options WHERE quote_id = ? AND company_id = ? AND option_id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ")", [$quote_id, $company_id, ...$ids]);
        if (count($opts) !== count($ids)) throw ApiException::from('ERR_V21_019');
        $total = round(array_sum(array_map(fn($o) => (float)$o['share_percent'], $opts)), 2);
        if (abs($total - 100) > 0.01) throw ApiException::from('ERR_V21_020', ['total' => $total]);
        $panel = json_encode($ids);
    }

    $usr = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".quotes SET status = 'accepted', accepted_option_id = '$oid', accepted_panel = " . salesSql($conn, $panel) . ", updated_by = '$usr'
            WHERE quote_id = '$qid' AND company_id = '$company_id'")) {
        throw ApiException::from('ERR_QUOTE_008');
    }
    $pid = mysqli_real_escape_string($conn, $current['prospect_id']);
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".prospects SET stage = 'negotiating', stage_changed_at = NOW(), updated_by = '$usr'
        WHERE prospect_id = '$pid' AND company_id = '$company_id' AND stage IN ('new', 'contacted', 'quoted')");
    jsonResponse(200, 'Quote accepted', quoteWithOptions($conn, $quote_id, $company_id));
}

/** [v2.1.0] PUT /quotes/{id}/options/{oid}/placement — move one insurer's submission along without re-sending the quote. */
function updateOptionPlacement($conn, string $company_id, string $quote_id, string $option_id, array $in, string $username): void {
    $current = findQuote($conn, $quote_id, $company_id);
    if (!in_array($current['status'], ['draft', 'sent'], true)) throw ApiException::from('ERR_QUOTE_009', ['status' => $current['status']]);
    if (!dbOne($conn, "SELECT 1 FROM {app}.quote_options WHERE option_id = ? AND quote_id = ? AND company_id = ?", [$option_id, $quote_id, $company_id])) {
        throw ApiException::from('ERR_QUOTE_013');
    }
    $set = inPresent($in, [
        'placement_status' => fn($i) => inEnum($i, 'placement_status', QUOTE_PLACEMENT_STATUSES, true),
        'submitted_date' => fn($i) => inDate($i, 'submitted_date'),
        'responded_date' => fn($i) => inDate($i, 'responded_date'),
        'decline_reason' => fn($i) => inText($i, 'decline_reason', 300),
        'info_supplied' => fn($i) => inText($i, 'info_supplied', 500),
        'share_percent' => fn($i) => inNumber($i, 'share_percent', 0.01, 100),
        'is_leader' => fn($i) => inBool($i, 'is_leader') ?? 0,
        'premium_amount' => fn($i) => inMoney($i, 'premium_amount'),
    ]);
    if (($set['placement_status'] ?? null) === 'submitted' && empty($set['submitted_date'])) $set['submitted_date'] = date('Y-m-d');
    if (in_array($set['placement_status'] ?? null, ['quoted', 'declined'], true) && empty($set['responded_date'])) $set['responded_date'] = date('Y-m-d');
    if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
    dbUpdate($conn, 'quote_options', $set, ['option_id' => $option_id]);
    jsonResponse(200, 'Placement updated', quoteWithOptions($conn, $quote_id, $company_id));
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

    if ($action !== '' && $sub_action === 'options' && ($parts[5] ?? '') !== '' && ($parts[6] ?? '') === 'placement') {
        if ($method !== 'PUT') Response::methodNotAllowed();
        updateOptionPlacement($conn, $company_id, $action, $parts[5], input(), $username);

    } elseif ($action !== '' && $sub_action === 'status') {
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
