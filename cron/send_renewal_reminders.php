<?php
// CLI-only. Meant to run once a day at 09:00 Asia/Jakarta (WIB) via the VPS crontab.
// See cron/README.md for the crontab line and setup notes.
//
// Sends a WhatsApp reminder to the customer of every pending-renewal policy that is
// expiring in exactly 30, 20, or 10 days, or in fewer than 10 days (sent daily while
// it stays under 10 and hasn't expired). Each send is logged to follow_up_logs so a
// re-run on the same day never double-sends.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../notification/notification.php';
require_once __DIR__ . '/../helpers/policy_log.php';

function renewalReminderMessage(int $daysLeft, string $customerName, string $policyNumber, string $productName, string $coverageEndFormatted): string {
    $greetingName = $customerName !== '' ? $customerName : 'Bapak/Ibu';

    if ($daysLeft >= 10) {
        return "Halo {$greetingName}! 👋\n\n"
            . "Polis *{$productName}* Anda dengan nomor *{$policyNumber}* akan berakhir pada *{$coverageEndFormatted}* ({$daysLeft} hari lagi).\n\n"
            . "Mohon hubungi agen Anda untuk proses perpanjangan (renewal) polis sebelum masa berlaku habis.\n\n"
            . "Terima kasih! 🙏";
    }

    return "Halo {$greetingName}! ⚠️\n\n"
        . "Polis *{$productName}* Anda dengan nomor *{$policyNumber}* akan segera berakhir pada *{$coverageEndFormatted}* "
        . ($daysLeft > 0 ? "(tinggal {$daysLeft} hari lagi)" : "(hari ini)") . ".\n\n"
        . "Mohon segera konfirmasi perpanjangan polis Anda agar perlindungan tidak terputus.\n\n"
        . "Terima kasih! 🙏";
}

$conn = getConn();

$result = mysqli_query($conn, "
    SELECT
        p.policy_id, p.company_id, p.policy_number, p.product_type,
        p.coverage_end, DATEDIFF(p.coverage_end, CURDATE()) AS days_left,
        c.customer_type, c.display_name, c.company_legal_name,
        COALESCE(NULLIF(c.personal_whatsapp, ''), NULLIF(c.pic_whatsapp, '')) AS whatsapp,
        COALESCE(mp.product_name, p.product_type) AS product_name
    FROM " . APP_SCHEMA . ".policies p
    LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
    LEFT JOIN " . APP_SCHEMA . ".master_products mp
        ON mp.company_id = p.company_id AND mp.product_code = p.product_type
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
$skipped_no_whatsapp = 0;
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

    $whatsapp = trim((string)($policy['whatsapp'] ?? ''));
    if ($whatsapp === '') {
        $skipped_no_whatsapp++;
        continue;
    }

    $phone  = preg_replace('/[^0-9]/', '', $whatsapp);
    $chatId = "{$phone}@c.us";

    $customerName = $policy['customer_type'] === 'company'
        ? ($policy['company_legal_name'] ?: $policy['display_name'])
        : $policy['display_name'];

    $daysLeft   = (int)$policy['days_left'];
    $coverageEndFormatted = date('d/m/Y', strtotime($policy['coverage_end']));

    $text = renewalReminderMessage($daysLeft, (string)$customerName, $policy['policy_number'], $policy['product_name'], $coverageEndFormatted);

    $response = sendWhatsAppText($chatId, $text);

    $bucket_label = in_array($daysLeft, [30, 20, 10], true) ? "H-{$daysLeft}" : "H-{$daysLeft} (urgent)";
    $now          = date('Y-m-d H:i:s');
    $followup_id  = 'fu_' . uniqid();
    $status       = $response['success'] ? 'contacted' : 'no_response';
    $notes        = mysqli_real_escape_string($conn,
        ($response['success'] ? "Reminder renewal otomatis terkirim ({$bucket_label})" : "Reminder renewal otomatis gagal terkirim ({$bucket_label})")
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

echo "Renewal reminders done: sent={$sent}, skipped_no_whatsapp={$skipped_no_whatsapp}, skipped_already_sent_today={$skipped_already_sent}" . PHP_EOL;
