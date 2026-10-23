<?php
// CLI-only cron: daily WhatsApp digest of open tasks that are due today or overdue.
//
// Each company with a notification_settings.whatsapp_target_number and at least one such task
// gets one message. Companies with nothing due are skipped.
//
// Usage:
//   php notification/tasks_digest.php [--dry-run]
//
// Cron (daily, 07:30 WIB):
//   30 7 * * * /usr/bin/php8.3 /home/ubuntu/apps/agentra_api/notification/tasks_digest.php >> /var/log/agentra_tasks_digest.log 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/renewal_message.php';
require_once __DIR__ . '/notification.php';

$dryRun = in_array('--dry-run', $argv, true);
$conn   = getConn();
date_default_timezone_set('Asia/Jakarta');
$app = APP_SCHEMA;

$res = mysqli_query($conn, "SELECT t.company_id, ns.whatsapp_target_number, t.title, t.due_date,
        DATEDIFF(t.due_date, CURDATE()) AS days, COALESCE(c.display_name, pc.display_name) AS customer, p.policy_number
    FROM $app.tasks t
    JOIN $app.notification_settings ns ON ns.company_id = t.company_id
    LEFT JOIN $app.customers c ON c.customer_id = t.customer_id
    LEFT JOIN $app.policies p ON p.policy_id = t.policy_id
    LEFT JOIN $app.customers pc ON pc.customer_id = p.customer_id
    WHERE t.status = 'open' AND t.due_date <= CURDATE() AND ns.whatsapp_target_number <> ''
    ORDER BY t.company_id, t.due_date");
$byCompany = [];
foreach ($res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [] as $row) $byCompany[$row['company_id']][] = $row;

$sent = $failed = 0;
foreach ($byCompany as $cid => $tasks) {
    $lines = ["*Tugas hari ini (" . date('d/m/Y') . ")*", ''];
    foreach ($tasks as $t) {
        $ref = array_filter([$t['customer'], $t['policy_number']]);
        $late = (int)$t['days'] < 0 ? ' ⚠️ terlambat ' . abs((int)$t['days']) . ' hari' : '';
        $lines[] = '• ' . $t['title'] . ($ref ? ' (' . implode(' · ', $ref) . ')' : '') . $late;
    }
    $message = implode("\n", $lines);
    $number  = $tasks[0]['whatsapp_target_number'];
    $chatId  = toWaChatId($number);
    if (!$chatId) { echo "[skip] invalid WhatsApp number for company $cid\n"; continue; }
    if ($dryRun) { echo "---- to $number ----\n$message\n\n"; continue; }

    $result = sendWhatsAppText($chatId, $message);
    if (!empty($result['success'])) { $sent++; } else { $failed++; echo "[failed] company $cid\n"; }
}
echo "Tasks digest: $sent sent, $failed failed\n";
