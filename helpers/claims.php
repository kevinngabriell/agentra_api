<?php

// Shared claim rules: status flow, the per-product document checklist, and the
// "pending documents" check used by both the claims module and the dashboard.

// [v2.1.0] partially_paid (interim payments), appeal (after a rejection), withdrawn (by the insured).
const CLAIM_STATUSES = ['reported', 'survey', 'documents_complete', 'approved', 'partially_paid', 'paid', 'rejected', 'appeal', 'withdrawn'];

// Statuses in which the claim is still being worked on.
const CLAIM_OPEN_STATUSES = ['reported', 'survey', 'documents_complete', 'approved', 'partially_paid', 'appeal'];

// Statuses in which the agent is still collecting documents.
const CLAIM_COLLECTING_STATUSES = ['reported', 'survey'];

// Allowed moves. Survey is optional; a rejected claim can be reopened with new documents or appealed;
// a paid claim can be reopened when more loss is found; the insured can withdraw while it is open.
const CLAIM_TRANSITIONS = [
    'reported'           => ['survey', 'documents_complete', 'rejected', 'withdrawn'],
    'survey'             => ['documents_complete', 'rejected', 'withdrawn'],
    'documents_complete' => ['approved', 'rejected', 'withdrawn'],
    'approved'           => ['partially_paid', 'paid'],
    'partially_paid'     => ['paid'],
    'paid'               => ['survey', 'documents_complete'],
    'rejected'           => ['documents_complete', 'appeal'],
    'appeal'             => ['approved', 'rejected'],
    'withdrawn'          => ['reported'],
];

// Days the insurer has to decide once the documents are complete, and to pay once the amount is agreed
// (30 days each under POJK 69/POJK.05/2016). Used as default due dates; the agent can edit them.
const CLAIM_DECISION_DAYS = 30;
const CLAIM_PAYMENT_DAYS  = 30;

/** SQL for what a claim costs the insurer: settlement once paid, the approved amount while approved / part-paid. */
function claimIncurredSql(string $a = 'cl'): string {
    return "CASE WHEN $a.status = 'paid' THEN COALESCE($a.settlement_amount, $a.approved_amount)
                 WHEN $a.status IN ('approved', 'partially_paid') THEN $a.approved_amount ELSE 0 END";
}

const CLAIM_STATUS_LABELS = [
    'reported'           => 'Dilaporkan',
    'survey'             => 'Survei',
    'documents_complete' => 'Dokumen Lengkap',
    'approved'           => 'Disetujui',
    'partially_paid'     => 'Dibayar Sebagian',
    'paid'               => 'Dibayar',
    'rejected'           => 'Ditolak',
    'appeal'             => 'Banding',
    'withdrawn'          => 'Dicabut',
];

const CLAIM_FOLLOWUP_CHANNELS = ['whatsapp', 'phone', 'email', 'in_person', 'insurer'];

/**
 * Maps a policy's product_type to a checklist group. product_type is either one of the
 * built-in codes (car, motorcycle, fire, ...) or a company-defined master_products code
 * (kendaraan, kebakaran, ...), so match on keywords.
 */
function claimProductGroup(string $product_type): string {
    $p = strtolower(trim($product_type));
    // "car" is a built-in code but also a prefix of "cargo", so it only matches exactly.
    if ($p === 'car') return 'motor';

    $keywords = [
        'cargo'  => ['cargo', 'marine', 'angkut'],
        'motor'  => ['motor', 'mobil', 'kendaraan', 'otomotif'],
        'fire'   => ['fire', 'kebakaran', 'property', 'properti'],
        'travel' => ['travel', 'perjalanan'],
        'pa'     => ['kecelakaan', 'aep', 'accident', 'jiwa'],
    ];
    foreach ($keywords as $group => $words) {
        foreach ($words as $w) {
            if (str_contains($p, $w)) return $group;
        }
    }
    return 'default';
}

/**
 * [v2.1.0] The product code to feed claimProductGroup(): company codes such as "01" or "61" carry no keyword,
 * so the line is taken from the master product's type first (same rule as the risk details).
 */
function claimCanonicalProduct($conn, string $company_id, string $code): string {
    require_once __DIR__ . '/risk_details.php';
    $group = riskGroup($code, '', productTypeOf($conn, $company_id, $code));
    return match ($group) { null => $code, 'pa' => 'kecelakaan', default => $group };
}

