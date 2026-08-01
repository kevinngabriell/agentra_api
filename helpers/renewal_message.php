<?php
// [NEW v1.1] Shared helpers for renewal WhatsApp messaging.

const DEFAULT_RENEWAL_WA_TEMPLATE =
    "Halo {customer_name}, polis {product_type} Anda nomor {policy_number} akan berakhir pada {coverage_end} " .
    "({days_until_expiry} hari lagi). Mohon konfirmasi apakah Bapak/Ibu berkenan melakukan perpanjangan. Terima kasih.";

// Fills {placeholder} tokens in a message template from a policy/customer row.
// Expected keys in $row: customer_name, policy_number, product_type, insurer_name, coverage_end, days_until_expiry.
function renderRenewalMessage(string $template, array $row): string {
    $daysLeft = isset($row['days_until_expiry']) ? (string)(int)$row['days_until_expiry'] : '-';
    $coverageEnd = !empty($row['coverage_end']) ? date('d/m/Y', strtotime($row['coverage_end'])) : '-';

    $replacements = [
        '{customer_name}'      => $row['customer_name']      ?? '-',
        '{policy_number}'      => $row['policy_number']      ?? '-',
        '{product_type}'       => $row['product_type']       ?? '-',
        '{insurer_name}'       => $row['insurer_name']       ?? '-',
        '{coverage_end}'       => $coverageEnd,
        '{days_until_expiry}'  => $daysLeft,
    ];

    return strtr($template, $replacements);
}

// Normalizes a local Indonesian WA number (08xx, +62, 62) to WAHA's chatId format (62xxxx@c.us).
function toWaChatId(string $number): ?string {
    $digits = preg_replace('/\D/', '', $number);
    if ($digits === '') return null;

    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (!str_starts_with($digits, '62')) {
        $digits = '62' . $digits;
    }

    return $digits . '@c.us';
}
