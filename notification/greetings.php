<?php
// CLI-only cron: automatic birthday and holiday greetings over WhatsApp.
//
// For every company, sends:
//   - each birthday template with auto_send = 1 to active individual customers whose birthday is today;
//   - each holiday template with auto_send = 1 to every active customer, on its send_on_date
//     (the same month and day every year when repeat_yearly = 1).
// A customer gets a given template at most once per calendar year (message_log is the record), so
// re-running the cron the same day does not send twice. Failed sends are logged and retried on the next run.
//
// Usage:
//   php notification/greetings.php [--dry-run]
//
// Cron (daily, 08:00 WIB):
//   0 8 * * * /usr/bin/php8.3 /home/ubuntu/apps/agentra_api/notification/greetings.php >> /var/log/agentra_greetings.log 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/ApiException.php';
require_once __DIR__ . '/../helpers/messaging.php';

$dryRun = in_array('--dry-run', $argv, true);
$conn   = getConn();
date_default_timezone_set('Asia/Jakarta');
$today  = date('Y-m-d');
$md     = date('m-d');
$year   = date('Y');

$tplRes = mysqli_query($conn, "SELECT template_id, company_id, template_type, name, body, send_on_date, repeat_yearly
    FROM " . APP_SCHEMA . ".message_templates
    WHERE auto_send = 1 AND template_type IN ('birthday', 'holiday')");
$templates = $tplRes ? mysqli_fetch_all($tplRes, MYSQLI_ASSOC) : [];

$totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

foreach ($templates as $tpl) {
    $cid = mysqli_real_escape_string($conn, $tpl['company_id']);
    $tid = mysqli_real_escape_string($conn, $tpl['template_id']);

    if ($tpl['template_type'] === 'holiday') {
        $due = $tpl['send_on_date'] !== null && ($tpl['repeat_yearly']
            ? substr($tpl['send_on_date'], 5) === $md
            : $tpl['send_on_date'] === $today);
        if (!$due) continue;
        $audience = "c.status = 'active'";
    } else {
        // Someone born on 29 Feb is greeted on 28 Feb in a common year.
        $isLeap   = (bool)date('L');
        $dobMatch = "DATE_FORMAT(c.date_of_birth, '%m-%d') = '$md'";
        if ($md === '02-28' && !$isLeap) $dobMatch = "DATE_FORMAT(c.date_of_birth, '%m-%d') IN ('02-28', '02-29')";
        $audience = "c.status = 'active' AND c.customer_type = 'individual' AND c.date_of_birth IS NOT NULL AND $dobMatch";
    }

    $res = mysqli_query($conn, "SELECT c.customer_id, c.customer_type, c.display_name, c.personal_whatsapp, c.pic_whatsapp
        FROM " . APP_SCHEMA . ".customers c
        WHERE c.company_id = '$cid' AND $audience
          AND NOT EXISTS (SELECT 1 FROM " . APP_SCHEMA . ".message_log l
                          WHERE l.template_id = '$tid' AND l.customer_id = c.customer_id AND l.status = 'sent' AND YEAR(l.created_at) = $year)");
    $customers = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];

    foreach ($customers as $c) {
        $chatId = msgChatId($c);
        if (!$chatId) { $totals['skipped']++; continue; }
        $body = msgRender($tpl['body'], $c);
        if ($dryRun) {
            echo "[dry-run] {$tpl['template_type']} '{$tpl['name']}' -> {$c['display_name']} ($chatId)\n";
            continue;
        }
        $r = msgSend($conn, $tpl['company_id'], [
            'customer_id' => $c['customer_id'], 'template_id' => $tpl['template_id'], 'message_type' => $tpl['template_type'],
            'trigger_source' => 'auto', 'recipient' => $chatId, 'body' => $body, 'sent_by' => null,
        ]);
        $totals[$r['status'] === 'sent' ? 'sent' : 'failed']++;
        usleep(MSG_SEND_PAUSE_US);
    }
}

echo date('c') . " greetings: sent={$totals['sent']} failed={$totals['failed']} skipped(no whatsapp)={$totals['skipped']}" . ($dryRun ? ' (dry run)' : '') . "\n";
