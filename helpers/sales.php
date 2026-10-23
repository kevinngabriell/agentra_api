<?php

// Shared rules for prospects and quotes (migration_prospects_quotes.sql): stages, lost reasons,
// and strict value parsers used by prospects/index.php and quotes/index.php.

const PROSPECT_TYPES    = ['lead', 'competitor_policy'];
const PROSPECT_STAGES   = ['new', 'contacted', 'quoted', 'negotiating', 'won', 'lost'];
const PROSPECT_OPEN_STAGES = ['new', 'contacted', 'quoted', 'negotiating'];
const PROSPECT_SOURCES  = ['direct', 'referral', 'website', 'event', 'other'];
const PROSPECT_LOST_REASONS = ['price', 'competitor', 'no_longer_needed', 'no_response', 'other'];

const QUOTE_STATUSES = ['draft', 'sent', 'accepted', 'declined'];
// Allowed moves. A declined quote can be reopened as a draft (customer came back with new terms).
const QUOTE_TRANSITIONS = [
    'draft'    => ['sent'],
    'sent'     => ['draft', 'declined'],
    'accepted' => [],
    'declined' => ['draft'],
];
const QUOTE_MAX_OPTIONS = 6;

function salesText(mixed $value, string $field, int $max): ?string {
    if ($value === null) return null;
    if (!is_scalar($value) || is_bool($value)) throw ApiException::from('ERR_SALES_003', ['field' => $field]);
    $s = trim((string)$value);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) throw ApiException::from('ERR_SALES_004', ['field' => $field, 'max' => $max]);
    return $s;
}

function salesRequiredText(mixed $value, string $field, int $max): string {
    $s = salesText($value, $field, $max);
    if ($s === null) throw ApiException::from('ERR_SALES_005', ['field' => $field]);
    return $s;
}

function salesMoney(mixed $value, string $field): int {
    if (is_int($value)) {
        $n = $value;
    } elseif (is_float($value) && floor($value) === $value) {
        $n = (int)$value;
    } elseif (is_string($value) && preg_match('/^\d{1,13}$/', trim($value))) {
        $n = (int)trim($value);
    } else {
        throw ApiException::from('ERR_SALES_006', ['field' => $field]);
    }
    if ($n < 0 || $n > 9_999_999_999_999) throw ApiException::from('ERR_SALES_006', ['field' => $field]);
    return $n;
}

function salesDate(mixed $value, string $field): ?string {
    if ($value === null || $value === '') return null;
    $s = is_string($value) ? trim($value) : '';
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    $errors = DateTime::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $d->format('Y-m-d') !== $s) {
        throw ApiException::from('ERR_SALES_007', ['field' => $field]);
    }
    return $s;
}

function salesEnum(mixed $value, array $allowed, string $field): string {
    $s = is_string($value) ? strtolower(trim($value)) : '';
    if (!in_array($s, $allowed, true)) throw ApiException::from('ERR_SALES_003', ['field' => $field]);
    return $s;
}

/** Quoted SQL literal, or NULL. */
function salesSql($conn, mixed $v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return "'" . mysqli_real_escape_string($conn, (string)$v) . "'";
}

function salesPaginate(int $total, int $page, int $limit): array {
    return ['total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => (int)ceil($total / $limit)];
}

/** Next QT-/ number for a company. Soft-deleted quotes are counted so a number is never reused. */
function nextQuoteNumber($conn, string $company_id): string {
    $prefix = 'QT-' . date('Y') . '-';
    $res = mysqli_query($conn, "SELECT COALESCE(MAX(CAST(SUBSTRING(quote_number, " . (strlen($prefix) + 1) . ") AS UNSIGNED)), 0) AS n
        FROM " . APP_SCHEMA . ".quotes WHERE company_id = '$company_id' AND quote_number LIKE '$prefix%'");
    return $prefix . str_pad((string)((int)mysqli_fetch_assoc($res)['n'] + 1), 4, '0', STR_PAD_LEFT);
}
