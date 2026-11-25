<?php

// [v2.1.0] Occupation codes and regulator tariff bands, maintained per company, and a rate check.
// No tariff figures ship with the app: the agent enters the bands from the regulator's circular
// (property and motor tariffs, earthquake zones), so a warning is only as current as that table.
//
//   GET    /tariffs/occupations?search=        list / search occupation codes
//   POST   /tariffs/occupations                add one        PUT|DELETE /tariffs/occupations/{id}
//   POST   /tariffs/occupations/import         {rows: [{code, description, tariff_class, notes}]} upsert by code
//   GET    /tariffs/bands?tariff_type=         list bands      POST /tariffs/bands   PUT|DELETE /tariffs/bands/{id}
//   GET    /tariffs/check                      ?type=property&occupation_code=&construction_class=&rate_permille=
//                                              ?type=earthquake&eq_zone=&rate_permille=
//                                              ?type=motor&vehicle_value=&tariff_region=&coverage=comprehensive|tlo&rate_percent=

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/db.php';

const TARIFF_TYPES = ['property', 'earthquake', 'motor'];
const OCCUPATION_IMPORT_MAX = 2000;

/** Motor tariff category from the vehicle value (comprehensive categories of the motor tariff). */
function motorTariffCategory(int $value): int {
    return match (true) {
        $value <= 125_000_000 => 1,
        $value <= 200_000_000 => 2,
        $value <= 400_000_000 => 3,
        $value <= 800_000_000 => 4,
        default               => 5,
    };
}

function tariffBandKey(array $q): ?string {
    return match ($q['type'] ?? '') {
        'property'   => isset($q['occupation_code']) && $q['occupation_code'] !== ''
            ? strtoupper(trim((string)$q['occupation_code'])) . ':' . strtoupper(trim((string)($q['construction_class'] ?? ''))) : null,
        'earthquake' => isset($q['eq_zone']) && $q['eq_zone'] !== '' ? 'zone:' . (int)$q['eq_zone'] : null,
        'motor'      => isset($q['vehicle_value'], $q['tariff_region']) && $q['vehicle_value'] !== '' && $q['tariff_region'] !== ''
            ? motorTariffCategory((int)$q['vehicle_value']) . ':' . (int)$q['tariff_region'] . ':' . (($q['coverage'] ?? 'comprehensive') === 'tlo' ? 'tlo' : 'comprehensive') : null,
        default      => null,
    };
}

/** within | below | above | no_band, comparing a permille rate to a band. */
function tariffVerdict(?array $band, float $rate): string {
    if (!$band) return 'no_band';
    if ($band['rate_min'] !== null && $rate < (float)$band['rate_min'] - 0.00001) return 'below';
    if ($band['rate_max'] !== null && $rate > (float)$band['rate_max'] + 0.00001) return 'above';
    return 'within';
}

function checkTariff($conn, string $company_id, array $q): void {
    $type = inEnum($q, 'type', TARIFF_TYPES, true);
    $key = tariffBandKey($q);
    if ($key === null) throw ApiException::from('ERR_V21_002', ['field' => 'band key']);
    $rate = $type === 'motor' ? (float)($q['rate_percent'] ?? 0) * 10 : (float)($q['rate_permille'] ?? 0);
    $band = dbOne($conn, "SELECT * FROM {app}.master_tariff_bands WHERE company_id = ? AND tariff_type = ? AND band_key = ?", [$company_id, $type, $key]);
    // Property bands may be set for a whole occupation class instead of each code: fall back to <class>:<construction>.
    if (!$band && $type === 'property') {
        $class = dbValue($conn, "SELECT tariff_class FROM {app}.master_occupations WHERE company_id = ? AND code = ?", [$company_id, strtoupper(trim((string)$q['occupation_code']))]);
        if ($class) {
            $key = strtoupper($class) . ':' . strtoupper(trim((string)($q['construction_class'] ?? '')));
            $band = dbOne($conn, "SELECT * FROM {app}.master_tariff_bands WHERE company_id = ? AND tariff_type = 'property' AND band_key = ?", [$company_id, $key]);
        }
    }
    $verdict = tariffVerdict($band, $rate);
    jsonResponse(200, 'Tariff check', [
        'type' => $type, 'band_key' => $key, 'rate_permille' => round($rate, 4), 'verdict' => $verdict,
        'band' => $band ? ['rate_min' => $band['rate_min'] === null ? null : (float)$band['rate_min'],
                           'rate_max' => $band['rate_max'] === null ? null : (float)$band['rate_max'],
                           'discount_max_percent' => $band['discount_max_percent'] === null ? null : (float)$band['discount_max_percent'],
                           'description' => $band['description']] : null,
        'motor_category' => $type === 'motor' ? motorTariffCategory((int)($q['vehicle_value'] ?? 0)) : null,
    ]);
}

