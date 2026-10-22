<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/messaging.php';
require_once __DIR__ . '/../helpers/customer_360.php';
require_once __DIR__ . '/../helpers/sales.php';

// Customer communication over WhatsApp (migration_communications.sql).
//
//   GET    /communications/templates[?type=]        template library (default templates are created on first use)
//   POST   /communications/templates                add { template_type, name, body, send_on_date?, repeat_yearly?, auto_send? }
//   PUT    /communications/templates/{id}           edit
//   DELETE /communications/templates/{id}           delete
//   POST   /communications/preview                  render { template_id? | body, customer_id, policy_id? } without sending
//   POST   /communications/send                     send { customer_id, policy_id?, template_id? | body, message_type? }
//   GET    /communications/log                      filters: customer_id, policy_id, message_type, status, page, limit
//   POST   /communications/broadcast/preview        { filters } -> who would receive it
//   POST   /communications/broadcast                { name, template_id? | body, filters } send to a segment
//   GET    /communications/broadcasts               history of broadcasts
//
// Segment filters: product_type, insurer_id, city (policy risk city), customer_type, group_id.
// A segment matches active customers with at least one policy in force that meets the product / insurer / city filters.

function commText(mixed $v, string $field, int $max, bool $required = false): ?string {
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($required) throw ApiException::from('ERR_MSG_002', ['field' => $field]);
        return null;
    }
    if (!is_scalar($v) || is_bool($v)) throw ApiException::from('ERR_MSG_002', ['field' => $field]);
    $s = trim((string)$v);
    if (mb_strlen($s) > $max) throw ApiException::from('ERR_MSG_002', ['field' => $field]);
    return $s;
}

function commBody(mixed $v): string {
    $s = is_string($v) ? trim($v) : '';
    if ($s === '' || mb_strlen($s) > MSG_MAX_BODY) throw ApiException::from('ERR_MSG_003');
    return $s;
}

function commFindTemplate($conn, string $company_id, string $template_id): array {
    $tid = mysqli_real_escape_string($conn, $template_id);
    $res = mysqli_query($conn, "SELECT template_id, template_type, name, body, send_on_date, repeat_yearly, auto_send, created_at, updated_at
        FROM " . APP_SCHEMA . ".message_templates WHERE template_id = '$tid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_MSG_001');
    return commTemplateShape(mysqli_fetch_assoc($res));
}

function commTemplateShape(array $r): array {
    $r['repeat_yearly'] = (bool)(int)$r['repeat_yearly'];
    $r['auto_send']     = (bool)(int)$r['auto_send'];
    return $r;
}

// ── Templates ────────────────────────────────────────────────────────────────