/** [v2.1.0] Sub-type of a claim from its cause, for cause-specific checklists. */
function claimCauseKind(string $cause): string {
    $c = strtolower($cause);
    // Most specific first: "bagasi hilang" is lost baggage, not theft.
    $map = [
        'baggage'      => ['bagasi', 'koper', 'baggage', 'luggage'],
        'delay'        => ['tunda', 'delay', 'terlambat'],
        'cancellation' => ['batal', 'cancel'],
        'medical'      => ['medis', 'sakit', 'rawat', 'medical', 'hospital', 'kecelakaan diri'],
        'third_party'  => ['pihak ketiga', 'third party', 'tpl'],
        'total_loss'   => ['total loss', 'hancur total', 'rusak total'],
        'theft'        => ['curi', 'hilang', 'theft', 'rampok', 'begal'],
    ];
    foreach ($map as $kind => $words) foreach ($words as $w) if (str_contains($c, $w)) return $kind;
    return 'general';
}

/** @return array<int, array{key: string, label: string, required: bool}> */
function claimRequirements(string $product_type, string $cause = ''): array {
    $r = fn(string $key, string $label, bool $required = true) => ['key' => $key, 'label' => $label, 'required' => $required];
    $group = claimProductGroup($product_type);
    $kind = claimCauseKind($cause);

    // [v2.1.0] Cause-specific lists where the documents differ from the line's default.
    if ($group === 'motor' && in_array($kind, ['theft', 'total_loss'], true)) {
        return [
            $r('claim_form', 'Formulir klaim'),
            $r('police_report', 'Laporan polisi' . ($kind === 'theft' ? ' (kehilangan)' : '')),
            $r('stnk', 'STNK asli'),
            $r('bpkb', 'BPKB asli + kuitansi blanko (pelepasan hak)'),
            $r('keys', 'Kunci kendaraan (kunci kontak & duplikat)'),
            $r('ktp', 'KTP tertanggung'),
            $r('sim', 'SIM pengemudi', $kind !== 'theft'),
            $r('leasing_letter', 'Surat keterangan leasing (jika kredit)', false),
        ];
    }
    if ($group === 'motor' && $kind === 'third_party') {
        return [
            $r('claim_form', 'Formulir klaim'), $r('police_report', 'Laporan polisi'), $r('sim', 'SIM pengemudi'),
            $r('stnk', 'STNK'), $r('third_party_claim', 'Surat tuntutan pihak ketiga'), $r('damage_photos', 'Foto kerusakan pihak ketiga'),
            $r('settlement_letter', 'Surat perdamaian / kesepakatan', false),
        ];
    }
    if ($group === 'travel' && $kind !== 'general') {
        return match ($kind) {
            'medical' => [$r('claim_form', 'Formulir klaim'), $r('passport', 'Paspor (halaman identitas & cap imigrasi)'),
                          $r('medical_report', 'Laporan medis / diagnosis dokter'), $r('receipts', 'Kuitansi & invoice rumah sakit asli'),
                          $r('ticket', 'Tiket / boarding pass'), $r('fx_proof', 'Bukti kurs / pembayaran kartu', false)],
            'baggage' => [$r('claim_form', 'Formulir klaim'), $r('pir', 'Property Irregularity Report (PIR) maskapai'),
                          $r('ticket', 'Tiket & baggage tag'), $r('purchase_receipts', 'Kuitansi pembelian barang darurat / barang hilang'),
                          $r('airline_compensation', 'Bukti kompensasi dari maskapai', false)],
            'delay' => [$r('claim_form', 'Formulir klaim'), $r('delay_letter', 'Surat keterangan keterlambatan dari maskapai'),
                        $r('ticket', 'Tiket & boarding pass')],
            'cancellation' => [$r('claim_form', 'Formulir klaim'), $r('cancellation_reason', 'Bukti alasan pembatalan (surat dokter, dll.)'),
                               $r('booking_invoice', 'Invoice tiket / hotel / tur'), $r('refund_statement', 'Bukti refund yang diterima', false)],
            default => [$r('claim_form', 'Formulir klaim'), $r('passport', 'Paspor'), $r('loss_evidence', 'Bukti kerugian')],
        };
    }

    return match ($group) {
        'motor' => [
            $r('claim_form', 'Formulir klaim'),
            $r('police_report', 'Laporan polisi'),
            $r('sim', 'SIM pengemudi'),
            $r('stnk', 'STNK'),
            $r('ktp', 'KTP tertanggung'),
            $r('damage_photos', 'Foto kerusakan'),
            $r('workshop_estimate', 'Estimasi biaya bengkel'),
        ],
        'fire' => [
            $r('claim_form', 'Formulir klaim'),
            $r('fire_report', 'Berita acara kebakaran / laporan polisi'),
            $r('ktp', 'KTP tertanggung'),
            $r('damage_photos', 'Foto kerusakan'),
            $r('loss_calculation', 'Rincian perhitungan kerugian'),
            $r('ownership_proof', 'Bukti kepemilikan / invoice', false),
        ],
        'cargo' => [
            $r('claim_form', 'Formulir klaim'),
            $r('bl_awb', 'Bill of Lading / Airway Bill'),
            $r('invoice_packing', 'Commercial invoice & packing list'),
            $r('survey_report', 'Laporan survei kerusakan'),
            $r('damage_photos', 'Foto kerusakan'),
            $r('carrier_notice', 'Surat klaim ke pengangkut'),
        ],
        'travel' => [
            $r('claim_form', 'Formulir klaim'),
            $r('passport', 'Paspor'),
            $r('ticket', 'Tiket / itinerary'),
            $r('loss_evidence', 'Laporan medis / bukti kerugian'),
            $r('receipts', 'Kuitansi pengeluaran', false),
        ],
        'pa' => [
            $r('claim_form', 'Formulir klaim'),
            $r('ktp', 'KTP tertanggung'),
            $r('medical_report', 'Laporan medis / surat keterangan dokter'),
            $r('receipts', 'Kuitansi biaya perawatan', false),
            $r('police_report', 'Laporan polisi', false),
        ],
        default => [
            $r('claim_form', 'Formulir klaim'),
            $r('ktp', 'KTP tertanggung'),
            $r('loss_evidence', 'Bukti kerugian / foto'),
        ],
    };
}

