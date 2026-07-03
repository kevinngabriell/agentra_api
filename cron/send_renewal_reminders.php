<?php
// CLI-only. Meant to run once a day at 09:00 Asia/Jakarta (WIB) via the VPS crontab.
// See cron/README.md for the crontab line and setup notes.
//
// Sends ONE consolidated WhatsApp message per AGENT (issuing_agent_id, falling back
// to created_by) listing every pending-renewal policy of theirs expiring in exactly
// 30, 20, or 10 days, or in fewer than 10 days (included daily while it stays under
// 10 and hasn't expired) — so an agent with 100+ policies due gets a short digest
// instead of 100 separate messages. Long lists are paginated into multiple messages.
// Each policy's reminder is logged to follow_up_logs so a re-run on the same day
// never double-sends.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../notification/notification.php';
require_once __DIR__ . '/../helpers/policy_log.php';

const MAX_POLICIES_PER_MESSAGE = 25;

function renewalReminderDigest(string $agentName, array $lines, int $pageNum, int $totalPages, int $totalPolicies): string {
    $greetingName = $agentName !== '' ? $agentName : 'Agent';
    $pageSuffix   = $totalPages > 1 ? " (Bagian {$pageNum}/{$totalPages})" : '';

    $body = "Halo {$greetingName}! 👋{$pageSuffix}\n\n"
        . "Berikut polis yang perlu segera di-follow up untuk konfirmasi perpanjangan (renewal):\n\n"
        . implode("\n", $lines)
        . "\n\nMohon segera hubungi masing-masing pelanggan di atas.\n"
        . "Total polis butuh follow up: {$totalPolicies}\n\n"
        . "Terima kasih! 🙏";

    return $body;
}

function renewalLine(int $num, int $daysLeft, string $customerName, string $policyNumber, string $productName, string $coverageEndFormatted): string {
    $tag = $daysLeft < 10 ? '⚠️ SEGERA' : "H-{$daysLeft}";
    $dayText = $daysLeft > 0 ? "{$daysLeft} hari lagi" : 'hari ini';
    return "{$num}. [{$tag}] {$customerName} — {$policyNumber} ({$productName}), berakhir {$coverageEndFormatted}, {$dayText}";
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

$today                      = date('Y-m-d');
$skipped_no_agent_whatsapp  = 0;
$skipped_already_sent       = 0;

// Group every policy still needing a reminder today under its agent's phone number.
$byAgent = [];

while ($policy = mysqli_fetch_assoc($result)) {
    $policy_id = mysqli_real_escape_string($conn, $policy['policy_id']);

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

    $phone = preg_replace('/[^0-9]/', '', $agentPhone);

    $customerName = $policy['customer_type'] === 'company'
        ? ($policy['company_legal_name'] ?: $policy['display_name'])
        : $policy['display_name'];

    if (!isset($byAgent[$phone])) {
        $byAgent[$phone] = ['name' => $agentName, 'policies' => []];
    }

    $byAgent[$phone]['policies'][] = [
        'policy_id'      => $policy['policy_id'],
        'company_id'     => $policy['company_id'],
        'days_left'      => (int)$policy['days_left'],
        'customer_name'  => (string)$customerName,
        'policy_number'  => $policy['policy_number'],
        'product_name'   => $policy['product_name'],
        'coverage_end'   => date('d/m/Y', strtotime($policy['coverage_end'])),
    ];
}

$agents_notified = 0;
$policies_sent    = 0;
$messages_sent    = 0;

foreach ($byAgent as $phone => $agentData) {
    $policies = $agentData['policies'];
    usort($policies, fn($a, $b) => $a['days_left'] <=> $b['days_left']);

    $chatId      = "{$phone}@c.us";
    $totalCount  = count($policies);
    $pages       = array_chunk($policies, MAX_POLICIES_PER_MESSAGE);
    $totalPages  = count($pages);
    $agentNotified = false;

    foreach ($pages as $pageIndex => $pagePolicies) {
        $lines = [];
        foreach ($pagePolicies as $i => $p) {
            $lines[] = renewalLine($i + 1, $p['days_left'], $p['customer_name'], $p['policy_number'], $p['product_name'], $p['coverage_end']);
        }

        $text     = renewalReminderDigest($agentData['name'], $lines, $pageIndex + 1, $totalPages, $totalCount);
        $response = sendWhatsAppText($chatId, $text);
        $messages_sent++;

        $now    = date('Y-m-d H:i:s');
        $status = $response['success'] ? 'contacted' : 'no_response';

        foreach ($pagePolicies as $p) {
            $policy_id   = mysqli_real_escape_string($conn, $p['policy_id']);
            $company_id  = mysqli_real_escape_string($conn, $p['company_id']);
            $bucket      = $p['days_left'] < 10 ? "urgent H-{$p['days_left']}" : "H-{$p['days_left']}";
            $followup_id = 'fu_' . uniqid();
            $notes       = mysqli_real_escape_string($conn,
                ($response['success']
                    ? "Reminder renewal ke agent otomatis terkirim ({$bucket}, digest)"
                    : "Reminder renewal ke agent otomatis gagal terkirim ({$bucket}, digest)")
            );

            mysqli_query($conn, "
                INSERT INTO " . APP_SCHEMA . ".follow_up_logs
                    (followup_id, policy_id, followup_status, channel, notes, followup_date, created_by, created_at)
                VALUES
                    ('$followup_id', '$policy_id', '$status', 'whatsapp_reminder', '$notes', '$today', 'system_cron', '$now')
            ");
            insertPolicyLog($conn, $policy_id, $company_id, 'followup_logged', $notes, 'system_cron', null, null, 'follow_up_logs', $followup_id);

            $policies_sent++;
        }

        $agentNotified = true;
        usleep(300000); // small delay between sends to stay friendly to the WAHA session
    }

    if ($agentNotified) {
        $agents_notified++;
    }
}

echo "Renewal reminders done: agents_notified={$agents_notified}, messages_sent={$messages_sent}, "
    . "policies_included={$policies_sent}, skipped_no_agent_whatsapp={$skipped_no_agent_whatsapp}, "
    . "skipped_already_sent_today={$skipped_already_sent}" . PHP_EOL;
