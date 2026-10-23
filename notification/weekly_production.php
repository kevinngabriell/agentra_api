<?php
// CLI-only cron: weekly / monthly production WhatsApp report.
//
// Reports how many policies were issued (and their premium) in the current
// week (Monday → today) so agents can see how far they've reached.
//   - Each issuing agent with agent_profiles.whatsapp_number gets their own numbers.
//   - The company's notification_settings.whatsapp_target_number gets the
//     company total with a per-agent breakdown.
//
// Usage:
//   php notification/weekly_production.php [--last-week | --monthly] [--dry-run]
//   --monthly reports the previous calendar month (run on the 1st).
//
// Cron (Saturday 17:00 WIB; the report covers Monday → Saturday):
//   0 17 * * 6 /usr/bin/php8.3 /home/ubuntu/apps/agentra_api/notification/weekly_production.php >> /var/log/agentra_weekly_production.log 2>&1
// Cron (1st of every month, 09:00 WIB; reports the previous month):
//   0 9 1 * * /usr/bin/php8.3 /home/ubuntu/apps/agentra_api/notification/weekly_production.php --monthly >> /var/log/agentra_monthly_production.log 2>&1

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/renewal_message.php';
require_once __DIR__ . '/notification.php';

$lastWeek = in_array('--last-week', $argv, true);
$monthly  = in_array('--monthly', $argv, true);
$dryRun   = in_array('--dry-run', $argv, true);

$tz    = new DateTimeZone('Asia/Jakarta');
$today = new DateTimeImmutable('today', $tz);

if ($monthly) {
    // Previous calendar month (run on the 1st of the next month).
    $first       = new DateTimeImmutable('first day of last month', $tz);
    $periodStart = $first->format('Y-m-d');
    $periodEndExcl = $first->modify('+1 month')->format('Y-m-d');
    $prevStart   = $first->modify('-1 month')->format('Y-m-d');
    $labelEnd    = $first->modify('last day of this month')->format('d/m/Y');
    $periodLabel = $first->format('d/m/Y') . ' - ' . $labelEnd;
    $digestType  = 'monthly_production';
    $reportTitle = 'Laporan produksi bulanan';
    $thisPeriod  = 'bulan lalu';
    $prevPeriod  = 'bulan sebelumnya';
    $nextPeriod  = 'bulan ini';
} else {
    $monday = new DateTimeImmutable('monday this week', $tz);
    if ($lastWeek) {
        $monday = $monday->modify('-7 days');
    }
    $periodStart   = $monday->format('Y-m-d');
    $periodEndExcl = $monday->modify('+7 days')->format('Y-m-d');
    $prevStart     = $monday->modify('-7 days')->format('Y-m-d');
    $sunday        = $monday->modify('+6 days');
    $labelEnd      = ($today < $sunday ? $today : $sunday)->format('d/m/Y');
    $periodLabel   = $monday->format('d/m/Y') . ' - ' . $labelEnd;
    $digestType    = 'weekly_production';
    $reportTitle   = 'Laporan produksi mingguan';
    $thisPeriod    = 'minggu ini';
    $prevPeriod    = 'minggu lalu';
    $nextPeriod    = 'minggu depan';
}
define('DIGEST_TYPE', $digestType);

$conn = getConn();