/** Requirement keys a claim of this product accepts on upload. */
function claimRequirementKeys(string $product_type, string $cause = ''): array {
    return array_column(claimRequirements($product_type, $cause), 'key');
}

/**
 * Checklist with a `provided` flag per item, given the requirement keys that have at
 * least one (non-deleted) document.
 *
 * @param string[] $providedKeys
 */
function claimChecklist(string $product_type, array $providedKeys, string $cause = ''): array {
    return array_map(
        fn($item) => $item + ['provided' => in_array($item['key'], $providedKeys, true)],
        claimRequirements($product_type, $cause)
    );
}

/** [required items, required items provided] for a product given the provided keys. */
function claimRequiredProgress(string $product_type, array $providedKeys, string $cause = ''): array {
    $required = array_filter(claimRequirements($product_type, $cause), fn($i) => $i['required']);
    $done     = array_filter($required, fn($i) => in_array($i['key'], $providedKeys, true));
    return [count($required), count($done)];
}

/** Provided requirement keys for many claims at once: claim_id => string[]. */
function claimProvidedKeysFor($conn, string $company_id, array $claim_ids): array {
    if (!$claim_ids) return [];
    $in  = "'" . implode("','", array_map(fn($id) => mysqli_real_escape_string($conn, $id), $claim_ids)) . "'";
    $res = mysqli_query($conn, "SELECT DISTINCT claim_id, claim_requirement FROM " . APP_SCHEMA . ".documents
        WHERE company_id = '$company_id' AND is_deleted = 0 AND claim_requirement IS NOT NULL AND claim_id IN ($in)");
    if (!$res) {
        throw new RuntimeException('claimProvidedKeysFor: ' . mysqli_error($conn));
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) $out[$row['claim_id']][] = $row['claim_requirement'];
    return $out;
}

/**
 * Number of claims still collecting documents (reported / survey) whose required
 * checklist items are not all on file. The $company_id must already be escaped.
 */
function claimsPendingDocumentsCount($conn, string $company_id): int {
    $statuses = "'" . implode("','", CLAIM_COLLECTING_STATUSES) . "'";
    $res = mysqli_query($conn, "SELECT c.claim_id, c.cause, p.product_type
        FROM " . APP_SCHEMA . ".claims c
        JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = c.policy_id
        WHERE c.company_id = '$company_id' AND c.is_deleted = 0 AND c.status IN ($statuses)");
    if (!$res) {
        throw new RuntimeException('claimsPendingDocumentsCount: ' . mysqli_error($conn));
    }
    $claims = mysqli_fetch_all($res, MYSQLI_ASSOC);
    $provided = claimProvidedKeysFor($conn, $company_id, array_column($claims, 'claim_id'));

    $pending = 0;
    foreach ($claims as $c) {
        [$required, $done] = claimRequiredProgress(claimCanonicalProduct($conn, $company_id, $c['product_type']), $provided[$c['claim_id']] ?? [], (string)$c['cause']);
        if ($done < $required) $pending++;
    }
    return $pending;
}

/** Append one event to claim_events. Throws on failure: the timeline is part of the record. */
function insertClaimEvent(
    $conn,
    string $claim_id,
    string $company_id,
    string $event_type,
    ?string $description,
    string $username,
    ?string $from_status = null,
    ?string $to_status = null,
    ?string $channel = null,
    ?string $event_date = null
): void {
    $event_id = 'cev_' . bin2hex(random_bytes(10));
    $esc  = fn(?string $v) => $v === null || $v === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $v) . "'";
    $date = $event_date ?? date('Y-m-d');

    $ok = mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".claim_events
        (event_id, claim_id, company_id, event_type, from_status, to_status, channel, description, event_date, created_by, created_at)
        VALUES
        ('$event_id', '" . mysqli_real_escape_string($conn, $claim_id) . "', '$company_id', '$event_type',
         {$esc($from_status)}, {$esc($to_status)}, {$esc($channel)}, {$esc($description)}, '$date',
         '" . mysqli_real_escape_string($conn, $username) . "', '" . date('Y-m-d H:i:s') . "')");
    if (!$ok) {
        throw new RuntimeException('insertClaimEvent: ' . mysqli_error($conn));
    }
}

// ── [v2.1.0] Settlement calculation ──────────────────────────────────────────

/**
 * The deductible a set of policy terms gives for a loss. Each deductible term is worked out
 * (fixed amount; % of the claim with its minimum; % of the sum insured with its minimum) and the
 * highest applies, the usual reading when several are printed. Returns [amount, the term used or null].
 */
function claimDeductibleFromTerms(array $terms, int $loss, int $sum_insured): array {
    $best = 0;
    $used = null;
    foreach ($terms as $t) {
        if (($t['term_type'] ?? '') !== 'deductible') continue;
        $min = (int)($t['deductible_amount'] ?? 0);
        $pct = (float)($t['deductible_percent'] ?? 0);
        $amount = match ($t['deductible_type'] ?? '') {
            'fixed'               => $min,
            'percent_claim'       => max((int)round($loss * $pct / 100), $min),
            'percent_sum_insured' => max((int)round($sum_insured * $pct / 100), $min),
            default               => 0,
        };
        if ($amount > $best) { $best = $amount; $used = $t; }
    }
    return [$best, $used];
}

/**
 * Settlement of a loss: gross loss → average (sum insured ÷ value at risk when underinsured and the average
 * applies) → deductible → salvage kept by the insured → net, capped at the sum insured.
 */
function claimSettlement(int $gross, int $sum_insured, ?int $value_at_risk, bool $average_applies, array $terms,
                         int $manual_deductible, int $salvage): array {
    $factor = 1.0;
    if ($average_applies && $value_at_risk !== null && $value_at_risk > $sum_insured && $value_at_risk > 0) {
        $factor = $sum_insured / $value_at_risk;
    }
    $after_average = (int)round($gross * $factor);
    [$suggested, $term] = claimDeductibleFromTerms($terms, $after_average, $sum_insured);
    $deductible = $manual_deductible > 0 ? $manual_deductible : $suggested;
    $net = max(0, $after_average - $deductible - $salvage);
    if ($sum_insured > 0) $net = min($net, $sum_insured);
    return [
        'gross_loss' => $gross, 'sum_insured' => $sum_insured, 'value_at_risk' => $value_at_risk,
        'average_applies' => $average_applies, 'average_factor' => round($factor, 4),
        'underinsurance_cut' => $gross - $after_average, 'after_average' => $after_average,
        'deductible_suggested' => $suggested, 'deductible_term' => $term ? ($term['title'] ?? null) : null,
        'deductible_used' => $deductible, 'deductible_source' => $manual_deductible > 0 ? 'claim' : ($term ? 'policy_terms' : 'none'),
        'salvage' => $salvage, 'net_payable' => $net,
    ];
}

/** Status of each claim deadline: met / overdue / due (with days left) / not set. */
function claimDeadlines(array $c): array {
    $today = date('Y-m-d');
    $closed = in_array($c['status'], ['paid', 'rejected', 'withdrawn'], true);
    $past = fn(array $states) => in_array($c['status'], $states, true);
    $defs = [
        'notice_deadline' => ['Batas lapor ke asuransi', $c['reported_date'] !== null && $c['notice_deadline'] !== null && $c['reported_date'] <= $c['notice_deadline']],
        'docs_deadline'   => ['Batas dokumen lengkap', $past(['documents_complete', 'approved', 'partially_paid', 'paid'])],
        'decision_due'    => ['Batas keputusan asuransi', $past(['approved', 'partially_paid', 'paid', 'rejected', 'appeal'])],
        'payment_due'     => ['Batas pembayaran klaim', $past(['paid'])],
    ];
    $out = [];
    foreach ($defs as $key => [$label, $met]) {
        $date = $c[$key] ?? null;
        $days = $date ? (int)(new DateTime($today))->diff(new DateTime($date))->format('%r%a') : null;
        $state = $date === null ? 'not_set' : ($met ? 'met' : ($closed ? 'closed' : ($days < 0 ? 'overdue' : 'due')));
        $out[] = ['key' => $key, 'label' => $label, 'date' => $date, 'days_left' => $days, 'state' => $state];
    }
    return $out;
}