function occupationParsers(): array {
    return [
        'code' => fn($i) => strtoupper(inText($i, 'code', 40, true)),
        'description' => fn($i) => inText($i, 'description', 300, true),
        'tariff_class' => fn($i) => inText($i, 'tariff_class', 40),
        'notes' => fn($i) => inText($i, 'notes', 300),
    ];
}

function listOccupations($conn, string $company_id, array $q): void {
    $s = trim((string)($q['search'] ?? ''));
    $rows = $s === ''
        ? dbAll($conn, "SELECT * FROM {app}.master_occupations WHERE company_id = ? ORDER BY code LIMIT 1000", [$company_id])
        : dbAll($conn, "SELECT * FROM {app}.master_occupations WHERE company_id = ? AND (code LIKE ? OR description LIKE ?) ORDER BY code LIMIT 50",
            [$company_id, "%$s%", "%$s%"]);
    jsonResponse(200, 'Occupations', ['items' => $rows]);
}

function saveOccupation($conn, string $company_id, ?string $id, array $in, string $username): void {
    $parsers = occupationParsers();
    $set = $id === null ? array_map(fn($p) => $p($in), $parsers) : inPresent($in, $parsers);
    if (isset($set['code'])) {
        $dup = dbValue($conn, "SELECT occupation_id FROM {app}.master_occupations WHERE company_id = ? AND code = ?" . ($id ? ' AND occupation_id <> ?' : ''),
            $id ? [$company_id, $set['code'], $id] : [$company_id, $set['code']]);
        if ($dup) throw ApiException::from('ERR_V21_017', ['code' => $set['code']]);
    }
    if ($id === null) {
        $id = dbNewId('occ');
        dbInsert($conn, 'master_occupations', $set + ['occupation_id' => $id, 'company_id' => $company_id, 'created_by' => $username]);
    } else {
        if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
        if (!dbOne($conn, "SELECT 1 FROM {app}.master_occupations WHERE occupation_id = ? AND company_id = ?", [$id, $company_id])) throw ApiException::from('ERR_V21_004');
        dbUpdate($conn, 'master_occupations', $set, ['occupation_id' => $id, 'company_id' => $company_id]);
    }
    jsonResponse(200, 'Occupation saved', dbOne($conn, "SELECT * FROM {app}.master_occupations WHERE occupation_id = ?", [$id]));
}

function importOccupations($conn, string $company_id, array $in, string $username): void {
    $rows = $in['rows'] ?? null;
    if (!is_array($rows) || !$rows) throw ApiException::from('ERR_V21_002', ['field' => 'rows']);
    if (count($rows) > OCCUPATION_IMPORT_MAX) throw ApiException::from('ERR_V21_012', ['max' => OCCUPATION_IMPORT_MAX]);
    $n = dbTransaction($conn, function () use ($conn, $company_id, $rows, $username) {
        $n = 0;
        foreach (array_values($rows) as $i => $r) {
            try {
                $set = array_map(fn($p) => $p(is_array($r) ? $r : []), occupationParsers());
            } catch (ApiException $e) {
                throw new ApiException($e->errCode, $e->httpStatus, 'Baris ' . ($i + 1) . ': ' . $e->getMessage(), $e->details);
            }
            dbExec($conn, "INSERT INTO {app}.master_occupations (occupation_id, company_id, code, description, tariff_class, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description), tariff_class = VALUES(tariff_class), notes = VALUES(notes)",
                [dbNewId('occ'), $company_id, $set['code'], $set['description'], $set['tariff_class'], $set['notes'], $username]);
            $n++;
        }
        return $n;
    });
    jsonResponse(200, 'Occupations imported', ['imported' => $n]);
}

function bandParsers(): array {
    return [
        'tariff_type' => fn($i) => inEnum($i, 'tariff_type', TARIFF_TYPES, true),
        // Matched case-insensitively (utf8mb4_unicode_ci), so "1:2:TLO" and "1:2:tlo" are the same band.
        'band_key' => fn($i) => preg_replace('/\s+/', '', inText($i, 'band_key', 100, true)),
        'description' => fn($i) => inText($i, 'description', 300),
        'rate_min' => fn($i) => inNumber($i, 'rate_min', 0, 1000),
        'rate_max' => fn($i) => inNumber($i, 'rate_max', 0, 1000),
        'discount_max_percent' => fn($i) => inNumber($i, 'discount_max_percent', 0, 100),
    ];
}

