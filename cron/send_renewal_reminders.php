<?php
// CLI-only. Meant to run once a day at 09:00 Asia/Jakarta (WIB) via the VPS crontab.
// See cron/README.md for the crontab line and setup notes.
//
// Sends a WhatsApp reminder to the AGENT (issuing_agent_id, falling back to
// created_by) of every pending-renewal policy that is expiring in exactly 30, 20,
// or 10 days, or in fewer than 10 days (sent daily while it stays under 10 and
// hasn't expired), so the agent can follow up with the customer to confirm
// renewal. Each send is logged to follow_up_logs so a re-run on the same day
// never double-sends.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../notification/notification.php';
require_once __DIR__ . '/../helpers/policy_log.php';

function renewalReminderMessage(int $daysLeft, string $agentName, string $customerName, string $policyNumber, string $productName, string $coverageEndFormatted): string {
    $greetingName = $agentName !== '' ? $agentName : 'Agent';

    if ($daysLeft >= 10) {
        return "Halo {$greetingName}! 👋\n\n"
            . "Polis *{$productName}* nomor *{$policyNumber}* milik *{$customerName}* akan berakhir pada *{$coverageEndFormatted}* ({$daysLeft} hari lagi).\n\n"
            . "Mohon hubungi pelanggan untuk proses konfirmasi perpanjangan (renewal) polis sebelum masa berlaku habis.\n\n"
            . "Terima kasih! 🙏";
    }

    return "Halo {$greetingName}! ⚠️\n\n"
        . "Polis *{$productName}* nomor *{$policyNumber}* milik *{$customerName}* akan segera berakhir pada *{$coverageEndFormatted}* "
        . ($daysLeft > 0 ? "(tinggal {$daysLeft} hari lagi)" : "(hari ini)") . ".\n\n"
        . "Mohon segera hubungi pelanggan untuk konfirmasi perpanjangan polis agar perlindungan tidak terputus.\n\n"
        . "Terima kasih! 🙏";
}

$conn = getConn();

$result = mysqli_query($conn, "
    SELECT
        p.policy_id, p.company_id, p.policy_number, p.product_type,
        p.coverage_end, DATEDIFF(p.coverage_end, CURDATE()) AS days_left,
        p.issuing_agent_id, p.created_by,
        c.customer_type, c.display_name, c.company_legal_name,
        COALESCE(mp.product_name, p.product_type) AS product_name,
        au_issuing.phone_number AS issuing_agent_phone,
        au_issuing.first_name  AS issuing_agent_name,
        au_creator.phone_number AS creator_phone,
        au_creator.first_name  AS creator_name
    FROM " . APP_SCHEMA . ".policies p
    LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
    LEFT JOIN " . APP_SCHEMA . ".master_products mp
        ON mp.company_id = p.company_id AND mp.product_code = p.product_type
    LEFT JOIN " . CORE_SCHEMA . ".app_user au_issuing ON au_issuing.user_id = p.issuing_agent_id
    LEFT JOIN " . CORE_SCHEMA . ".app_user au_creator ON au_creator.user_id = p.created_by
    WHERE p.renewal_status = 'pending'
      AND (
            DATEDIFF(p.coverage_end, CURDATE()) IN (30, 20, 10)
         OR DATEDIFF(p.coverage_end, CURDATE()) BETWEEN 0 AND 9
      )
");

if (!$result) {
    fwrite(STDERR, 'Query failed: ' . mysqli_error($conn) . PHP_EOL);
    exit(1);
}

$today = date('Y-m-d');
$sent  = 0;
$skipped_no_agent_whatsapp = 0;
$skipped_already_sent = 0;

while ($policy = mysqli_fetch_assoc($result)) {
    $policy_id  = mysqli_real_escape_string($conn, $policy['policy_id']);
    $company_id = mysqli_real_escape_string($conn, $policy['company_id']);

    $already = mysqli_query($conn, "
        SELECT 1 FROM " . APP_SCHEMA . ".follow_up_logs
        WHERE policy_id = '$policy_id' AND channel = 'whatsapp_reminder' AND followup_date = '$today'
        LIMIT 1
    ");
    if ($already && mysqli_num_rows($already) > 0) {
        $skipped_already_sent++;
        continue;
    }

    // Prefer the policy's issuing agent; fall back to whoever created the policy record.
    $agentPhone = trim((string)($policy['issuing_agent_phone'] ?: $policy['creator_phone'] ?: ''));
    $agentName  = trim((string)($policy['issuing_agent_name'] ?: $policy['creator_name'] ?: ''));

    if ($agentPhone === '') {
        $skipped_no_agent_whatsapp++;
        continue;
    }

    $phone  = preg_replace('/[^0-9]/', '', $agentPhone);
    $chatId = "{$phone}@c.us";

    $customerName = $policy['customer_type'] === 'company'
        ? ($policy['company_legal_name'] ?: $policy['display_name'])
        : $policy['display_name'];

    $daysLeft   = (int)$policy['days_left'];
    $coverageEndFormatted = date('d/m/Y', strtotime($policy['coverage_end']));

    $text = renewalReminderMessage($daysLeft, $agentName, (string)$customerName, $policy['policy_number'], $policy['product_name'], $coverageEndFormatted);

    $response = sendWhatsAppText($chatId, $text);

    $bucket_label = in_array($daysLeft, [30, 20, 10], true) ? "H-{$daysLeft}" : "H-{$daysLeft} (urgent)";
    $now          = date('Y-m-d H:i:s');
    $followup_id  = 'fu_' . uniqid();
    $status       = $response['success'] ? 'contacted' : 'no_response';
    $notes        = mysqli_real_escape_string($conn,
        ($response['success'] ? "Reminder renewal ke agent otomatis terkirim ({$bucket_label})" : "Reminder renewal ke agent otomatis gagal terkirim ({$bucket_label})")
    );

    mysqli_query($conn, "
        INSERT INTO " . APP_SCHEMA . ".follow_up_logs
            (followup_id, policy_id, followup_status, channel, notes, followup_date, created_by, created_at)
        VALUES
            ('$followup_id', '$policy_id', '$status', 'whatsapp_reminder', '$notes', '$today', 'system_cron', '$now')
    ");
    insertPolicyLog($conn, $policy_id, $company_id, 'followup_logged', $notes, 'system_cron', null, null, 'follow_up_logs', $followup_id);

    $sent++;
    usleep(300000); // small delay between sends to stay friendly to the WAHA session
}

echo "Renewal reminders done: sent={$sent}, skipped_no_agent_whatsapp={$skipped_no_agent_whatsapp}, skipped_already_sent_today={$skipped_already_sent}" . PHP_EOL;
