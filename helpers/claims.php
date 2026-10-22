<?php

// Shared claim rules: status flow, the per-product document checklist, and the
// "pending documents" check used by both the claims module and the dashboard.

const CLAIM_STATUSES = ['reported', 'survey', 'documents_complete', 'approved', 'rejected', 'paid'];

// Statuses in which the claim is still being worked on.
const CLAIM_OPEN_STATUSES = ['reported', 'survey', 'documents_complete', 'approved'];

// Statuses in which the agent is still collecting documents.
const CLAIM_COLLECTING_STATUSES = ['reported', 'survey'];

// Allowed moves. Survey is optional; a rejected claim can be reopened (appeal / new documents).
const CLAIM_TRANSITIONS = [
    'reported'           => ['survey', 'documents_complete', 'rejected'],
    'survey'             => ['documents_complete', 'rejected'],
    'documents_complete' => ['approved', 'rejected'],
    'approved'           => ['paid'],
    'rejected'           => ['documents_complete'],
    'paid'               => [],
];

const CLAIM_STATUS_LABELS = [
    'reported'           => 'Dilaporkan',
    'survey'             => 'Survei',
    'documents_complete' => 'Dokumen Lengkap',
    'approved'           => 'Disetujui',
    'rejected'           => 'Ditolak',
    'paid'               => 'Dibayar',
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

/** @return array<int, array{key: string, label: string, required: bool}> */
function claimRequirements(string $product_type): array {
    $r = fn(string $key, string $label, bool $required = true) => ['key' => $key, 'label' => $label, 'required' => $required];

    return match (claimProductGroup($product_type)) {
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
function claimRequirementKeys(string $product_type): array {
    return array_column(claimRequirements($product_type), 'key');
}

/**
 * Checklist with a `provided` flag per item, given the requirement keys that have at
 * least one (non-deleted) document.
 *
 * @param string[] $providedKeys
 */
function claimChecklist(string $product_type, array $providedKeys): array {
    return array_map(
        fn($item) => $item + ['provided' => in_array($item['key'], $providedKeys, true)],
        claimRequirements($product_type)
    );
}

/** [required items, required items provided] for a product given the provided keys. */
function claimRequiredProgress(string $product_type, array $providedKeys): array {
    $required = array_filter(claimRequirements($product_type), fn($i) => $i['required']);
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
    $res = mysqli_query($conn, "SELECT c.claim_id, p.product_type
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
        [$required, $done] = claimRequiredProgress($c['product_type'], $provided[$c['claim_id']] ?? []);
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
