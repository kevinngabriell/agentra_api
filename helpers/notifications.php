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

    // ── [v2.1.0] Large-account events ──────────────────────────────────────────
    $sources = array_merge($sources, [
        // Per-policy lead time: big risks need terms requested weeks before the company-wide window.
        "SELECT 'expiring_week', CONCAT('Mulai proses perpanjangan: ', p.policy_number),
                CONCAT(COALESCE(c.display_name, '-'), ' — berakhir ', DATE_FORMAT(p.coverage_end, '%d/%m/%Y'), ' (', DATEDIFF(p.coverage_end, CURDATE()), ' hari lagi)'),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('exp_lead:', p.policy_id, ':', p.coverage_end)
         FROM $app.policies p LEFT JOIN $app.customers c ON c.customer_id = p.customer_id
         WHERE p.company_id = '$cid' AND p.renewal_status = 'pending' AND p.renewal_lead_days IS NOT NULL
           AND p.coverage_end > DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           AND p.coverage_end <= DATE_ADD(CURDATE(), INTERVAL p.renewal_lead_days DAY)",

        "SELECT 'covernote_due', CONCAT('Covernote ', COALESCE(p.covernote_number, ''), ' hampir habis: ', p.policy_number),
                CONCAT(COALESCE(c.display_name, '-'), ' — polis belum diterima, covernote berlaku s/d ', DATE_FORMAT(p.covernote_expiry, '%d/%m/%Y')),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('covernote:', p.policy_id, ':', p.covernote_expiry)
         FROM $app.policies p LEFT JOIN $app.customers c ON c.customer_id = p.customer_id
         WHERE p.company_id = '$cid' AND p.covernote_expiry IS NOT NULL AND p.policy_doc_received_date IS NULL
           AND p.renewal_status <> 'cancelled' AND p.covernote_expiry <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
           AND p.covernote_expiry >= DATE_SUB(CURDATE(), INTERVAL $days DAY)",

        "SELECT 'endorsement_due', CONCAT('Nota debit endorsemen jatuh tempo: ', e.note_number),
                CONCAT(p.policy_number, ' — ', {$idr('(e.premium_delta + e.materai_amount + e.biaya_polis)')}, ', jatuh tempo ', DATE_FORMAT(e.due_date, '%d/%m/%Y')),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('endo:', e.endorsement_id)
         FROM $app.policy_endorsements e JOIN $app.policies p ON p.policy_id = e.policy_id
         WHERE e.company_id = '$cid' AND e.is_void = 0 AND e.note_type = 'debit' AND e.settled_date IS NULL AND e.due_date < CURDATE()",

        "SELECT 'warranty_due', CONCAT(IF(r.is_warranty = 1, 'Warranty', 'Rekomendasi survei'), ' jatuh tempo: ', p.policy_number),
                CONCAT(LEFT(r.description, 150), ' — batas ', DATE_FORMAT(r.due_date, '%d/%m/%Y')),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('warr:', r.recommendation_id, ':', r.due_date)
         FROM $app.policy_risk_recommendations r JOIN $app.policies p ON p.policy_id = r.policy_id
         WHERE r.company_id = '$cid' AND r.status = 'open' AND r.due_date IS NOT NULL AND (r.is_warranty = 1 OR r.priority = 'high')
           AND r.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND p.renewal_status <> 'cancelled'",

        "SELECT 'party_proof_due', CONCAT(pt.name, ' menunggu bukti perpanjangan: ', p.policy_number),
                CONCAT('Polis berakhir ', DATE_FORMAT(p.coverage_end, '%d/%m/%Y'), ' — kirim bukti perpanjangan sebelum ', IF(pt.party_type = 'mortgagee', 'bank', 'leasing'), ' menutup asuransi sendiri'),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('party:', pt.party_id, ':', p.coverage_end)
         FROM $app.policy_parties pt JOIN $app.policies p ON p.policy_id = pt.policy_id
         WHERE pt.company_id = '$cid' AND pt.notify_renewal = 1 AND p.renewal_status = 'pending'
           AND (pt.tenor_end IS NULL OR pt.tenor_end > p.coverage_end)
           AND p.coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)",

        "SELECT 'year_premium_due', CONCAT('Premi tahun ke-', y.year_no, ' jatuh tempo: ', p.policy_number),
                CONCAT({$idr('y.premium_amount')}, ' — jatuh tempo ', DATE_FORMAT(COALESCE(y.due_date, y.period_start), '%d/%m/%Y')),
                CONCAT('/agentra/policies/', p.policy_id), CONCAT('year:', y.year_id)
         FROM $app.policy_year_schedule y JOIN $app.policies p ON p.policy_id = y.policy_id
         WHERE y.company_id = '$cid' AND y.paid_date IS NULL AND y.year_no > 1 AND p.renewal_status <> 'cancelled'
           AND COALESCE(y.due_date, y.period_start) <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)",

        // Claim deadlines that are not yet met, three days ahead or already passed.
        "SELECT 'claim_deadline', CONCAT('Batas klaim ', cl.claim_number, ': ', d.label),
                CONCAT(COALESCE(c.display_name, '-'), ' — ', DATE_FORMAT(d.due, '%d/%m/%Y'), IF(d.due < CURDATE(), ' (terlewat)', '')),
                CONCAT('/agentra/claims/', cl.claim_id), CONCAT('cdl:', cl.claim_id, ':', d.k, ':', d.due)
         FROM $app.claims cl
         JOIN $app.customers c ON c.customer_id = cl.customer_id
         JOIN (SELECT 'docs' AS k, 'dokumen lengkap' AS label UNION ALL SELECT 'decision', 'keputusan asuransi' UNION ALL SELECT 'payment', 'pembayaran klaim') kinds
         JOIN LATERAL (SELECT kinds.k, kinds.label,
                CASE kinds.k WHEN 'docs' THEN IF(cl.status IN ('reported', 'survey'), cl.docs_deadline, NULL)
                             WHEN 'decision' THEN IF(cl.status IN ('documents_complete', 'appeal'), cl.decision_due, NULL)
                             ELSE IF(cl.status IN ('approved', 'partially_paid'), cl.payment_due, NULL) END AS due) d
         WHERE cl.company_id = '$cid' AND cl.is_deleted = 0 AND d.due IS NOT NULL
           AND d.due <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) AND d.due >= DATE_SUB(CURDATE(), INTERVAL $days DAY)",
    ]);

    foreach ($sources as $select) {
        // The leading UUID() / company id columns are spliced in front of the source's own select list.
        $ok = mysqli_query($conn, "INSERT IGNORE INTO $app.notifications
            (notification_id, company_id, type, title, body, link, dedupe_key)
            " . preg_replace('/^SELECT /', "SELECT UUID(), '$cid', ", $select));
        if (!$ok) throw new RuntimeException('syncNotifications: ' . mysqli_error($conn));
    }
}
