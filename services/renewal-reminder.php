<?php
// [NEW v1.1] POST /api/v1/services/renewal-reminder
//
// Sends a WhatsApp digest to each issuing agent listing their policies entering
// the renewal window (default 30 days before coverage_end, configurable per
// company via notification_settings.renewal_reminder_days).
//
// Auth: reuses the standard JWT (helpers/jwt.php).
//   - A service token minted via mint-cron-token.php (payload: service=cron,
//     no company_id) runs across every company that has policies — this is
//     how the daily external cron should call this endpoint.
//   - A normal logged-in user's token (has company_id) runs the reminder for
//     just their own company, e.g. an FE "Kirim reminder sekarang" button.
//
// Each policy is only notified once: renewal_reminder_sent_at is stamped after
// a successful send, so re-running the same day (or an early retry) does not
// spam the agent. A send failure leaves it NULL so the next run retries it.

require_once __DIR__ . '/../helpers/renewal_message.php';
require_once __DIR__ . '/../helpers/subscription.php';
require_once __DIR__ . '/../notification/notification.php';

$authUser = requireAuth();
$method   = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(405, 'Method Not Allowed');
}

$isService = ($authUser['service'] ?? null) === 'cron';
$scopedCompanyId = $authUser['company_id'] ?? null;

if (!$isService && !$scopedCompanyId) {
    jsonResponse(403, 'Not authorized to trigger renewal reminders');
}

// Authorization for the manual (non-cron) path: only admins/managers may
// trigger a company-wide WhatsApp blast. Without this, any agent in the
// company could spam every issuing agent's phone and run up WAHA API costs.
if (!$isService) {
    $role = $authUser['role'] ?? '';
    if (!in_array($role, ['admin', 'manager', 'owner'], true)) {
        jsonResponse(403, 'Only admins or managers may trigger renewal reminders');
    }
}

$conn = getConn();

