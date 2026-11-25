<?php

// Quote queries shared by quotes/index.php and prospects/index.php.

function quoteSelectSql(): string {
    return "SELECT q.quote_id, q.quote_number, q.prospect_id, p.name AS prospect_name, p.whatsapp AS prospect_whatsapp,
                p.prospect_type, p.customer_id, q.product_type, q.object_description, q.sum_insured,
                q.coverage_start, q.coverage_end, q.valid_until, q.status, q.sent_at, q.accepted_option_id, q.policy_id,
                pol.policy_number, q.notes, q.created_by, q.currency, q.exchange_rate, q.accepted_panel,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS created_by_name,
                q.created_at, q.updated_at,
                (SELECT COUNT(*) FROM " . APP_SCHEMA . ".quote_options o WHERE o.quote_id = q.quote_id) AS option_count,
                (SELECT MIN(o.premium_amount) FROM " . APP_SCHEMA . ".quote_options o WHERE o.quote_id = q.quote_id) AS lowest_premium
            FROM " . APP_SCHEMA . ".quotes q
            JOIN " . APP_SCHEMA . ".prospects p ON p.prospect_id = q.prospect_id
            LEFT JOIN " . APP_SCHEMA . ".policies pol ON pol.policy_id = q.policy_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user u ON u.user_id COLLATE utf8mb4_unicode_ci = q.created_by";
}

function quoteNormalize(array $r): array {
    $r['sum_insured']    = (int)$r['sum_insured'];
    $r['option_count']   = (int)$r['option_count'];
    $r['lowest_premium'] = $r['lowest_premium'] === null ? null : (int)$r['lowest_premium'];
    $r['created_by_name'] = $r['created_by_name'] !== '' ? $r['created_by_name'] : null;
    // A sent quote past its validity date is shown as expired; it is not a stored status.
    $r['is_expired'] = $r['status'] === 'sent' && $r['valid_until'] !== null && $r['valid_until'] < date('Y-m-d');
    $r['exchange_rate'] = (float)($r['exchange_rate'] ?? 1);
    $r['accepted_panel'] = $r['accepted_panel'] ? (json_decode($r['accepted_panel'], true) ?: null) : null;
    return $r;
}

function quoteOptionsFor($conn, string $company_id, string $quote_id): array {
    $id  = mysqli_real_escape_string($conn, $quote_id);
    $res = mysqli_query($conn, "SELECT o.option_id, o.insurer_id, i.name AS insurer_name, i.short_name AS insurer_short_name,
            o.rate_permille, o.premium_amount, o.biaya_polis, o.materai_amount, o.deductible_note, o.terms_note, o.sort_order,
            o.placement_status, o.submitted_date, o.responded_date, o.decline_reason, o.info_supplied, o.share_percent, o.is_leader, o.terms_json
        FROM " . APP_SCHEMA . ".quote_options o
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = o.insurer_id
        WHERE o.quote_id = '$id' AND o.company_id = '$company_id'
        ORDER BY o.sort_order, o.option_id");
    if (!$res) throw new RuntimeException('quoteOptionsFor: ' . mysqli_error($conn));
    $options = mysqli_fetch_all($res, MYSQLI_ASSOC);
    // [v2.1.0] Per-item rates of itemised quotes.
    $rates = [];
    if ($options) {
        $in = "'" . implode("','", array_map(fn($o) => mysqli_real_escape_string($conn, $o['option_id']), $options)) . "'";
        $r2 = mysqli_query($conn, "SELECT oi.option_id, oi.item_id, oi.rate_permille, oi.premium_amount FROM " . APP_SCHEMA . ".quote_option_items oi
            JOIN " . APP_SCHEMA . ".quote_items qi ON qi.item_id = oi.item_id WHERE oi.option_id IN ($in) ORDER BY qi.sort_order");
        while ($r2 && ($x = mysqli_fetch_assoc($r2))) {
            $rates[$x['option_id']][] = ['item_id' => $x['item_id'], 'rate_permille' => (float)$x['rate_permille'], 'premium_amount' => (int)$x['premium_amount']];
        }
    }
    return array_map(function ($o) use ($rates) {
        $o['rate_permille']  = $o['rate_permille'] === null ? null : (float)$o['rate_permille'];
        foreach (['premium_amount', 'biaya_polis', 'materai_amount'] as $k) $o[$k] = (int)$o[$k];
        $o['total_amount'] = $o['premium_amount'] + $o['biaya_polis'] + $o['materai_amount'];
        $o['share_percent'] = $o['share_percent'] === null ? null : (float)$o['share_percent'];
        $o['is_leader'] = (bool)(int)$o['is_leader'];
        $o['terms'] = $o['terms_json'] ? (json_decode($o['terms_json'], true) ?: []) : [];
        unset($o['terms_json']);
        $o['item_rates'] = $rates[$o['option_id']] ?? [];
        return $o;
    }, $options);
}

function quoteListForProspect($conn, string $company_id, string $prospect_id): array {
    $id  = mysqli_real_escape_string($conn, $prospect_id);
    $res = mysqli_query($conn, quoteSelectSql() . " WHERE q.prospect_id = '$id' AND q.company_id = '$company_id' AND q.is_deleted = 0 ORDER BY q.created_at DESC");
    if (!$res) throw new RuntimeException('quoteListForProspect: ' . mysqli_error($conn));
    return array_map('quoteNormalize', mysqli_fetch_all($res, MYSQLI_ASSOC));
}

/** [v2.1.0] Items of an itemised quote, in order. */
function quoteItemsFor($conn, string $quote_id): array {
    $id = mysqli_real_escape_string($conn, $quote_id);
    $res = mysqli_query($conn, "SELECT item_id, coverage_type, label, sum_insured, count_in_tsi, sort_order FROM " . APP_SCHEMA . ".quote_items WHERE quote_id = '$id' ORDER BY sort_order");
    if (!$res) throw new RuntimeException('quoteItemsFor: ' . mysqli_error($conn));
    return array_map(function ($i) {
        $i['sum_insured'] = (int)$i['sum_insured'];
        $i['count_in_tsi'] = (bool)(int)$i['count_in_tsi'];
        unset($i['sort_order']);
        return $i;
    }, mysqli_fetch_all($res, MYSQLI_ASSOC));
}

