<?php

// Prepared-statement helpers for the v2.1.0 modules. Every value is bound, never spliced, so the
// new code does not depend on hand escaping. Types are inferred: int → i, float → d, else s.
// APP_SCHEMA is spliced as `{app}` in the SQL text.

function dbSql(string $sql): string {
    return str_replace('{app}', APP_SCHEMA, $sql);
}

function dbStmt($conn, string $sql, array $params): mysqli_stmt {
    $stmt = mysqli_prepare($conn, dbSql($sql));
    if (!$stmt) throw new RuntimeException('dbStmt prepare: ' . mysqli_error($conn) . ' — ' . $sql);
    if ($params) {
        $types = '';
        $vals  = [];
        foreach ($params as $p) {
            if (is_bool($p)) { $types .= 'i'; $vals[] = $p ? 1 : 0; }
            elseif (is_int($p)) { $types .= 'i'; $vals[] = $p; }
            elseif (is_float($p)) { $types .= 'd'; $vals[] = $p; }
            else { $types .= 's'; $vals[] = $p === null ? null : (string)$p; }
        }
        mysqli_stmt_bind_param($stmt, $types, ...$vals);
    }
    if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('dbStmt execute: ' . mysqli_stmt_error($stmt));
    return $stmt;
}

/** All rows as associative arrays. */
function dbAll($conn, string $sql, array $params = []): array {
    $stmt = dbStmt($conn, $sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function dbOne($conn, string $sql, array $params = []): ?array {
    return dbAll($conn, $sql, $params)[0] ?? null;
}

/** Scalar of the first column of the first row, or null. */
function dbValue($conn, string $sql, array $params = []): mixed {
    $row = dbOne($conn, $sql, $params);
    return $row === null ? null : array_values($row)[0];
}

/** Runs a write; returns affected rows. */
function dbExec($conn, string $sql, array $params = []): int {
    $stmt = dbStmt($conn, $sql, $params);
    $n = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $n;
}

/** Inserts one row from a column => value map. */
function dbInsert($conn, string $table, array $row): void {
    $cols = array_keys($row);
    $sql  = 'INSERT INTO {app}.' . $table . ' (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    dbExec($conn, $sql, array_values($row));
}

/** Updates columns of the rows matching $where (column => value, AND-ed). Returns affected rows. */
function dbUpdate($conn, string $table, array $set, array $where): int {
    if (!$set) return 0;
    $sql = 'UPDATE {app}.' . $table . ' SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set)))
         . ' WHERE ' . implode(' AND ', array_map(fn($c) => "`$c` = ?", array_keys($where)));
    return dbExec($conn, $sql, [...array_values($set), ...array_values($where)]);
}

function dbNewId(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(10));
}

/** Runs $fn inside a transaction; rolls back and rethrows on any error. */
function dbTransaction($conn, callable $fn): mixed {
    mysqli_begin_transaction($conn);
    try {
        $out = $fn();
        mysqli_commit($conn);
        return $out;
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}

// ── Input parsing shared by the v2.1.0 modules ───────────────────────────────
// Each throws ERR_V21_001 (invalid value for {field}) on bad input.

function inText(array $in, string $field, int $max, bool $required = false): ?string {
    $v = $in[$field] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        if ($required) throw ApiException::from('ERR_V21_002', ['field' => $field]);
        return null;
    }
    if (!is_scalar($v) || is_bool($v)) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    $s = trim((string)$v);
    if (mb_strlen($s) > $max) throw ApiException::from('ERR_V21_003', ['field' => $field, 'max' => $max]);
    return $s;
}

function inDate(array $in, string $field, bool $required = false): ?string {
    $v = $in[$field] ?? null;
    if ($v === null || $v === '') {
        if ($required) throw ApiException::from('ERR_V21_002', ['field' => $field]);
        return null;
    }
    $s = is_string($v) ? trim($v) : '';
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    return $s;
}

/** Whole rupiah (or other integer amount). $signed allows negatives. */
function inMoney(array $in, string $field, bool $required = false, bool $signed = false): ?int {
    $v = $in[$field] ?? null;
    if ($v === null || $v === '') {
        if ($required) throw ApiException::from('ERR_V21_002', ['field' => $field]);
        return null;
    }
    if (is_float($v) && floor($v) === $v) $v = (int)$v;
    if (is_string($v)) $v = trim($v);
    if (!(is_int($v) || (is_string($v) && preg_match($signed ? '/^-?\d{1,14}$/' : '/^\d{1,14}$/', $v)))) {
        throw ApiException::from('ERR_V21_001', ['field' => $field]);
    }
    $n = (int)$v;
    if (!$signed && $n < 0) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    if (abs($n) > 99_999_999_999_999) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    return $n;
}

function inNumber(array $in, string $field, float $min, float $max, bool $required = false): ?float {
    $v = $in[$field] ?? null;
    if ($v === null || $v === '') {
        if ($required) throw ApiException::from('ERR_V21_002', ['field' => $field]);
        return null;
    }
    if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    return (float)$v;
}

function inInt(array $in, string $field, int $min, int $max, bool $required = false): ?int {
    $n = inNumber($in, $field, $min, $max, $required);
    if ($n === null) return null;
    if (floor($n) !== $n) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    return (int)$n;
}

function inEnum(array $in, string $field, array $allowed, bool $required = false): ?string {
    $v = $in[$field] ?? null;
    if ($v === null || $v === '') {
        if ($required) throw ApiException::from('ERR_V21_002', ['field' => $field]);
        return null;
    }
    if (!is_string($v) || !in_array($v, $allowed, true)) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    return $v;
}

function inBool(array $in, string $field): ?int {
    if (!array_key_exists($field, $in) || $in[$field] === null || $in[$field] === '') return null;
    $b = filter_var($in[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($b === null) throw ApiException::from('ERR_V21_001', ['field' => $field]);
    return $b ? 1 : 0;
}

/** Only the keys present in the request, parsed with the given parser. For partial updates. */
function inPresent(array $in, array $parsers): array {
    $out = [];
    foreach ($parsers as $field => $parse) {
        if (array_key_exists($field, $in)) $out[$field] = $parse($in);
    }
    return $out;
}

const CURRENCIES = ['IDR', 'USD', 'EUR', 'SGD', 'JPY', 'AUD', 'CNY', 'GBP', 'HKD', 'MYR'];

/** Normalised currency fields: ['currency' => 'USD', 'exchange_rate' => 15800.0]. IDR forces a rate of 1. */
function inCurrency(array $in): array {
    $cur = $in['currency'] ?? null;
    if ($cur === null || $cur === '') return [];
    if (!is_string($cur) || !in_array(strtoupper($cur), CURRENCIES, true)) throw ApiException::from('ERR_V21_001', ['field' => 'currency']);
    $cur = strtoupper($cur);
    if ($cur === 'IDR') return ['currency' => 'IDR', 'exchange_rate' => 1.0];
    $rate = inNumber($in, 'exchange_rate', 0.000001, 1_000_000_000, true);
    return ['currency' => $cur, 'exchange_rate' => $rate];
}