function listBands($conn, string $company_id, array $q): void {
    $type = inEnum($q, 'tariff_type', TARIFF_TYPES);
    $rows = $type
        ? dbAll($conn, "SELECT * FROM {app}.master_tariff_bands WHERE company_id = ? AND tariff_type = ? ORDER BY band_key", [$company_id, $type])
        : dbAll($conn, "SELECT * FROM {app}.master_tariff_bands WHERE company_id = ? ORDER BY tariff_type, band_key", [$company_id]);
    foreach ($rows as &$r) foreach (['rate_min', 'rate_max', 'discount_max_percent'] as $k) $r[$k] = $r[$k] === null ? null : (float)$r[$k];
    unset($r);
    jsonResponse(200, 'Tariff bands', ['items' => $rows]);
}

function saveBand($conn, string $company_id, ?string $id, array $in, string $username): void {
    $parsers = bandParsers();
    $set = $id === null ? array_map(fn($p) => $p($in), $parsers) : inPresent($in, $parsers);
    $min = $set['rate_min'] ?? null; $max = $set['rate_max'] ?? null;
    if ($min !== null && $max !== null && $min > $max) throw ApiException::from('ERR_V21_001', ['field' => 'rate_min']);
    try {
        if ($id === null) {
            $id = dbNewId('tbd');
            dbInsert($conn, 'master_tariff_bands', $set + ['band_id' => $id, 'company_id' => $company_id, 'created_by' => $username]);
        } else {
            if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
            if (!dbOne($conn, "SELECT 1 FROM {app}.master_tariff_bands WHERE band_id = ? AND company_id = ?", [$id, $company_id])) throw ApiException::from('ERR_V21_004');
            dbUpdate($conn, 'master_tariff_bands', $set, ['band_id' => $id, 'company_id' => $company_id]);
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) throw ApiException::from('ERR_V21_017', ['code' => $set['band_key'] ?? '']);
        throw $e;
    }
    jsonResponse(200, 'Tariff band saved', dbOne($conn, "SELECT * FROM {app}.master_tariff_bands WHERE band_id = ?", [$id]));
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? '';
if (!$company_id) jsonResponse(400, 'company_id is required');
$id = $parts[4] ?? '';

try {
    $conn = getConn();
    if ($action === 'check') {
        if ($method !== 'GET') Response::methodNotAllowed();
        checkTariff($conn, $company_id, $_GET);
    } elseif ($action === 'occupations') {
        if ($id === '' && $method === 'GET') listOccupations($conn, $company_id, $_GET);
        elseif ($id === '' && $method === 'POST') saveOccupation($conn, $company_id, null, input(), $username);
        elseif ($id === 'import' && $method === 'POST') importOccupations($conn, $company_id, input(), $username);
        elseif ($id !== '' && $method === 'PUT') saveOccupation($conn, $company_id, $id, input(), $username);
        elseif ($id !== '' && $method === 'DELETE') {
            if (dbExec($conn, "DELETE FROM {app}.master_occupations WHERE occupation_id = ? AND company_id = ?", [$id, $company_id]) === 0) throw ApiException::from('ERR_V21_004');
            jsonResponse(200, 'Occupation deleted');
        } else Response::methodNotAllowed();
    } elseif ($action === 'bands') {
        if ($id === '' && $method === 'GET') listBands($conn, $company_id, $_GET);
        elseif ($id === '' && $method === 'POST') saveBand($conn, $company_id, null, input(), $username);
        elseif ($id !== '' && $method === 'PUT') saveBand($conn, $company_id, $id, input(), $username);
        elseif ($id !== '' && $method === 'DELETE') {
            if (dbExec($conn, "DELETE FROM {app}.master_tariff_bands WHERE band_id = ? AND company_id = ?", [$id, $company_id]) === 0) throw ApiException::from('ERR_V21_004');
            jsonResponse(200, 'Tariff band deleted');
        } else Response::methodNotAllowed();
    } else {
        jsonResponse(404, 'Route not found');
    }
} catch (ApiException $e) {
    throw $e;
} catch (\Throwable $e) {
    RequestLogger::setException($e);
    error_log($e->getMessage());
    Response::internalError();
}
