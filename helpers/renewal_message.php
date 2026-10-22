<?php
// [NEW v1.1] Shared helpers for renewal WhatsApp messaging.

const DEFAULT_RENEWAL_WA_TEMPLATE =
    "Halo {customer_name}, polis {product_type} Anda nomor {policy_number} akan berakhir pada {coverage_end} " .
    "({days_until_expiry} hari lagi). Mohon konfirmasi apakah Bapak/Ibu berkenan melakukan perpanjangan. Terima kasih.";

// Fills {placeholder} tokens in a message template from a policy/customer row.
// Expected keys in $row: customer_name, policy_number, product_type, insurer_name, coverage_end, days_until_expiry.
function renderRenewalMessage(string $template, array $row): string {
    $daysLeft = isset($row['days_until_expiry']) ? (string)(int)$row['days_until_expiry'] : '-';
    // Guard against strtotime(false) returning "01/01/1970" for null / malformed / "0000-00-00" dates.
    $coverageEnd = '-';
    if (!empty($row['coverage_end']) && $row['coverage_end'] !== '0000-00-00') {
        $ts = strtotime($row['coverage_end']);
        if ($ts !== false) $coverageEnd = date('d/m/Y', $ts);
    }

    // Sanitize fields that flow into outbound WhatsApp messages — strip URLs
    // so a malicious customer_name can't turn the reminder into a phishing relay.
    $sanitize = static function ($v) {
        if (!is_string($v)) return '-';
        return preg_replace('#https?://\S+#i', '[link]', $v);
    };

    $replacements = [
        '{customer_name}'      => $sanitize($row['customer_name']      ?? '-'),
        '{policy_number}'      => $sanitize($row['policy_number']      ?? '-'),
        '{product_type}'       => $sanitize($row['product_type']       ?? '-'),
        '{insurer_name}'       => $sanitize($row['insurer_name']       ?? '-'),
        '{coverage_end}'       => $coverageEnd,
        '{days_until_expiry}'  => $daysLeft,
    ];

    return strtr($template, $replacements);
}

// Normalizes a local Indonesian WA number (08xx, +62, 62) to WAHA's chatId format (62xxxx@c.us).
// Returns null if the normalized number isn't a plausible Indonesian mobile number.
function toWaChatId(string $number): ?string {
    $digits = preg_replace('/\D/', '', $number);
    if ($digits === '') return null;

    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (!str_starts_with($digits, '62')) {
        $digits = '62' . $digits;
    }

    // Indonesian mobile numbers are 9–15 digits after the 62 country code,
    // and the subscriber number starts with 8 (e.g. 8xx). Reject obvious junk.
    if (!preg_match('/^62\d{9,15}$/', $digits)) {
        return null;
    }
    if ($digits[2] !== '8') {
        return null;
    }

    return $digits . '@c.us';
}