function rupiah($n): string {
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function sendAndLog(mysqli $conn, string $cid, string $waNumber, string $message, bool $dryRun): bool {
    $chatId = toWaChatId($waNumber);
    if (!$chatId) {
        echo "[skip] invalid WhatsApp number for company $cid\n";
        return false;
    }
    if ($dryRun) {
        echo "---- to $waNumber ----\n$message\n\n";
        return true;
    }

    $result = sendWhatsAppText($chatId, $message);
    $status = !empty($result['success']) ? 'sent' : 'failed';

    $errEsc = 'NULL';
    if ($status === 'failed') {
        $errPayload = $result['error'] ?? ['httpCode' => $result['httpCode'] ?? 0, 'data' => $result['data'] ?? null];
        $errJson = json_encode($errPayload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            ?: json_encode(['__encode_error' => json_last_error_msg()]);
        $errEsc = "'" . mysqli_real_escape_string($conn, $errJson) . "'";
    }

    $logId  = 'wadlog_' . bin2hex(random_bytes(10));
    $waEsc  = mysqli_real_escape_string($conn, $waNumber);
    $msgEsc = mysqli_real_escape_string($conn, $message);
    $now    = date('Y-m-d H:i:s');
    $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".whatsapp_digest_logs
        (log_id, company_id, digest_type, wa_number, message_body, sent_at, status, error_message)
        VALUES ('$logId', '$cid', '" . DIGEST_TYPE . "', '$waEsc', '$msgEsc', '$now', '$status', $errEsc)");
    if (!$ok) {
        error_log("weekly_production audit log insert failed for company $cid: " . mysqli_error($conn));
    }

    echo "[$status] company $cid → $waNumber\n";
    return $status === 'sent';
}

$res = mysqli_query($conn, "SELECT DISTINCT company_id FROM " . APP_SCHEMA . ".policies");
$companyIds = $res ? array_column(mysqli_fetch_all($res, MYSQLI_ASSOC), 'company_id') : [];

$sent = 0;
$failed = 0;

foreach ($companyIds as $company_id) {
    $cid = mysqli_real_escape_string($conn, $company_id);

    // Per-agent production for the week (NULL issuing_agent_id = main agent / company-level).
    $rows = mysqli_query($conn, "SELECT issuing_agent_id,
            COUNT(*) AS policy_count,
            COALESCE(SUM(premium_amount), 0)          AS total_premium,
            COALESCE(SUM(net_commission_amount), 0)   AS total_commission
        FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid'
          AND renewal_status <> 'cancelled'
          AND created_at >= '$periodStart' AND created_at < '$periodEndExcl'
        GROUP BY issuing_agent_id
        ORDER BY policy_count DESC");
    $byAgent = $rows ? mysqli_fetch_all($rows, MYSQLI_ASSOC) : [];

    $prevRes = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM " . APP_SCHEMA . ".policies
        WHERE company_id = '$cid' AND renewal_status <> 'cancelled'
          AND created_at >= '$prevStart' AND created_at < '$periodStart'");
    $prevCount = $prevRes ? (int)mysqli_fetch_assoc($prevRes)['cnt'] : 0;

    $totalCount = array_sum(array_column($byAgent, 'policy_count'));
    $totalPrem  = array_sum(array_column($byAgent, 'total_premium'));
    $totalComm  = array_sum(array_column($byAgent, 'total_commission'));

    $settingsRes = mysqli_query($conn, "SELECT whatsapp_target_number FROM " . APP_SCHEMA . ".notification_settings WHERE company_id = '$cid' LIMIT 1");
    $companyWa = $settingsRes && mysqli_num_rows($settingsRes) > 0 ? mysqli_fetch_assoc($settingsRes)['whatsapp_target_number'] : null;

    // Agent names + numbers for the breakdown and per-agent messages.
    $profiles = [];
    $profRes = mysqli_query($conn, "SELECT ap.user_id, ap.whatsapp_number, u.first_name
        FROM " . APP_SCHEMA . ".agent_profiles ap
        LEFT JOIN " . CORE_SCHEMA . ".app_user u ON u.user_id = ap.user_id
        WHERE ap.company_id = '$cid'");
    if ($profRes) {
        foreach (mysqli_fetch_all($profRes, MYSQLI_ASSOC) as $p) {
            $profiles[$p['user_id']] = $p;
        }
    }

    $diff = $totalCount - $prevCount;
    $trend = $diff === 0 ? "sama dengan $prevPeriod" : (($diff > 0 ? '+' : '') . $diff . " dibanding $prevPeriod");

    // ── Company-level message ──────────────────────────────────────────────
    if ($companyWa) {
        $lines = ["$reportTitle ($periodLabel):",
                  "Total polis: $totalCount ($trend)",
                  "Total premi: " . rupiah($totalPrem),
                  "Komisi bersih: " . rupiah($totalComm)];
        if (count($byAgent) > 0) {
            $lines[] = "";
            $lines[] = "Per agen:";
            foreach ($byAgent as $i => $r) {
                $aid  = $r['issuing_agent_id'] ?? '';
                $name = $aid === '' ? 'Agen utama' : ($profiles[$aid]['first_name'] ?? 'Agen');
                $name = mb_substr(preg_replace('#https?://\S+#i', '[link]', $name), 0, 40);
                $lines[] = ($i + 1) . ". $name — {$r['policy_count']} polis, " . rupiah($r['total_premium']);
            }
        } else {
            $lines[] = "";
            $lines[] = "Belum ada polis baru $thisPeriod.";
        }
        sendAndLog($conn, $company_id, $companyWa, implode("\n", $lines), $dryRun) ? $sent++ : $failed++;
    }

    // ── Per-agent messages ─────────────────────────────────────────────────
    // Every agent with a WhatsApp number is messaged, including those with zero production.
    $prodByAgent = [];
    foreach ($byAgent as $r) {
        if (($r['issuing_agent_id'] ?? '') !== '') {
            $prodByAgent[$r['issuing_agent_id']] = $r;
        }
    }

    foreach ($profiles as $aid => $profile) {
        if (empty($profile['whatsapp_number'])) continue;
        $wa = $profile['whatsapp_number'];
        // Don't double-message someone who already got the company summary.
        if ($companyWa && toWaChatId($wa) === toWaChatId($companyWa)) continue;

        $r = $prodByAgent[$aid] ?? null;
        if ($r === null) {
            $msg = "Produksi Anda $thisPeriod ($periodLabel):\n" .
                   ucfirst($thisPeriod) . " belum ada produksi, ayo kejar target di $nextPeriod!";
        } else {
            $msg = "Produksi Anda $thisPeriod ($periodLabel):\n" .
                   "Polis: {$r['policy_count']}\n" .
                   "Premi: " . rupiah($r['total_premium']) . "\n" .
                   "Komisi bersih: " . rupiah($r['total_commission']);
        }
        sendAndLog($conn, $company_id, $wa, $msg, $dryRun) ? $sent++ : $failed++;
    }
}

echo date('c') . " weekly_production done: sent=$sent failed=$failed" . ($dryRun ? ' (dry-run)' : '') . "\n";
exit($failed > 0 ? 1 : 0);
