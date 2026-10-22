<?php

// Quote queries shared by quotes/index.php and prospects/index.php.

function quoteSelectSql(): string {
    return "SELECT q.quote_id, q.quote_number, q.prospect_id, p.name AS prospect_name, p.whatsapp AS prospect_whatsapp,
                p.prospect_type, p.customer_id, q.product_type, q.object_description, q.sum_insured,
                q.coverage_start, q.coverage_end, q.valid_until, q.status, q.sent_at, q.accepted_option_id, q.policy_id,
                pol.policy_number, q.notes, q.created_by,
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
    return $r;
}

function quoteOptionsFor($conn, string $company_id, string $quote_id): array {
    $id  = mysqli_real_escape_string($conn, $quote_id);
    $res = mysqli_query($conn, "SELECT o.option_id, o.insurer_id, i.name AS insurer_name, i.short_name AS insurer_short_name,
            o.rate_permille, o.premium_amount, o.biaya_polis, o.materai_amount, o.deductible_note, o.terms_note, o.sort_order
        FROM " . APP_SCHEMA . ".quote_options o
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = o.insurer_id
        WHERE o.quote_id = '$id' AND o.company_id = '$company_id'
        ORDER BY o.sort_order, o.option_id");
    if (!$res) throw new RuntimeException('quoteOptionsFor: ' . mysqli_error($conn));
    return array_map(function ($o) {
        $o['rate_permille']  = $o['rate_permille'] === null ? null : (float)$o['rate_permille'];
        foreach (['premium_amount', 'biaya_polis', 'materai_amount'] as $k) $o[$k] = (int)$o[$k];
        $o['total_amount'] = $o['premium_amount'] + $o['biaya_polis'] + $o['materai_amount'];
        return $o;
    }, mysqli_fetch_all($res, MYSQLI_ASSOC));
}

function quoteListForProspect($conn, string $company_id, string $prospect_id): array {
    $id  = mysqli_real_escape_string($conn, $prospect_id);
    $res = mysqli_query($conn, quoteSelectSql() . " WHERE q.prospect_id = '$id' AND q.company_id = '$company_id' AND q.is_deleted = 0 ORDER BY q.created_at DESC");
    if (!$res) throw new RuntimeException('quoteListForProspect: ' . mysqli_error($conn));
    return array_map('quoteNormalize', mysqli_fetch_all($res, MYSQLI_ASSOC));
}
