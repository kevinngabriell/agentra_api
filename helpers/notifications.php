<?php

// In-app notification feed. There is no event bus, so each source below is a query over the
// current data that inserts one row per event; the unique (company_id, dedupe_key) makes
// re-running it on every feed read harmless. Rows older than the lookback windows are never
// regenerated, and read rows are kept so a dismissed event does not come back.

require_once __DIR__ . '/payments.php';

const NOTIFICATION_LOOKBACK_DAYS = 14;

function syncNotifications($conn, string $company_id): void {
    $cid = mysqli_real_escape_string($conn, $company_id);
    $app = APP_SCHEMA;
    $due = paymentDueExpr('p');
    $days = NOTIFICATION_LOOKBACK_DAYS;
    $idr = static fn(string $col) => "CONCAT('Rp ', REPLACE(FORMAT($col, 0), ',', '.'))";

    $sources = [
        // Expiring today / within the week: only while the renewal is still pending.
        "SELECT 'expiring_today', CONCAT('Polis berakhir hari ini: ', p.policy_number),
                CONCAT(COALESCE(c.display_name, '-'), ' — perpanjang sekarang'),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('exp_today:', p.policy_id, ':', p.coverage_end)
         FROM $app.policies p LEFT JOIN $app.customers c ON c.customer_id = p.customer_id
         WHERE p.company_id = '$cid' AND p.renewal_status = 'pending' AND p.coverage_end = CURDATE()",

        "SELECT 'expiring_week', CONCAT('Polis berakhir ', DATE_FORMAT(p.coverage_end, '%d/%m/%Y'), ': ', p.policy_number),
                CONCAT(COALESCE(c.display_name, '-'), ' — ', DATEDIFF(p.coverage_end, CURDATE()), ' hari lagi'),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('exp_week:', p.policy_id, ':', p.coverage_end)
         FROM $app.policies p LEFT JOIN $app.customers c ON c.customer_id = p.customer_id
         WHERE p.company_id = '$cid' AND p.renewal_status = 'pending'
           AND p.coverage_end BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",

        "SELECT 'policy_lapsed', CONCAT('Polis lapsed: ', p.policy_number),
                CONCAT(COALESCE(c.display_name, '-'), ' — berakhir ', DATE_FORMAT(p.coverage_end, '%d/%m/%Y')),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('lapsed:', p.policy_id, ':', p.coverage_end)
         FROM $app.policies p LEFT JOIN $app.customers c ON c.customer_id = p.customer_id
         WHERE p.company_id = '$cid' AND p.renewal_status = 'lapsed'
           AND p.coverage_end >= DATE_SUB(CURDATE(), INTERVAL $days DAY)",

        "SELECT 'premium_overdue', CONCAT('Premi jatuh tempo: ', p.policy_number),
                CONCAT(COALESCE(c.display_name, '-'), ' — ', {$idr('p.premium_amount')}, ', jatuh tempo ', DATE_FORMAT($due, '%d/%m/%Y')),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('overdue:', p.policy_id)
         FROM $app.policies p LEFT JOIN $app.customers c ON c.customer_id = p.customer_id
         WHERE p.company_id = '$cid' AND p.payment_status = 'unpaid'
           AND p.renewal_status NOT IN ('lapsed', 'cancelled') AND $due < CURDATE()",

        "SELECT 'commission_received', CONCAT('Komisi diterima: ', p.policy_number),
                CONCAT(COALESCE(i.short_name, '-'), ' — ', {$idr('cm.received_amount')}),
                '/agentra/commissions', CONCAT('comm:', cm.commission_id, ':', cm.marked_at)
         FROM $app.commissions cm JOIN $app.policies p ON p.policy_id = cm.policy_id
         LEFT JOIN $app.insurers i ON i.insurer_id = cm.insurer_id
         WHERE cm.company_id = '$cid' AND cm.status IN ('received', 'discrepancy')
           AND cm.marked_at >= DATE_SUB(NOW(), INTERVAL $days DAY)",

        "SELECT 'claim_status', CONCAT('Status klaim berubah: ', cl.claim_number),
                CONCAT(COALESCE(ev.from_status, '-'), ' → ', ev.to_status),
                CONCAT('/agentra/claims/', cl.claim_id), CONCAT('claim:', ev.event_id)
         FROM $app.claim_events ev JOIN $app.claims cl ON cl.claim_id = ev.claim_id
         WHERE ev.company_id = '$cid' AND ev.event_type = 'status_changed' AND cl.is_deleted = 0
           AND ev.created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)",
    ];

    foreach ($sources as $select) {
        // The leading UUID() / company id columns are spliced in front of the source's own select list.
        $ok = mysqli_query($conn, "INSERT IGNORE INTO $app.notifications
            (notification_id, company_id, type, title, body, link, dedupe_key)
            " . preg_replace('/^SELECT /', "SELECT UUID(), '$cid', ", $select));
        if (!$ok) throw new RuntimeException('syncNotifications: ' . mysqli_error($conn));
    }
}