// Rate limit for the manual path: one run per company per 10 minutes.
// The cron path (service token) is exempt — it's scheduled once daily.
if (!$isService) {
    $cidChk = mysqli_real_escape_string($conn, $scopedCompanyId);
    $recent = mysqli_query($conn, "SELECT 1 FROM " . APP_SCHEMA . ".whatsapp_digest_logs
        WHERE company_id = '$cidChk' AND digest_type = 'renewal_reminder'
          AND sent_at > (NOW() - INTERVAL 10 MINUTE) LIMIT 1");
    if ($recent && mysqli_num_rows($recent) > 0) {
        jsonResponse(429, 'A renewal reminder was recently sent. Please wait before retrying.');
    }
}

// ── Determine target companies ─────────────────────────────────────────────
if ($isService) {
    $res = mysqli_query($conn, "SELECT DISTINCT company_id FROM " . APP_SCHEMA . ".policies");
    $companyIds = $res ? array_column(mysqli_fetch_all($res, MYSQLI_ASSOC), 'company_id') : [];
} else {
    $companyIds = [$scopedCompanyId];
}

$summary = [
    'companies_processed' => 0,
    'agents_notified'     => 0,
    'policies_included'   => 0,
    'send_failures'       => [],
];

foreach ($companyIds as $company_id) {
    $cid = mysqli_real_escape_string($conn, $company_id);
    $summary['companies_processed']++;

    // Automatic WhatsApp reminders are a Profesional/Tim feature.
    if (!companyHasFeature($conn, $company_id, 'whatsapp')) continue;

    $settingsRes = mysqli_query($conn, "SELECT renewal_reminder_days, whatsapp_target_number FROM " . APP_SCHEMA . ".notification_settings WHERE company_id = '$cid' LIMIT 1");
    $settings = $settingsRes && mysqli_num_rows($settingsRes) > 0 ? mysqli_fetch_assoc($settingsRes) : null;
    $reminderDays = $settings ? (int)$settings['renewal_reminder_days'] : 30;
    $companyWaFallback = $settings['whatsapp_target_number'] ?? null;

    $policiesRes = mysqli_query($conn, "SELECT
            p.policy_id, p.policy_number, p.product_type, p.coverage_end, p.issuing_agent_id,
            DATEDIFF(p.coverage_end, CURDATE()) AS days_until_expiry,
            c.display_name AS customer_name,
            i.short_name   AS insurer_name
        FROM " . APP_SCHEMA . ".policies p
        LEFT JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers  i ON i.insurer_id  = p.insurer_id
        WHERE p.company_id = '$cid'
          AND p.renewal_status = 'pending'
          AND p.renewal_reminder_sent_at IS NULL
          AND p.coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $reminderDays DAY)
        ORDER BY p.issuing_agent_id, p.coverage_end ASC");

    $policies = $policiesRes ? mysqli_fetch_all($policiesRes, MYSQLI_ASSOC) : [];
    if (empty($policies)) {
        continue;
    }

    // Group by issuing agent — NULL group = main agent / company-level policies.
    $byAgent = [];
    foreach ($policies as $p) {
        $byAgent[$p['issuing_agent_id'] ?? ''][] = $p;
    }

    foreach ($byAgent as $agentId => $agentPolicies) {
        $waNumber = $companyWaFallback;
        if ($agentId !== '') {
            $aid = mysqli_real_escape_string($conn, $agentId);
            // Sub-agents (v1.7.0) first, then the older login-based agent_profiles.
            $agentRes = mysqli_query($conn, "SELECT whatsapp_number FROM " . APP_SCHEMA . ".sub_agents WHERE sub_agent_id = '$aid' AND company_id = '$cid' AND wa_digest_enabled = 1 LIMIT 1");
            if (!$agentRes || mysqli_num_rows($agentRes) === 0) {
                $agentRes = mysqli_query($conn, "SELECT whatsapp_number FROM " . APP_SCHEMA . ".agent_profiles WHERE user_id = '$aid' AND company_id = '$cid' LIMIT 1");
            }
            if ($agentRes && mysqli_num_rows($agentRes) > 0) {
                $agentWa = mysqli_fetch_assoc($agentRes)['whatsapp_number'];
                if ($agentWa) { $waNumber = $agentWa; }
            }
        }

        if (!$waNumber) {
            $summary['send_failures'][] = ['company_id' => $company_id, 'agent_id' => $agentId ?: null, 'error' => 'No WhatsApp number on file'];
            continue;
        }

        $chatId = toWaChatId($waNumber);
        if (!$chatId) {
            $summary['send_failures'][] = ['company_id' => $company_id, 'agent_id' => $agentId ?: null, 'error' => 'Invalid WhatsApp number'];
            continue;
        }

        $lines = ["Pengingat perpanjangan polis ({$reminderDays} hari ke depan):"];
        foreach ($agentPolicies as $i => $p) {
            $n = $i + 1;
            $endDate = date('d/m/Y', strtotime($p['coverage_end']));
            // Sanitize DB-sourced fields that flow into the outbound WhatsApp
            // message — a customer created with display_name containing a URL
            // would otherwise turn the API into a phishing relay, since
            // WhatsApp renders URLs as clickable links.
            $safeName = mb_substr(preg_replace('#https?://\S+#i', '[link]', $p['customer_name'] ?? ''), 0, 80);
            $safeInsurer = mb_substr(preg_replace('#https?://\S+#i', '[link]', $p['insurer_name'] ?? ''), 0, 80);
            $lines[] = "{$n}. {$p['policy_number']} — {$safeName} ({$safeInsurer}), berakhir {$endDate} ({$p['days_until_expiry']} hari lagi)";
        }
        $message = implode("\n", $lines);

        $sendResult = sendWhatsAppText($chatId, $message);
        $now = date('Y-m-d H:i:s');
        $status = !empty($sendResult['success']) ? 'sent' : 'failed';

        $log_id = 'wadlog_' . bin2hex(random_bytes(10));
        $waEsc  = mysqli_real_escape_string($conn, $waNumber);
        $msgEsc = mysqli_real_escape_string($conn, $message);
        // Avoid persisting the WAHA 'raw' response (could echo API key or stack
        // traces). Persist only the structured error/data.
        $errPayload = $status === 'failed'
            ? ($sendResult['error'] ?? ['httpCode' => $sendResult['httpCode'] ?? 0, 'data' => $sendResult['data'] ?? null])
            : null;
        // Guard json_encode against non-UTF-8 bytes.
        if ($errPayload !== null) {
            $errJson = json_encode($errPayload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($errJson === false) {
                $errJson = json_encode(['__encode_error' => json_last_error_msg()]);
            }
            $errEsc = "'" . mysqli_real_escape_string($conn, $errJson) . "'";
        } else {
            $errEsc = 'NULL';
        }

        $okLog = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".whatsapp_digest_logs
            (log_id, company_id, digest_type, wa_number, message_body, sent_at, status, error_message)
            VALUES ('$log_id', '$cid', 'renewal_reminder', '$waEsc', '$msgEsc', '$now', '$status', $errEsc)");

        if ($status === 'sent') {
            // Defensive: never build `WHERE policy_id IN ()` — MySQL rejects it.
            if (!empty($agentPolicies)) {
                $ids = implode(',', array_map(fn($p) => "'" . mysqli_real_escape_string($conn, $p['policy_id']) . "'", $agentPolicies));
                $okStamp = mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET renewal_reminder_sent_at = '$now' WHERE policy_id IN ($ids)");
                if (!$okStamp) {
                    // The WhatsApp message was sent but the stamp failed — the
                    // next cron run WILL re-send. Log it so ops can see the
                    // duplicate risk instead of silently breaking the
                    // "notified once" guarantee.
                    error_log("renewal-reminder stamp failed for company $company_id agent $agentId: " . mysqli_error($conn));
                    $summary['send_failures'][] = [
                        'company_id' => $company_id,
                        'agent_id'   => $agentId ?: null,
                        'error'      => 'send ok but stamp failed — may resend',
                    ];
                    continue 2;
                }
            }
            $summary['agents_notified']++;
            $summary['policies_included'] += count($agentPolicies);
        } else {
            if (!$okLog) {
                error_log("renewal-reminder audit log insert failed for company $company_id agent $agentId: " . mysqli_error($conn));
            }
            $summary['send_failures'][] = ['company_id' => $company_id, 'agent_id' => $agentId ?: null, 'error' => $sendResult['error'] ?? 'send failed'];
        }
    }
}

jsonResponse(200, 'Renewal reminder run complete', $summary);
