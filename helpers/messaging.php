<?php

// Customer messaging over WhatsApp (migration_communications.sql): templates, placeholder
// rendering, sending and the message log. Used by communications/index.php,
// renewals/index.php and notification/greetings.php.

require_once __DIR__ . '/renewal_message.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/subscription.php';
require_once __DIR__ . '/../notification/notification.php';

const MSG_TEMPLATE_TYPES = ['invoice', 'payment_reminder', 'birthday', 'holiday', 'broadcast', 'custom'];
const MSG_LOG_TYPES      = ['renewal', 'invoice', 'payment_reminder', 'birthday', 'holiday', 'broadcast', 'custom'];
const MSG_MAX_BODY       = 2000;
const MSG_BROADCAST_MAX  = 100;
// Pause between sends in a broadcast, so WhatsApp does not see a burst (microseconds).
const MSG_SEND_PAUSE_US  = 400000;

const MSG_PLACEHOLDERS = [
    '{customer_name}', '{policy_number}', '{product_type}', '{insurer_name}', '{coverage_end}',
    '{days_until_expiry}', '{premium_amount}', '{amount_due}', '{due_date}',
];

function msgDefaultTemplates(): array {
    return [
        ['payment_reminder', 'Pengingat pembayaran premi',
            "Yth. {customer_name},\n\nKami mengingatkan bahwa premi polis {policy_number} ({product_type}) sebesar {amount_due} jatuh tempo pada {due_date}. Mohon segera melakukan pembayaran agar perlindungan polis tetap berlaku.\n\nAbaikan pesan ini jika Anda sudah membayar. Terima kasih.", 0, null, 0],
        ['invoice', 'Nota debet / invoice premi',
            "Yth. {customer_name},\n\nTerlampir tagihan premi polis {policy_number} ({product_type}, {insurer_name}).\nTotal yang harus dibayar: {amount_due}\nJatuh tempo: {due_date}\n\nTerima kasih atas kepercayaan Bapak/Ibu.", 0, null, 0],
        ['birthday', 'Ucapan ulang tahun',
            "Selamat ulang tahun, {customer_name}! Semoga sehat, sukses dan dilimpahi kebahagiaan. Terima kasih telah mempercayakan perlindungan Anda kepada kami.", 0, null, 0],
        ['holiday', 'Ucapan Tahun Baru',
            "Selamat Tahun Baru, {customer_name}! Semoga tahun ini membawa kesehatan dan kesuksesan. Terima kasih atas kepercayaan Bapak/Ibu kepada kami.", 0, date('Y') . '-01-01', 1],
        ['broadcast', 'Informasi untuk nasabah',
            "Halo {customer_name}, kami ingin menyampaikan informasi terbaru terkait perlindungan asuransi Anda. Silakan hubungi kami jika ada yang ingin ditanyakan.", 0, null, 0],
    ];
}

/** Creates the default templates the first time a company has none. */
function msgEnsureDefaults($conn, string $company_id, string $username): void {
    $res = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".message_templates WHERE company_id = '$company_id' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) return;
    $usr = mysqli_real_escape_string($conn, $username);
    foreach (msgDefaultTemplates() as [$type, $name, $body, $auto, $date, $yearly]) {
        $id = 'tpl_' . bin2hex(random_bytes(10));
        $n  = mysqli_real_escape_string($conn, $name);
        $b  = mysqli_real_escape_string($conn, $body);
        $d  = $date === null ? 'NULL' : "'$date'";
        mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".message_templates
            (template_id, company_id, template_type, name, body, send_on_date, repeat_yearly, auto_send, created_by)
            VALUES ('$id', '$company_id', '$type', '$n', '$b', $d, $yearly, $auto, '$usr')");
    }
}

/** WhatsApp chat id for a customer row (company customers use the PIC number), or null. */
function msgChatId(array $customer): ?string {
    $number = ($customer['customer_type'] ?? '') === 'company' ? ($customer['pic_whatsapp'] ?? '') : ($customer['personal_whatsapp'] ?? '');
    return $number ? toWaChatId($number) : null;
}

function msgFindCustomer($conn, string $company_id, string $customer_id): array {
    $cid = mysqli_real_escape_string($conn, $customer_id);
    $res = mysqli_query($conn, "SELECT customer_id, customer_type, display_name, status, personal_whatsapp, pic_whatsapp, date_of_birth
        FROM " . APP_SCHEMA . ".customers WHERE customer_id = '$cid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_MSG_004');
    return mysqli_fetch_assoc($res);
}

