<?php
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/policy_log.php';
require_once __DIR__ . '/../helpers/renewal_message.php';
require_once __DIR__ . '/../helpers/renewal_workflow.php';
require_once __DIR__ . '/../helpers/risk_details.php';
require_once __DIR__ . '/../helpers/sales.php';
require_once __DIR__ . '/../helpers/messaging.php';
require_once __DIR__ . '/../notification/notification.php';

/**
 * Optional insurer / product type / sub-agent filters shared by the list, stats and calendar.
 * `sub_agent_id=main` selects policies issued by the main agent directly. Alias p = policies.
 */
function renewalFilterSql($conn, array $params): string {
    $sql = '';
    if (!empty($params['insurer_id'])) {
        $sql .= " AND p.insurer_id = '" . mysqli_real_escape_string($conn, $params['insurer_id']) . "'";
    }
    if (!empty($params['product_type'])) {
        $sql .= " AND p.product_type = '" . mysqli_real_escape_string($conn, $params['product_type']) . "'";
    }
    if (!empty($params['sub_agent_id'])) {
        $sql .= $params['sub_agent_id'] === 'main'
            ? " AND p.issuing_agent_id IS NULL"
            : " AND p.issuing_agent_id = '" . mysqli_real_escape_string($conn, $params['sub_agent_id']) . "'";
    }
    return $sql;
}

// --- RN-001: GET /api/v1/renewals?month=YYYY-MM ---
// List of policies expiring in the given month with renewal tracking info
function getRenewalList($conn, $company_id, $month, $renewal_status, $page, $limit, $workflow_stage = '', $filters = '') {
    $offset = ($page - 1) * $limit;

    $where = "p.company_id = '$company_id' AND DATE_FORMAT(p.coverage_end, '%Y-%m') = '$month'" . $filters;
    if ($renewal_status && in_array($renewal_status, ['pending', 'renewed', 'lapsed', 'cancelled'], true)) {
        $where .= " AND p.renewal_status = '$renewal_status'";
    }
    if ($workflow_stage !== '' && in_array($workflow_stage, RWF_STAGES, true)) {
        $where .= $workflow_stage === 'not_started'
            ? " AND NOT EXISTS (SELECT 1 FROM " . APP_SCHEMA . ".renewal_workflows w WHERE w.policy_id = p.policy_id AND w.stage <> 'not_started')"
            : " AND EXISTS (SELECT 1 FROM " . APP_SCHEMA . ".renewal_workflows w WHERE w.policy_id = p.policy_id AND w.stage = '$workflow_stage')";
    }

    $query = "SELECT
            p.policy_id, p.policy_number, p.product_type,
            p.coverage_end, p.renewal_status, p.payment_status,
            p.premium_amount, p.commission_amount,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            c.display_name   AS customer_name,
            c.personal_whatsapp AS customer_whatsapp,
            i.short_name     AS insurer_name,
            (SELECT fl.followup_status
             FROM " . APP_SCHEMA . ".follow_up_logs fl
             WHERE fl.policy_id = p.policy_id
             ORDER BY fl.followup_date DESC, fl.created_at DESC
             LIMIT 1) AS last_follow_up_status,
            (SELECT fl.followup_date
             FROM " . APP_SCHEMA . ".follow_up_logs fl
             WHERE fl.policy_id = p.policy_id
             ORDER BY fl.followup_date DESC, fl.created_at DESC
             LIMIT 1) AS last_follow_up_date,
            COALESCE((SELECT w.stage FROM " . APP_SCHEMA . ".renewal_workflows w WHERE w.policy_id = p.policy_id), 'not_started') AS workflow_stage,
            (SELECT w.lost_reason FROM " . APP_SCHEMA . ".renewal_workflows w WHERE w.policy_id = p.policy_id) AS lost_reason,
            (SELECT COUNT(*) FROM " . APP_SCHEMA . ".claims cl
             WHERE cl.policy_id = p.policy_id AND cl.is_deleted = 0) AS claim_count,
            (SELECT COALESCE(SUM(COALESCE(cl.settlement_amount, cl.approved_amount)), 0) FROM " . APP_SCHEMA . ".claims cl
             WHERE cl.policy_id = p.policy_id AND cl.is_deleted = 0 AND cl.status IN ('approved', 'paid')) AS claims_incurred
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE $where
        ORDER BY p.coverage_end ASC
        LIMIT $limit OFFSET $offset";

    $countQuery = "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".policies p WHERE $where";

    $result      = mysqli_query($conn, $query);
    $countResult = mysqli_query($conn, $countQuery);
    $total       = $countResult ? (int)mysqli_fetch_assoc($countResult)['total'] : 0;
    $data        = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    foreach ($data as &$row) {
        $row['claim_count']     = (int)$row['claim_count'];
        $row['claims_incurred'] = (int)$row['claims_incurred'];
    }
    unset($row);

    jsonResponse(200, 'Renewals found', [
        'month' => $month,
        'data'  => $data,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => $limit > 0 ? (int)ceil($total / $limit) : 0,
        ],
    ]);
}