function listTemplates($conn, string $company_id, string $username, array $q) {
    msgEnsureDefaults($conn, $company_id, $username);
    $where = "company_id = '$company_id'";
    if (!empty($q['type']) && in_array($q['type'], MSG_TEMPLATE_TYPES, true)) $where .= " AND template_type = '{$q['type']}'";
    $res = mysqli_query($conn, "SELECT template_id, template_type, name, body, send_on_date, repeat_yearly, auto_send, created_at, updated_at
        FROM " . APP_SCHEMA . ".message_templates WHERE $where ORDER BY template_type, name");
    if (!$res) throw new RuntimeException('listTemplates: ' . mysqli_error($conn));
    jsonResponse(200, 'Templates', [
        'data'         => array_map('commTemplateShape', mysqli_fetch_all($res, MYSQLI_ASSOC)),
        'placeholders' => MSG_PLACEHOLDERS,
    ]);
}

function commTemplateParse(array $in, ?array $current = null): array {
    $pick = fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($current[$k] ?? null);
    $type = $current['template_type'] ?? ($in['template_type'] ?? null);
    if (!is_string($type) || !in_array($type, MSG_TEMPLATE_TYPES, true)) throw ApiException::from('ERR_MSG_002', ['field' => 'template_type']);

    $row = [
        'template_type' => $type,
        'name'          => commText($pick('name'), 'name', 150, true),
        'body'          => commBody($pick('body')),
        'send_on_date'  => null,
        'repeat_yearly' => 0,
        'auto_send'     => 0,
    ];
    if ($type === 'holiday') {
        try { $row['send_on_date'] = salesDate($pick('send_on_date'), 'send_on_date'); }
        catch (ApiException $e) { throw ApiException::from('ERR_MSG_002', ['field' => 'send_on_date']); }
        $row['repeat_yearly'] = filter_var($pick('repeat_yearly'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (in_array($type, ['birthday', 'holiday'], true)) {
        $row['auto_send'] = filter_var($pick('auto_send'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        // An automatic holiday greeting without a date would never go out.
        if ($type === 'holiday' && $row['auto_send'] && $row['send_on_date'] === null) throw ApiException::from('ERR_MSG_002', ['field' => 'send_on_date']);
    }
    return $row;
}

function createTemplate($conn, string $company_id, array $in, string $username) {
    $row = commTemplateParse($in);
    $id  = 'tpl_' . bin2hex(random_bytes(10));
    $usr = mysqli_real_escape_string($conn, $username);
    $d   = $row['send_on_date'] === null ? 'NULL' : "'{$row['send_on_date']}'";
    if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".message_templates
        (template_id, company_id, template_type, name, body, send_on_date, repeat_yearly, auto_send, created_by)
        VALUES ('$id', '$company_id', '{$row['template_type']}', '" . mysqli_real_escape_string($conn, $row['name']) . "',
                '" . mysqli_real_escape_string($conn, $row['body']) . "', $d, {$row['repeat_yearly']}, {$row['auto_send']}, '$usr')")) {
        error_log('createTemplate: ' . mysqli_error($conn));
        throw ApiException::from('ERR_MSG_010');
    }
    jsonResponse(201, 'Template created', commFindTemplate($conn, $company_id, $id));
}

function updateTemplate($conn, string $company_id, string $template_id, array $in) {
    $cur = commFindTemplate($conn, $company_id, $template_id);
    $row = commTemplateParse($in, $cur);
    $tid = mysqli_real_escape_string($conn, $cur['template_id']);
    $d   = $row['send_on_date'] === null ? 'NULL' : "'{$row['send_on_date']}'";
    if (!mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".message_templates
        SET name = '" . mysqli_real_escape_string($conn, $row['name']) . "', body = '" . mysqli_real_escape_string($conn, $row['body']) . "',
            send_on_date = $d, repeat_yearly = {$row['repeat_yearly']}, auto_send = {$row['auto_send']}
        WHERE template_id = '$tid' AND company_id = '$company_id'")) {
        error_log('updateTemplate: ' . mysqli_error($conn));
        throw ApiException::from('ERR_MSG_010');
    }
    jsonResponse(200, 'Template updated', commFindTemplate($conn, $company_id, $tid));
}

function deleteTemplate($conn, string $company_id, string $template_id) {
    $cur = commFindTemplate($conn, $company_id, $template_id);
    $tid = mysqli_real_escape_string($conn, $cur['template_id']);
    if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".message_templates WHERE template_id = '$tid' AND company_id = '$company_id'")) {
        error_log('deleteTemplate: ' . mysqli_error($conn));
        throw ApiException::from('ERR_MSG_010');
    }
    jsonResponse(200, 'Template deleted');
}

// ── Preview and single send ──────────────────────────────────────────────────

/** Resolves the body and message type of a send from a template or an explicit body. */
function commResolveBody($conn, string $company_id, array $in): array {
    $templateId = null;
    $type = $in['message_type'] ?? 'custom';
    if (!empty($in['template_id'])) {
        $tpl        = commFindTemplate($conn, $company_id, (string)$in['template_id']);
        $templateId = $tpl['template_id'];
        $type       = $tpl['template_type'];
        $body       = !empty($in['body']) ? commBody($in['body']) : $tpl['body'];
    } else {
        $body = commBody($in['body'] ?? null);
    }
    if (!is_string($type) || !in_array($type, MSG_LOG_TYPES, true)) throw ApiException::from('ERR_MSG_002', ['field' => 'message_type']);
    return [$body, $type, $templateId];
}

function previewMessage($conn, string $company_id, array $in) {
    [$body, $type] = commResolveBody($conn, $company_id, $in);
    $customer = msgFindCustomer($conn, $company_id, (string)($in['customer_id'] ?? ''));
    $policy   = msgPolicyContext($conn, $company_id, $in['policy_id'] ?? null, $customer['customer_id']);
    jsonResponse(200, 'Message preview', [
        'message_type' => $type,
        'message'      => msgRender($body, $customer, $policy),
        'has_whatsapp' => msgChatId($customer) !== null,
    ]);
}

function sendMessage($conn, string $company_id, array $in, string $username) {
    requirePlanFeature($conn, $company_id, 'whatsapp');
    [$body, $type, $templateId] = commResolveBody($conn, $company_id, $in);
    $customer = msgFindCustomer($conn, $company_id, (string)($in['customer_id'] ?? ''));
    $policyId = !empty($in['policy_id']) ? (string)$in['policy_id'] : null;
    if (in_array($type, ['invoice', 'payment_reminder'], true) && $policyId === null) throw ApiException::from('ERR_MSG_002', ['field' => 'policy_id']);
    $policy = msgPolicyContext($conn, $company_id, $policyId, $customer['customer_id']);

    $chatId = msgChatId($customer);
    if (!$chatId) throw ApiException::from('ERR_MSG_005');

    $message = msgRender($body, $customer, $policy);
    $sent = msgSend($conn, $company_id, [
        'customer_id' => $customer['customer_id'], 'policy_id' => $policyId, 'template_id' => $templateId,
        'message_type' => $type, 'trigger_source' => 'manual', 'recipient' => $chatId, 'body' => $message, 'sent_by' => $username,
    ]);
    if ($sent['status'] !== 'sent') throw ApiException::from('ERR_MSG_006');

    jsonResponse(200, 'WhatsApp message sent', ['message' => $message, 'log_id' => $sent['log_id']]);
}

// ── Log ──────────────────────────────────────────────────────────────────────

function listMessageLog($conn, string $company_id, array $q) {
    $where = ["l.company_id = '$company_id'"];
    foreach (['customer_id', 'policy_id'] as $k) {
        if (!empty($q[$k])) $where[] = "l.$k = '" . mysqli_real_escape_string($conn, (string)$q[$k]) . "'";
    }
    if (!empty($q['message_type']) && in_array($q['message_type'], MSG_LOG_TYPES, true)) $where[] = "l.message_type = '{$q['message_type']}'";
    if (!empty($q['status']) && in_array($q['status'], ['sent', 'failed'], true)) $where[] = "l.status = '{$q['status']}'";
    $w = implode(' AND ', $where);

    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(50, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $total = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".message_log l WHERE $w"))['n'];
    $res = mysqli_query($conn, "SELECT l.log_id, l.customer_id, c.display_name AS customer_name, l.policy_id, p.policy_number,
            l.template_id, t.name AS template_name, l.broadcast_id, l.message_type, l.trigger_source, l.body, l.status, l.error, l.sent_by, l.created_at
        FROM " . APP_SCHEMA . ".message_log l
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = l.customer_id
        LEFT JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = l.policy_id
        LEFT JOIN " . APP_SCHEMA . ".message_templates t ON t.template_id = l.template_id
        WHERE $w ORDER BY l.created_at DESC, l.log_id DESC LIMIT $limit OFFSET $offset");
    if (!$res) throw new RuntimeException('listMessageLog: ' . mysqli_error($conn));
    jsonResponse(200, 'Message log', [
        'data' => mysqli_fetch_all($res, MYSQLI_ASSOC),
        'pagination' => ['total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => (int)ceil($total / $limit)],
    ]);
}

// ── Broadcast ────────────────────────────────────────────────────────────────

function commFilters(mixed $raw): array {
    $f = is_array($raw) ? $raw : [];
    $out = [];
    foreach (['product_type' => 50, 'insurer_id' => 50, 'city' => 100, 'group_id' => 50] as $k => $max) {
        $v = commText($f[$k] ?? null, $k, $max);
        if ($v !== null) $out[$k] = $v;
    }
    $ct = $f['customer_type'] ?? null;
    if ($ct !== null && $ct !== '') {
        if (!in_array($ct, ['individual', 'company'], true)) throw ApiException::from('ERR_MSG_002', ['field' => 'customer_type']);
        $out['customer_type'] = $ct;
    }
    return $out;
}

/** Active customers matching the segment, each with the chat id of their WhatsApp number (null when missing or invalid). */
function commSegment($conn, string $company_id, array $filters): array {
    $where = ["c.company_id = '$company_id'", "c.status = 'active'"];
    if (isset($filters['customer_type'])) $where[] = "c.customer_type = '{$filters['customer_type']}'";
    if (isset($filters['group_id']))      $where[] = "c.group_id = '" . mysqli_real_escape_string($conn, $filters['group_id']) . "'";

    $policyWhere = [];
    if (isset($filters['product_type'])) $policyWhere[] = "p.product_type = '" . mysqli_real_escape_string($conn, $filters['product_type']) . "'";
    if (isset($filters['insurer_id']))   $policyWhere[] = "p.insurer_id = '" . mysqli_real_escape_string($conn, $filters['insurer_id']) . "'";
    if (isset($filters['city'])) {
        $city = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['city']);
        $policyWhere[] = "p.risk_city LIKE '%" . mysqli_real_escape_string($conn, $city) . "%'";
    }
    if ($policyWhere) {
        $where[] = "EXISTS (SELECT 1 FROM " . APP_SCHEMA . ".policies p WHERE p.customer_id = c.customer_id AND p.company_id = c.company_id
            AND " . POLICY_IN_FORCE_SQL . " AND " . implode(' AND ', $policyWhere) . ")";
    }
    $res = mysqli_query($conn, "SELECT c.customer_id, c.customer_type, c.display_name, c.personal_whatsapp, c.pic_whatsapp, c.date_of_birth
        FROM " . APP_SCHEMA . ".customers c WHERE " . implode(' AND ', $where) . " ORDER BY c.display_name LIMIT 2000");
    if (!$res) throw new RuntimeException('commSegment: ' . mysqli_error($conn));
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    foreach ($rows as &$r) $r['chat_id'] = msgChatId($r);
    unset($r);
    return $rows;
}

function previewBroadcast($conn, string $company_id, array $in) {
    $segment = commSegment($conn, $company_id, commFilters($in['filters'] ?? []));
    $reachable = array_values(array_filter($segment, fn($r) => $r['chat_id'] !== null));
    jsonResponse(200, 'Broadcast preview', [
        'matched'      => count($segment),
        'reachable'    => count($reachable),
        'no_whatsapp'  => count($segment) - count($reachable),
        'max_recipients' => MSG_BROADCAST_MAX,
        'sample'       => array_map(fn($r) => $r['display_name'], array_slice($reachable, 0, 10)),
    ]);
}

function sendBroadcast($conn, string $company_id, array $in, string $username) {
    requirePlanFeature($conn, $company_id, 'whatsapp');
    $name = commText($in['name'] ?? null, 'name', 150, true);
    [$body, , $templateId] = commResolveBody($conn, $company_id, ['template_id' => $in['template_id'] ?? null, 'body' => $in['body'] ?? null, 'message_type' => 'broadcast']);
    $filters   = commFilters($in['filters'] ?? []);
    $segment   = commSegment($conn, $company_id, $filters);
    $reachable = array_values(array_filter($segment, fn($r) => $r['chat_id'] !== null));
    if (!$reachable) throw ApiException::from('ERR_MSG_008');
    if (count($reachable) > MSG_BROADCAST_MAX) throw ApiException::from('ERR_MSG_009', ['count' => count($reachable), 'max' => MSG_BROADCAST_MAX]);

    $bid  = 'bc_' . bin2hex(random_bytes(10));
    $usr  = mysqli_real_escape_string($conn, $username);
    if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".message_broadcasts (broadcast_id, company_id, name, filters, body, recipient_count, created_by)
        VALUES ('$bid', '$company_id', '" . mysqli_real_escape_string($conn, $name) . "', '" . mysqli_real_escape_string($conn, json_encode($filters)) . "',
                '" . mysqli_real_escape_string($conn, $body) . "', " . count($reachable) . ", '$usr')")) {
        error_log('sendBroadcast: ' . mysqli_error($conn));
        throw ApiException::from('ERR_MSG_010');
    }

    // One send per recipient can take a few seconds; the run is capped at MSG_BROADCAST_MAX recipients.
    set_time_limit(0);
    ignore_user_abort(true);
    $sent = 0;
    $failed = 0;
    foreach ($reachable as $i => $c) {
        if ($i > 0) usleep(MSG_SEND_PAUSE_US);
        $r = msgSend($conn, $company_id, [
            'customer_id' => $c['customer_id'], 'template_id' => $templateId, 'broadcast_id' => $bid,
            'message_type' => 'broadcast', 'trigger_source' => 'broadcast', 'recipient' => $c['chat_id'],
            'body' => msgRender($body, $c), 'sent_by' => $username,
        ]);
        $r['status'] === 'sent' ? $sent++ : $failed++;
    }
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".message_broadcasts SET sent_count = $sent, failed_count = $failed WHERE broadcast_id = '$bid'");

    jsonResponse(200, 'Broadcast sent', ['broadcast_id' => $bid, 'recipients' => count($reachable), 'sent' => $sent, 'failed' => $failed]);
}

function listBroadcasts($conn, string $company_id) {
    $res = mysqli_query($conn, "SELECT broadcast_id, name, filters, body, recipient_count, sent_count, failed_count, created_by, created_at
        FROM " . APP_SCHEMA . ".message_broadcasts WHERE company_id = '$company_id' ORDER BY created_at DESC LIMIT 50");
    if (!$res) throw new RuntimeException('listBroadcasts: ' . mysqli_error($conn));
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $r['filters'] = json_decode($r['filters'] ?? '{}', true) ?: new stdClass();
        foreach (['recipient_count', 'sent_count', 'failed_count'] as $k) $r[$k] = (int)$r[$k];
    }
    unset($r);
    jsonResponse(200, 'Broadcasts', ['data' => $rows]);
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

    if ($action === 'templates') {
        if ($sub_action === '') {
            switch ($method) {
                case 'GET':  listTemplates($conn, $company_id, $username, $_GET); break;
                case 'POST': createTemplate($conn, $company_id, input(), $username); break;
                default:     Response::methodNotAllowed();
            }
        } else {
            switch ($method) {
                case 'PUT':    updateTemplate($conn, $company_id, $sub_action, input()); break;
                case 'DELETE': deleteTemplate($conn, $company_id, $sub_action); break;
                default:       Response::methodNotAllowed();
            }
        }
    } elseif ($action === 'preview') {
        if ($method !== 'POST') Response::methodNotAllowed();
        previewMessage($conn, $company_id, input());
    } elseif ($action === 'send') {
        if ($method !== 'POST') Response::methodNotAllowed();
        sendMessage($conn, $company_id, input(), $username);
    } elseif ($action === 'log') {
        if ($method !== 'GET') Response::methodNotAllowed();
        listMessageLog($conn, $company_id, $_GET);
    } elseif ($action === 'broadcast' && $sub_action === 'preview') {
        if ($method !== 'POST') Response::methodNotAllowed();
        previewBroadcast($conn, $company_id, input());
    } elseif ($action === 'broadcast' && $sub_action === '') {
        if ($method !== 'POST') Response::methodNotAllowed();
        sendBroadcast($conn, $company_id, input(), $username);
    } elseif ($action === 'broadcasts') {
        if ($method !== 'GET') Response::methodNotAllowed();
        listBroadcasts($conn, $company_id);
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