/** The policy fields a template can use, or an empty array when no policy is given. */
function msgPolicyContext($conn, string $company_id, ?string $policy_id, ?string $customer_id = null): array {
    if (!$policy_id) return [];
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT p.policy_id, p.customer_id, p.policy_number, COALESCE(mp.product_name, p.product_type) AS product_name,
            p.coverage_start, p.coverage_end, p.premium_amount, p.customer_premium_amount, p.payment_due_date,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry, i.short_name AS insurer_name
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        LEFT JOIN " . APP_SCHEMA . ".master_products mp ON mp.company_id = p.company_id AND mp.product_code = p.product_type
        WHERE p.policy_id = '$pid' AND p.company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_MSG_007');
    $p = mysqli_fetch_assoc($res);
    if ($customer_id !== null && $p['customer_id'] !== $customer_id) throw ApiException::from('ERR_MSG_007');

    $billed = (int)$p['customer_premium_amount'] > 0 ? (int)$p['customer_premium_amount'] : (int)$p['premium_amount'];
    $due    = $p['payment_due_date'] ?: date('Y-m-d', strtotime($p['coverage_start'] . ' +' . PAYMENT_WARRANTY_DAYS . ' days'));
    return [
        'policy_number'     => $p['policy_number'],
        'product_type'      => $p['product_name'],
        'insurer_name'      => $p['insurer_name'] ?? '-',
        'coverage_end'      => $p['coverage_end'],
        'days_until_expiry' => $p['days_until_expiry'],
        'premium_amount'    => (int)$p['premium_amount'],
        'amount_due'        => $billed,
        'due_date'          => $due,
    ];
}

function msgDate(?string $date): string {
    if (!$date || $date === '0000-00-00') return '-';
    $ts = strtotime($date);
    return $ts === false ? '-' : date('d/m/Y', $ts);
}

function msgIdr(int $n): string {
    return 'Rp ' . number_format($n, 0, ',', '.');
}

/** Fills {placeholder} tokens. Values that come from customer-entered data have URLs stripped. */
function msgRender(string $template, array $customer, array $policy = []): string {
    $clean = static fn($v) => is_string($v) ? preg_replace('#https?://\S+#i', '[link]', $v) : '-';
    $map = [
        '{customer_name}'     => $clean($customer['display_name'] ?? '-'),
        '{policy_number}'     => $clean($policy['policy_number'] ?? '-'),
        '{product_type}'      => $clean($policy['product_type'] ?? '-'),
        '{insurer_name}'      => $clean($policy['insurer_name'] ?? '-'),
        '{coverage_end}'      => msgDate($policy['coverage_end'] ?? null),
        '{days_until_expiry}' => isset($policy['days_until_expiry']) ? (string)(int)$policy['days_until_expiry'] : '-',
        '{premium_amount}'    => isset($policy['premium_amount']) ? msgIdr((int)$policy['premium_amount']) : '-',
        '{amount_due}'        => isset($policy['amount_due']) ? msgIdr((int)$policy['amount_due']) : '-',
        '{due_date}'          => msgDate($policy['due_date'] ?? null),
    ];
    return strtr($template, $map);
}

/** Writes one message_log row. */
function msgLog($conn, string $company_id, array $f): string {
    $id = 'msg_' . bin2hex(random_bytes(10));
    $q  = static fn($v) => $v === null || $v === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
    $error = isset($f['error']) ? mb_substr((string)$f['error'], 0, 300) : null;
    if (!mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".message_log
        (log_id, company_id, customer_id, policy_id, template_id, broadcast_id, message_type, trigger_source, recipient, body, status, error, sent_by)
        VALUES ('$id', '$company_id', {$q($f['customer_id'] ?? null)}, {$q($f['policy_id'] ?? null)}, {$q($f['template_id'] ?? null)},
                {$q($f['broadcast_id'] ?? null)}, '{$f['message_type']}', '{$f['trigger_source']}', {$q($f['recipient'])}, {$q($f['body'])},
                '{$f['status']}', {$q($error)}, {$q($f['sent_by'] ?? null)})")) {
        // Never let the log break a send that already went out.
        error_log('msgLog: ' . mysqli_error($conn));
    }
    return $id;
}

/**
 * Sends one WhatsApp text and logs the outcome. Returns ['status' => 'sent'|'failed', 'error' => ?string, 'log_id' => string].
 * $f needs customer_id, message_type, trigger_source, body, recipient (chat id); policy_id, template_id, broadcast_id, sent_by are optional.
 */
function msgSend($conn, string $company_id, array $f): array {
    // Automatic sends (greetings) and any caller that skipped the route-level check stop here too.
    if (!companyHasFeature($conn, $company_id, 'whatsapp')) {
        return ['status' => 'failed', 'error' => 'plan_not_included', 'log_id' => ''];
    }
    $result = sendWhatsAppText($f['recipient'], $f['body']);
    $ok     = !empty($result['success']);
    $error  = null;
    if (!$ok) {
        $raw   = $result['error'] ?? ('HTTP ' . ($result['httpCode'] ?? '?'));
        $error = is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE);
    }
    $logId = msgLog($conn, $company_id, $f + ['status' => $ok ? 'sent' : 'failed', 'error' => $error]);
    return ['status' => $ok ? 'sent' : 'failed', 'error' => $error, 'log_id' => $logId];
}