// --- RN-002: GET /api/v1/renewals/stats?month=YYYY-MM ---
// Aggregated renewal stats for the StatusPerpanjangan component
function getRenewalStats($conn, $company_id, $month, $filters = '') {
    $r = mysqli_query($conn, "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN renewal_status = 'renewed'   THEN 1 ELSE 0 END) AS renewed,
            SUM(CASE WHEN renewal_status = 'pending'   THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN renewal_status = 'lapsed'    THEN 1 ELSE 0 END) AS lapsed,
            SUM(CASE WHEN renewal_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN renewal_status = 'renewed'   THEN premium_amount ELSE 0 END) AS achieved_omzet,
            SUM(premium_amount) AS total_omzet_potential
        FROM " . APP_SCHEMA . ".policies p
        WHERE company_id = '$company_id'
          AND DATE_FORMAT(coverage_end, '%Y-%m') = '$month'" . $filters);

    if (!$r) {
        jsonResponse(500, 'Failed to fetch renewal stats', ['error' => mysqli_error($conn)]);
        return;
    }

    $row   = mysqli_fetch_assoc($r);
    $total = (int)($row['total'] ?? 0);

    $renewed     = (int)($row['renewed']     ?? 0);
    $in_progress = (int)($row['in_progress'] ?? 0);
    $lapsed      = (int)($row['lapsed']      ?? 0);
    $cancelled   = (int)($row['cancelled']   ?? 0);

    $pct = fn($n) => $total > 0 ? round(($n / $total) * 100, 1) : 0;

    $lost = [];
    $lr = mysqli_query($conn, "SELECT w.lost_reason, COUNT(*) AS n
        FROM " . APP_SCHEMA . ".renewal_workflows w
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = w.policy_id
        WHERE p.company_id = '$company_id' AND DATE_FORMAT(p.coverage_end, '%Y-%m') = '$month'$filters
          AND w.stage = 'declined' AND w.lost_reason IS NOT NULL
        GROUP BY w.lost_reason ORDER BY n DESC");
    while ($lr && ($l = mysqli_fetch_assoc($lr))) $lost[$l['lost_reason']] = (int)$l['n'];

    jsonResponse(200, 'Renewal stats retrieved', [
        'month'               => $month,
        'total'               => $total,
        'achieved_omzet'      => (int)($row['achieved_omzet']       ?? 0),
        'total_omzet_potential' => (int)($row['total_omzet_potential'] ?? 0),
        'lost_reasons'        => $lost,
        'breakdown' => [
            'renewed'     => ['count' => $renewed,     'pct' => $pct($renewed)],
            'in_progress' => ['count' => $in_progress, 'pct' => $pct($in_progress)],
            'lapsed'      => ['count' => $lapsed,      'pct' => $pct($lapsed)],
            'cancelled'   => ['count' => $cancelled,   'pct' => $pct($cancelled)],
        ],
    ]);
}

// --- GET /api/v1/renewals/calendar?month=YYYY-MM[&insurer_id=..][&product_type=..][&sub_agent_id=..] ---
// Every policy ending in the month (no paging), for the calendar grid.
function getRenewalCalendar($conn, $company_id, $month, $filters) {
    $a = APP_SCHEMA;
    $res = mysqli_query($conn, "SELECT p.policy_id, p.policy_number, p.product_type, p.coverage_end,
            p.renewal_status, p.premium_amount, DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            c.display_name AS customer_name, i.short_name AS insurer_name,
            COALESCE((SELECT w.stage FROM $a.renewal_workflows w WHERE w.policy_id = p.policy_id), 'not_started') AS workflow_stage
        FROM $a.policies p
        LEFT JOIN $a.customers c ON c.customer_id = p.customer_id
        LEFT JOIN $a.insurers i ON i.insurer_id = p.insurer_id
        WHERE p.company_id = '$company_id' AND DATE_FORMAT(p.coverage_end, '%Y-%m') = '$month'$filters
        ORDER BY p.coverage_end ASC, p.policy_number ASC
        LIMIT 1000");
    if (!$res) throw new RuntimeException('getRenewalCalendar: ' . mysqli_error($conn));

    $data = mysqli_fetch_all($res, MYSQLI_ASSOC);
    foreach ($data as &$row) {
        $row['premium_amount']     = (int)$row['premium_amount'];
        $row['days_until_expiry']  = (int)$row['days_until_expiry'];
    }
    unset($row);
    jsonResponse(200, 'Renewal calendar retrieved', ['month' => $month, 'data' => $data]);
}

// --- RN-003: POST /api/v1/renewals/{policy_id}/send-whatsapp [NEW v1.1] ---
// Sends (or previews) a renewal follow-up WhatsApp message to the customer.
// Body: { message?: string } — overrides the saved default template for this send only.
function sendRenewalWhatsApp($conn, $policy_id, $company_id, $input, $username) {
    requirePlanFeature($conn, $company_id, 'whatsapp');
    if (!$policy_id) {
        jsonResponse(400, 'policy_id is required');
        return;
    }

    $policy_id = mysqli_real_escape_string($conn, $policy_id);

    $result = mysqli_query($conn, "SELECT
            p.policy_id, p.policy_number, p.product_type, p.coverage_end,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            p.customer_id, c.customer_type, c.display_name AS customer_name,
            c.personal_whatsapp, c.pic_whatsapp,
            i.short_name AS insurer_name
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE p.policy_id = '$policy_id' AND p.company_id = '$company_id'
        LIMIT 1");

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Policy not found');
        return;
    }

    $row = mysqli_fetch_assoc($result);

    $waNumber = $row['customer_type'] === 'company' ? $row['pic_whatsapp'] : $row['personal_whatsapp'];
    if (!$waNumber) {
        jsonResponse(400, 'Customer has no WhatsApp number on file');
        return;
    }

    $template = DEFAULT_RENEWAL_WA_TEMPLATE;
    $settingsRes = mysqli_query($conn, "SELECT renewal_wa_message_template FROM " . APP_SCHEMA . ".notification_settings WHERE company_id = '$company_id' LIMIT 1");
    if ($settingsRes && mysqli_num_rows($settingsRes) > 0) {
        $saved = mysqli_fetch_assoc($settingsRes)['renewal_wa_message_template'];
        if ($saved) { $template = $saved; }
    }

    // Per-send override always wins, but is still run through placeholder substitution.
    if (!empty($input['message']) && trim($input['message']) !== '') {
        $template = trim($input['message']);
    }

    $message = renderRenewalMessage($template, $row);
    $chatId  = toWaChatId($waNumber);
    if (!$chatId) {
        jsonResponse(400, 'Customer WhatsApp number is invalid');
        return;
    }

    $sendResult = sendWhatsAppText($chatId, $message);
    // [NEW v1.11.0] Every renewal reminder is in the customer's message log.
    msgLog($conn, $company_id, [
        'customer_id' => $row['customer_id'], 'policy_id' => $policy_id, 'message_type' => 'renewal', 'trigger_source' => 'manual',
        'recipient' => $chatId, 'body' => $message, 'sent_by' => $username,
        'status' => !empty($sendResult['success']) ? 'sent' : 'failed',
        'error' => !empty($sendResult['success']) ? null : (is_string($sendResult['error'] ?? null) ? $sendResult['error'] : 'HTTP ' . ($sendResult['httpCode'] ?? '?')),
    ]);
    if (empty($sendResult['success'])) {
        $error = $sendResult['error'] ?? $sendResult;
        insertPolicyLog($conn, $policy_id, $company_id, 'whatsapp_send_failed',
            'Gagal mengirim WhatsApp renewal reminder', $username,
            null, null, null, null, ['chatId' => $chatId, 'error' => $error]);
        jsonResponse(502, 'Failed to send WhatsApp message', ['error' => $error]);
        return;
    }

    // Log as a follow-up so it shows on the policy timeline, consistent with manual follow-up entries.
    $followup_id = 'fu_' . uniqid();
    $now         = date('Y-m-d H:i:s');
    $notes       = mysqli_real_escape_string($conn, $message);
    mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".follow_up_logs
        (followup_id, policy_id, followup_status, channel, notes, followup_date, created_by, created_at)
        VALUES ('$followup_id', '$policy_id', 'contacted', 'whatsapp', '$notes', CURDATE(), '$username', '$now')");

    insertPolicyLog($conn, $policy_id, $company_id, 'followup_logged',
        'Follow-up dicatat via whatsapp: contacted', $username,
        null, null, 'follow_up_logs', $followup_id, ['message' => $message]);

    jsonResponse(200, 'WhatsApp message sent', ['message' => $message, 'followup_id' => $followup_id]);
}


// ── Renewal quote workflow (v1.11.0) ─────────────────────────────────────────

function rwfFindPolicy($conn, string $company_id, string $policy_id): array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT p.policy_id, p.company_id, p.policy_number, p.product_type, p.policy_year, p.insurer_id, p.customer_id,
            p.coverage_start, p.coverage_end, p.renewal_status, p.sum_insured, p.premium_amount, p.commission_rate,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            c.display_name AS customer_name, i.short_name AS insurer_name,
            (SELECT COUNT(*) FROM " . APP_SCHEMA . ".policy_coverages pc WHERE pc.policy_id = p.policy_id) AS coverage_items
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE p.policy_id = '$pid' AND p.company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_RWF_001');
    return mysqli_fetch_assoc($res);
}

function rwfShape(array $policy, ?array $w, $conn): array {
    $stage = $w['stage'] ?? 'not_started';
    $events = [];
    $terms  = [];
    if ($w) {
        $wid = mysqli_real_escape_string($conn, $w['workflow_id']);
        $er = mysqli_query($conn, "SELECT e.from_stage, e.to_stage, e.note, e.created_by, e.created_at
            FROM " . APP_SCHEMA . ".renewal_workflow_events e WHERE e.workflow_id = '$wid' ORDER BY e.created_at DESC, e.event_id DESC");
        $events = $er ? mysqli_fetch_all($er, MYSQLI_ASSOC) : [];
    }
    $pid = mysqli_real_escape_string($conn, $policy['policy_id']);
    $tr = mysqli_query($conn, "SELECT term_type, title FROM " . APP_SCHEMA . ".policy_terms WHERE policy_id = '$pid' ORDER BY term_type, title");
    $terms = $tr ? mysqli_fetch_all($tr, MYSQLI_ASSOC) : [];

    $reviewKind = rwfReviewKind($policy['product_type'], $policy['policy_number'], productTypeOf($conn, $policy['company_id'], $policy['product_type']));
    $open = !in_array($policy['renewal_status'], ['renewed', 'cancelled'], true) && $stage !== 'issued';

    return [
        'policy' => [
            'policy_id'       => $policy['policy_id'],
            'policy_number'   => $policy['policy_number'],
            'product_type'    => $policy['product_type'],
            'policy_year'     => (int)$policy['policy_year'],
            'insurer_id'      => $policy['insurer_id'],
            'insurer_name'    => $policy['insurer_name'],
            'customer_id'     => $policy['customer_id'],
            'customer_name'   => $policy['customer_name'],
            'coverage_start'  => $policy['coverage_start'],
            'coverage_end'    => $policy['coverage_end'],
            'days_until_expiry' => (int)$policy['days_until_expiry'],
            'renewal_status'  => $policy['renewal_status'],
            'sum_insured'     => (int)$policy['sum_insured'],
            'premium_amount'  => (int)$policy['premium_amount'],
            'commission_rate' => (float)$policy['commission_rate'],
            'coverage_items'  => (int)$policy['coverage_items'],
            'terms'           => $terms,
        ],
        'workflow' => [
            'stage'                => $stage,
            'stage_changed_at'     => $w['stage_changed_at'] ?? null,
            'insurer_reference'    => $w['insurer_reference'] ?? null,
            'new_sum_insured'      => isset($w['new_sum_insured']) ? (int)$w['new_sum_insured'] : null,
            'new_premium'          => isset($w['new_premium']) ? (int)$w['new_premium'] : null,
            'new_commission_rate'  => isset($w['new_commission_rate']) ? (float)$w['new_commission_rate'] : null,
            'new_terms_notes'      => $w['new_terms_notes'] ?? null,
            'sum_insured_reviewed' => !empty($w['sum_insured_reviewed']),
            'review_note'          => $w['review_note'] ?? null,
            'lost_reason'          => $w['lost_reason'] ?? null,
            'lost_note'            => $w['lost_note'] ?? null,
            'renewed_policy_id'    => $w['renewed_policy_id'] ?? null,
        ],
        'allowed_next' => $open ? RWF_TRANSITIONS[$stage] : [],
        'review_kind'  => $reviewKind,
        'events'       => $events,
    ];
}

// --- RN-004: GET /api/v1/renewals/{policy_id}/workflow ---
function getRenewalWorkflow($conn, $company_id, $policy_id) {
    $policy = rwfFindPolicy($conn, $company_id, $policy_id);
    jsonResponse(200, 'Renewal workflow found', rwfShape($policy, rwfFetch($conn, $policy['policy_id']), $conn));
}

// --- RN-005: PUT /api/v1/renewals/{policy_id}/workflow ---
// Records the insurer's terms and the sum-insured review. Only the fields sent are changed.
function updateRenewalWorkflow($conn, $company_id, $policy_id, $in, $username) {
    $policy = rwfFindPolicy($conn, $company_id, $policy_id);
    if (in_array($policy['renewal_status'], ['renewed', 'cancelled'], true)) throw ApiException::from('ERR_RWF_004');

    $w = rwfEnsure($conn, $company_id, $policy['policy_id'], $username);
    if ($w['stage'] === 'issued') throw ApiException::from('ERR_RWF_004');

    $sets = [];
    $money = static function ($v, string $field) {
        try { return $v === null || $v === '' ? null : salesMoney($v, $field); }
        catch (ApiException $e) { throw ApiException::from('ERR_RWF_007', ['field' => $field]); }
    };
    foreach (['new_sum_insured', 'new_premium'] as $f) {
        if (array_key_exists($f, $in)) {
            $n = $money($in[$f], $f);
            $sets[] = "$f = " . ($n === null ? 'NULL' : $n);
        }
    }
    if (array_key_exists('new_commission_rate', $in)) {
        $r = $in['new_commission_rate'];
        if ($r === null || $r === '') {
            $sets[] = 'new_commission_rate = NULL';
        } elseif (is_numeric($r) && $r >= 0 && $r <= 100) {
            $sets[] = 'new_commission_rate = ' . round((float)$r, 2);
        } else {
            throw ApiException::from('ERR_RWF_007', ['field' => 'new_commission_rate']);
        }
    }
    foreach (['insurer_reference' => 100, 'new_terms_notes' => 4000, 'review_note' => 500] as $f => $max) {
        if (array_key_exists($f, $in)) {
            try { $t = salesText($in[$f], $f, $max); }
            catch (ApiException $e) { throw ApiException::from('ERR_RWF_007', ['field' => $f]); }
            $sets[] = "$f = " . ($t === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $t) . "'");
        }
    }
    if (array_key_exists('sum_insured_reviewed', $in)) {
        $sets[] = 'sum_insured_reviewed = ' . (filter_var($in['sum_insured_reviewed'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
    }
    if ($sets) {
        $usr = mysqli_real_escape_string($conn, $username);
        $wid = mysqli_real_escape_string($conn, $w['workflow_id']);
        if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".renewal_workflows SET " . implode(', ', $sets) . ", updated_by = '$usr' WHERE workflow_id = '$wid'")) {
            error_log('updateRenewalWorkflow: ' . mysqli_error($conn));
            throw ApiException::from('ERR_RWF_006');
        }
    }
    jsonResponse(200, 'Renewal workflow updated', rwfShape($policy, rwfFetch($conn, $policy['policy_id']), $conn));
}

// --- RN-006: PUT /api/v1/renewals/{policy_id}/workflow/stage ---
// Body: { stage, note?, lost_reason?, lost_note? }. Declining needs lost_reason and marks the policy lapsed;
// reopening a declined renewal puts the policy back to pending.
function changeRenewalStage($conn, $company_id, $policy_id, $in, $username) {
    $policy = rwfFindPolicy($conn, $company_id, $policy_id);
    if (in_array($policy['renewal_status'], ['renewed', 'cancelled'], true)) throw ApiException::from('ERR_RWF_004');

    $to = $in['stage'] ?? null;
    if (!is_string($to) || !in_array($to, RWF_STAGES, true) || $to === 'not_started') throw ApiException::from('ERR_RWF_007', ['field' => 'stage']);
    if ($to === 'issued') throw ApiException::from('ERR_RWF_005');

    $w    = rwfEnsure($conn, $company_id, $policy['policy_id'], $username);
    $from = $w['stage'];
    if (!in_array($to, RWF_TRANSITIONS[$from], true)) throw ApiException::from('ERR_RWF_002', ['from' => $from, 'to' => $to]);

    try {
        $note = salesText($in['note'] ?? null, 'note', 500);
        $lostNote = salesText($in['lost_note'] ?? null, 'lost_note', 500);
    } catch (ApiException $e) { throw ApiException::from('ERR_RWF_007', ['field' => 'note']); }

    $lostSql = "lost_reason = NULL, lost_note = NULL";
    if ($to === 'declined') {
        $reason = $in['lost_reason'] ?? null;
        if (!is_string($reason) || !in_array($reason, RWF_LOST_REASONS, true)) throw ApiException::from('ERR_RWF_003');
        $lostSql = "lost_reason = '$reason', lost_note = " . ($lostNote === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $lostNote) . "'");
    }

    $usr = mysqli_real_escape_string($conn, $username);
    $wid = mysqli_real_escape_string($conn, $w['workflow_id']);
    $pid = mysqli_real_escape_string($conn, $policy['policy_id']);
    mysqli_begin_transaction($conn);
    try {
        if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".renewal_workflows
            SET stage = '$to', stage_changed_at = NOW(), $lostSql, updated_by = '$usr' WHERE workflow_id = '$wid'")) {
            throw new RuntimeException(mysqli_error($conn));
        }
        rwfAddEvent($conn, $w['workflow_id'], $from, $to, $to === 'declined' ? ($note ?? $lostNote) : $note, $username);

        $newStatus = null;
        if ($to === 'declined' && $policy['renewal_status'] === 'pending') $newStatus = 'lapsed';
        if ($from === 'declined' && $policy['renewal_status'] === 'lapsed') $newStatus = 'pending';
        if ($newStatus) {
            if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET renewal_status = '$newStatus', updated_by = '$usr', updated_at = NOW()
                WHERE policy_id = '$pid' AND company_id = '$company_id'")) {
                throw new RuntimeException(mysqli_error($conn));
            }
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        error_log('changeRenewalStage: ' . $e->getMessage());
        throw ApiException::from('ERR_RWF_006');
    }

    $label = "Tahap renewal: $from → $to" . ($to === 'declined' ? " ({$in['lost_reason']})" : '');
    insertPolicyLog($conn, $policy['policy_id'], $company_id, 'renewal_status_changed', $label, $username, $from, $to, 'renewal_workflows', $w['workflow_id']);

    $policy['renewal_status'] = $newStatus ?? $policy['renewal_status'];
    jsonResponse(200, 'Renewal stage updated', rwfShape($policy, rwfFetch($conn, $policy['policy_id']), $conn));
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['user_id']    ?? $authUser['sub'] ?? null;

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
    exit;
}

// $action     = $parts[3] — 'stats', a policy_id, or '' (list)
// $sub_action = $parts[4] — 'send-whatsapp'
$sub_action = $parts[4] ?? '';

try {
    $conn = getConn();

    // /api/v1/renewals/{policy_id}/workflow[/stage]
    if ($action !== '' && !in_array($action, ['stats', 'calendar'], true) && $sub_action === 'workflow') {
        $sub_id = $parts[5] ?? '';
        $in     = $method === 'GET' ? [] : input();
        if ($sub_id === 'stage') {
            if ($method !== 'PUT') { Response::methodNotAllowed(); }
            changeRenewalStage($conn, $company_id, $action, $in, $username);
        } elseif ($sub_id === '') {
            if ($method === 'GET')      { getRenewalWorkflow($conn, $company_id, $action); }
            elseif ($method === 'PUT')  { updateRenewalWorkflow($conn, $company_id, $action, $in, $username); }
            else                        { Response::methodNotAllowed(); }
        } else {
            jsonResponse(404, 'Route not found');
        }

    // POST /api/v1/renewals/{policy_id}/send-whatsapp
    } elseif ($action !== '' && !in_array($action, ['stats', 'calendar'], true) && $sub_action === 'send-whatsapp') {
        if ($method !== 'POST') { jsonResponse(405, 'Method Not Allowed'); }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        sendRenewalWhatsApp($conn, $action, $company_id, $input, $username);

    // GET /api/v1/renewals/calendar
    } elseif ($action === 'calendar') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
        getRenewalCalendar($conn, $company_id, $month, renewalFilterSql($conn, $_GET));

    // GET /api/v1/renewals/stats
    } elseif ($action === 'stats') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
        getRenewalStats($conn, $company_id, $month, renewalFilterSql($conn, $_GET));

    // GET /api/v1/renewals
    } elseif ($action === '') {
        if ($method !== 'GET') { jsonResponse(405, 'Method Not Allowed'); }
        $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
        $renewal_status = isset($_GET['renewal_status'])
            ? mysqli_real_escape_string($conn, $_GET['renewal_status'])
            : '';
        $page  = max(1, (int)($_GET['page']  ?? 1));
        $limit = min(15, max(1, (int)($_GET['limit'] ?? 15)));
        $workflow_stage = isset($_GET['workflow_stage']) ? mysqli_real_escape_string($conn, $_GET['workflow_stage']) : '';
        getRenewalList($conn, $company_id, $month, $renewal_status, $page, $limit, $workflow_stage, renewalFilterSql($conn, $_GET));

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
