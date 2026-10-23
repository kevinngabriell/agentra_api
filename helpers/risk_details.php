<?php

// Product-specific risk details (migration_policy_risk_details.sql). Pure functions used by
// policies/risk_details.php, policies/declarations.php, POST /policies, renew and policy search.
//
// A policy belongs to one risk group, derived from its product code:
//   motor  car, motorcycle      plate, chassis, engine, vehicle, coverage type, extensions
//   cargo  cargo                conveyance, voyage, B/L or AWB, ICC clause, open cover
//   travel travel               destinations, Schengen certificate, travellers
//   pa     kecelakaan, aep      insured people and beneficiaries
//   fire   fire, or a "01" policy number prefix (same rule as the construction class)
//                               occupation, extensions, survey
// Other products have no risk details.

require_once __DIR__ . '/product_types.php';

const RISK_MOTOR_CODES  = ['car', 'motorcycle', 'motor', 'mobil'];
const RISK_CARGO_CODES  = ['cargo', 'kargo'];
const RISK_TRAVEL_CODES = ['travel', 'perjalanan'];
const RISK_PA_CODES     = ['kecelakaan', 'aep'];
const RISK_FIRE_CODES   = ['fire', 'kebakaran'];

const RISK_MEMBER_ROLES_BY_GROUP = [
    'travel' => ['traveller'],
    'pa'     => ['insured', 'beneficiary'],
];
const RISK_MAX_MEMBERS = 200;

// column => [type, max length | null]. The column set per group is the whitelist for that group.
const RISK_FIELDS = [
    'motor' => [
        'plate_number' => ['plate', 20], 'chassis_number' => ['code', 40], 'engine_number' => ['code', 40],
        'vehicle_make' => ['text', 60], 'vehicle_model' => ['text', 80], 'vehicle_year' => ['year', null],
        'vehicle_color' => ['text', 30], 'vehicle_usage' => ['enum:private,commercial', null],
        'motor_coverage_type' => ['enum:comprehensive,tlo', null], 'vehicle_value' => ['money', null],
        'tariff_region' => ['region', null],
        'ext_flood' => ['bool', null], 'ext_earthquake' => ['bool', null], 'ext_rsmdcc' => ['bool', null], 'ext_terrorism' => ['bool', null],
        'tpl_limit' => ['money', null], 'pa_driver_limit' => ['money', null], 'pa_passenger_limit' => ['money', null],
        'pa_passenger_seats' => ['seats', null],
    ],
    'cargo' => [
        'conveyance_name' => ['text', 120], 'voyage_from' => ['text', 120], 'voyage_to' => ['text', 120],
        'bl_awb_number' => ['code', 60], 'departure_date' => ['date', null], 'icc_clause' => ['enum:A,B,C', null],
        'goods_description' => ['text', 2000], 'is_open_cover' => ['bool', null],
    ],
    'travel' => [
        'destinations' => ['text', 500], 'schengen_certificate_no' => ['code', 60],
    ],
    'pa' => [],
    'fire' => [
        'occupation_code' => ['text', 40], 'occupation_description' => ['text', 200],
        'survey_status' => ['enum:not_required,pending,scheduled,done', null], 'survey_date' => ['date', null],
        'ext_flood' => ['bool', null], 'ext_earthquake' => ['bool', null], 'ext_rsmdcc' => ['bool', null], 'ext_terrorism' => ['bool', null],
    ],
];

const RISK_BOOL_COLUMNS = ['ext_flood', 'ext_earthquake', 'ext_rsmdcc', 'ext_terrorism', 'is_open_cover'];
const RISK_INT_COLUMNS  = ['vehicle_year', 'vehicle_value', 'tariff_region', 'tpl_limit', 'pa_driver_limit', 'pa_passenger_limit', 'pa_passenger_seats'];

/**
 * Risk group for a policy, or null when the product has no risk details. $master_type is the
 * product's master_products.product_type (productTypeOf()); when it names a type with a risk
 * group that wins. Otherwise the legacy code / policy-number-prefix rules below still apply, so
 * unclassified products and "liability" AEP policies behave as before.
 */
function riskGroup(string $product_type, string $policy_number = '', ?string $master_type = null): ?string {
    if ($master_type !== null && isset(PRODUCT_TYPES[$master_type]['risk_group'])) {
        return PRODUCT_TYPES[$master_type]['risk_group'];
    }
    $code = strtolower(trim($product_type));
    if (in_array($code, RISK_MOTOR_CODES, true))  return 'motor';
    if (in_array($code, RISK_CARGO_CODES, true))  return 'cargo';
    if (in_array($code, RISK_TRAVEL_CODES, true)) return 'travel';
    if (in_array($code, RISK_PA_CODES, true))     return 'pa';
    if (in_array($code, RISK_FIRE_CODES, true) || str_starts_with(trim($policy_number), '01')) return 'fire';
    return null;
}

function riskStrictDate(mixed $value, string $field): string {
    $s = is_string($value) ? trim($value) : '';
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    $errors = DateTime::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $d->format('Y-m-d') !== $s) {
        throw ApiException::from('ERR_RISK_004', ['field' => $field]);
    }
    return $s;
}

/** Non-negative whole Rupiah as int. */
function riskMoney(mixed $value, string $field): int {
    if (is_int($value)) {
        $n = $value;
    } elseif (is_string($value) && preg_match('/^\d{1,13}$/', trim($value))) {
        $n = (int)trim($value);
    } else {
        throw ApiException::from('ERR_RISK_005', ['field' => $field]);
    }
    if ($n < 0 || $n > 9_999_999_999_999) throw ApiException::from('ERR_RISK_005', ['field' => $field]);
    return $n;
}

function riskBool(mixed $value, string $field): int {
    if ($value === true || $value === 1 || $value === '1' || $value === 'true') return 1;
    if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === '' || $value === null) return 0;
    throw ApiException::from('ERR_RISK_003', ['field' => $field]);
}

function riskText(mixed $value, string $field, int $max): ?string {
    if ($value === null) return null;
    if (!is_scalar($value) || is_bool($value)) throw ApiException::from('ERR_RISK_003', ['field' => $field]);
    $s = trim((string)$value);
    if ($s === '') return null;
    if (mb_strlen($s) > $max) throw ApiException::from('ERR_RISK_013', ['field' => $field, 'max' => $max]);
    return $s;
}

/** Validates one detail value. Returns null for "not set", else a string or int ready for SQL. */
function riskParseValue(string $type, ?int $max, mixed $value, string $field): string|int|null {
    if ($type === 'bool') return riskBool($value, $field);
    if ($value === null || $value === '') return null;

    if (str_starts_with($type, 'enum:')) {
        $allowed = explode(',', substr($type, 5));
        $s = is_string($value) ? trim($value) : '';
        // ICC clauses are upper-case letters; the other enums are lower-case words.
        $s = $allowed === ['A', 'B', 'C'] ? strtoupper($s) : strtolower($s);
        if (!in_array($s, $allowed, true)) throw ApiException::from('ERR_RISK_003', ['field' => $field]);
        return $s;
    }

    switch ($type) {
        case 'money':
            return riskMoney($value, $field);
        case 'date':
            return riskStrictDate($value, $field);
        case 'year':
            $y = is_numeric($value) ? (int)$value : 0;
            if ($y < 1950 || $y > (int)date('Y') + 1) throw ApiException::from('ERR_RISK_003', ['field' => $field]);
            return $y;
        case 'region':
            $r = is_numeric($value) ? (int)$value : 0;
            if ($r < 1 || $r > 3) throw ApiException::from('ERR_RISK_003', ['field' => $field]);
            return $r;
        case 'seats':
            $n = is_numeric($value) ? (int)$value : 0;
            if ($n < 1 || $n > 60) throw ApiException::from('ERR_RISK_003', ['field' => $field]);
            return $n;
        case 'plate':
        case 'code':
            $s = riskText($value, $field, $max ?? 40);
            if ($s === null) return null;
            // Plates and chassis/engine/B/L numbers: letters, digits, space and dash only; stored upper-case.
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-\/.]*$/', $s)) throw ApiException::from('ERR_RISK_003', ['field' => $field]);
            return strtoupper($s);
        default:
            return riskText($value, $field, $max ?? 255);
    }
}

/**
 * Validates `risk_details` for a group. Returns a column => value map covering EVERY column of the
 * group (unset ones as null / 0), so saving it replaces the previous row completely.
 */
function riskParseDetails(mixed $input, string $group): array {
    if ($input !== null && !is_array($input)) throw ApiException::from('ERR_RISK_003', ['field' => 'risk_details']);
    $input = $input ?? [];

    $out = [];
    foreach (RISK_FIELDS[$group] as $column => [$type, $max]) {
        $out[$column] = riskParseValue($type, $max, $input[$column] ?? null, $column);
    }

    if ($group === 'motor') {
        // A passenger limit needs a seat count to mean anything.
        if ($out['pa_passenger_limit'] !== null && $out['pa_passenger_seats'] === null) {
            throw ApiException::from('ERR_RISK_012', ['field' => 'pa_passenger_seats']);
        }
    }
    return $out;
}

/** Validates `members`. Returns a list of rows ready for riskInsertMembers(). */
function riskParseMembers(mixed $input, string $group): array {
    if (!is_array($input) || count($input) > RISK_MAX_MEMBERS) throw ApiException::from('ERR_RISK_006');
    $roles = RISK_MEMBER_ROLES_BY_GROUP[$group] ?? [];

    $rows = [];
    $share_total = 0.0;
    foreach (array_values($input) as $i => $m) {
        $m = is_array($m) ? $m : [];
        $at = 'members.' . ($i + 1) . '.';

        $role = strtolower(trim((string)($m['member_role'] ?? '')));
        if (!in_array($role, $roles, true)) throw ApiException::from('ERR_RISK_007', ['role' => $role === '' ? '-' : $role]);

        $name = riskText($m['full_name'] ?? null, $at . 'full_name', 150);
        if ($name === null) throw ApiException::from('ERR_RISK_012', ['field' => $at . 'full_name']);

        $row = [
            'member_role' => $role,
            'full_name'   => $name,
            'id_number'   => riskParseValue('code', 40, $m['id_number'] ?? null, $at . 'id_number'),
            'birth_date'  => riskParseValue('date', null, $m['birth_date'] ?? null, $at . 'birth_date'),
            'relationship' => riskText($m['relationship'] ?? null, $at . 'relationship', 60),
            'benefit_death' => null, 'benefit_disability' => null, 'benefit_medical' => null,
            'share_percent' => null,
        ];

        if ($role === 'beneficiary') {
            $share = $m['share_percent'] ?? null;
            if ($share !== null && $share !== '') {
                if (!is_numeric($share) || (float)$share <= 0 || (float)$share > 100) {
                    throw ApiException::from('ERR_RISK_003', ['field' => $at . 'share_percent']);
                }
                $row['share_percent'] = round((float)$share, 2);
                $share_total += $row['share_percent'];
            }
        } else {
            foreach (['benefit_death', 'benefit_disability', 'benefit_medical'] as $b) {
                $row[$b] = riskParseValue('money', null, $m[$b] ?? null, $at . $b);
            }
        }
        $rows[] = $row;
    }

    if ($share_total > 100.001) throw ApiException::from('ERR_RISK_008');
    return $rows;
}

/**
 * Validates the optional `risk_details` / `members` pair of a create or PUT request against the
 * policy's group. Throws ERR_RISK_002 when the product has no risk details. Returns
 * ['group' => ..., 'details' => array|null, 'members' => array|null]; null = not supplied.
 */
function riskParseInput(array $input, string $product_type, string $policy_number, ?string $master_type = null): array {
    $group = riskGroup($product_type, $policy_number, $master_type);
    $has_details = array_key_exists('risk_details', $input);
    $has_members = array_key_exists('members', $input);
    if ($group === null) throw ApiException::from('ERR_RISK_002');

    $members = null;
    if ($has_members) {
        if (!isset(RISK_MEMBER_ROLES_BY_GROUP[$group])) throw ApiException::from('ERR_RISK_002');
        $members = riskParseMembers($input['members'], $group);
    }
    return [
        'group'   => $group,
        'details' => $has_details && RISK_FIELDS[$group] ? riskParseDetails($input['risk_details'], $group) : null,
        'members' => $members,
    ];
}

function riskSqlValue($conn, string|int|float|null $v): string {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return "'" . mysqli_real_escape_string($conn, $v) . "'";
}

function riskFetchDetailsRow($conn, string $policy_id): ?array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT * FROM " . APP_SCHEMA . ".policy_risk_details WHERE policy_id = '$pid' LIMIT 1");
    if (!$res) throw new RuntimeException('riskFetchDetailsRow: ' . mysqli_error($conn));
    $row = mysqli_fetch_assoc($res);
    return $row ?: null;
}

/** Upserts the details row. Caller owns the transaction. Returns false on a DB error. */
function riskUpsertDetails($conn, string $policy_id, string $company_id, array $details, string $username): bool {
    $columns = array_keys($details);
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $company_id);
    $usr = mysqli_real_escape_string($conn, $username);

    $values  = array_map(fn($c) => riskSqlValue($conn, $details[$c]), $columns);
    $updates = array_map(fn($c) => "`$c` = VALUES(`$c`)", $columns);

    return (bool)mysqli_query($conn,
        "INSERT INTO " . APP_SCHEMA . ".policy_risk_details
            (policy_id, company_id, `" . implode('`, `', $columns) . "`, created_by)
         VALUES ('$pid', '$cid', " . implode(', ', $values) . ", '$usr')
         ON DUPLICATE KEY UPDATE " . implode(', ', $updates) . ", updated_by = '$usr'"
    );
}

/** Replaces all members of a policy. Caller owns the transaction. Returns false on a DB error. */
function riskReplaceMembers($conn, string $policy_id, string $company_id, array $members, string $username): bool {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $company_id);
    $usr = mysqli_real_escape_string($conn, $username);

    if (!mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_risk_members WHERE policy_id = '$pid'")) return false;

    foreach ($members as $i => $m) {
        $member_id = 'rmb_' . bin2hex(random_bytes(10));
        $ok = mysqli_query($conn,
            "INSERT INTO " . APP_SCHEMA . ".policy_risk_members
                (member_id, company_id, policy_id, member_role, full_name, id_number, birth_date, relationship,
                 benefit_death, benefit_disability, benefit_medical, share_percent, sort_order, created_by)
             VALUES ('$member_id', '$cid', '$pid', " . riskSqlValue($conn, $m['member_role']) . ", " . riskSqlValue($conn, $m['full_name']) . ", "
                . riskSqlValue($conn, $m['id_number']) . ", " . riskSqlValue($conn, $m['birth_date']) . ", " . riskSqlValue($conn, $m['relationship']) . ", "
                . riskSqlValue($conn, $m['benefit_death']) . ", " . riskSqlValue($conn, $m['benefit_disability']) . ", " . riskSqlValue($conn, $m['benefit_medical']) . ", "
                . riskSqlValue($conn, $m['share_percent']) . ", " . ($i + 1) . ", '$usr')"
        );
        if (!$ok) return false;
    }
    return true;
}

/** Shapes a details row for the API: only the group's own columns, ints and booleans typed. */
function riskShapeDetails(array $row, string $group): array {
    $row = array_intersect_key($row, RISK_FIELDS[$group] + ['updated_at' => true]);
    foreach (RISK_BOOL_COLUMNS as $c) {
        if (array_key_exists($c, $row)) $row[$c] = (bool)(int)$row[$c];
    }
    foreach (RISK_INT_COLUMNS as $c) {
        if (array_key_exists($c, $row) && $row[$c] !== null) $row[$c] = (int)$row[$c];
    }
    return $row;
}

function riskFetchMembers($conn, string $policy_id): array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn,
        "SELECT member_id, member_role, full_name, id_number, birth_date, relationship,
                benefit_death, benefit_disability, benefit_medical, share_percent, sort_order
         FROM " . APP_SCHEMA . ".policy_risk_members WHERE policy_id = '$pid'
         ORDER BY FIELD(member_role, 'traveller', 'insured', 'beneficiary'), sort_order");
    if (!$res) throw new RuntimeException('riskFetchMembers: ' . mysqli_error($conn));
    return array_map(function ($m) {
        foreach (['benefit_death', 'benefit_disability', 'benefit_medical'] as $b) {
            if ($m[$b] !== null) $m[$b] = (int)$m[$b];
        }
        $m['share_percent'] = $m['share_percent'] !== null ? (float)$m['share_percent'] : null;
        unset($m['sort_order']);
        return $m;
    }, mysqli_fetch_all($res, MYSQLI_ASSOC));
}

/** Names of the detail columns whose value differs between the stored row and a new map. */
function riskChangedFields(?array $old, array $new): array {
    $changed = [];
    foreach ($new as $column => $value) {
        $before = $old[$column] ?? null;
        if (in_array($column, RISK_BOOL_COLUMNS, true)) { $before = (int)$before; }
        if ((string)($before ?? '') !== (string)($value ?? '')) $changed[] = $column;
    }
    return $changed;
}

/**
 * SQL condition (no leading AND) matching policies whose plate, chassis, engine or B/L number contains
 * the search text. `$escaped` is already mysqli-escaped. Needs `p` = policies and `r` = policy_risk_details
 * (LEFT JOINed) in the query. Plates ignore spaces and dashes on both sides ("B1234XYZ" finds "B 1234 XYZ").
 */
function riskSearchCondition(string $escaped): string {
    $plate = str_replace([' ', '-'], '', $escaped);
    $conds = [
        "r.chassis_number LIKE '%$escaped%'",
        "r.engine_number LIKE '%$escaped%'",
        "r.bl_awb_number LIKE '%$escaped%'",
        "EXISTS (SELECT 1 FROM " . APP_SCHEMA . ".policy_cargo_declarations d WHERE d.policy_id = p.policy_id AND d.bl_awb_number LIKE '%$escaped%')",
    ];
    if ($plate !== '') {
        $conds[] = "REPLACE(REPLACE(r.plate_number, ' ', ''), '-', '') LIKE '%$plate%'";
    }
    return '(' . implode(' OR ', $conds) . ')';
}

/** Copies details and members onto a renewed policy. Caller owns the transaction. Returns false on a DB error. */
function riskCopyToRenewal($conn, string $from_policy_id, string $to_policy_id, string $company_id, string $username): bool {
    $row = riskFetchDetailsRow($conn, $from_policy_id);
    if ($row) {
        $details = $row;
        unset($details['policy_id'], $details['company_id'], $details['created_by'], $details['created_at'], $details['updated_by'], $details['updated_at']);
        // A shipment's B/L and departure belong to that shipment, not to the next policy year.
        $details['bl_awb_number'] = null;
        $details['departure_date'] = null;
        if (!riskUpsertDetails($conn, $to_policy_id, $company_id, $details, $username)) return false;
    }
    $members = riskFetchMembers($conn, $from_policy_id);
    if ($members) {
        $parsed = array_map(fn($m) => [
            'member_role' => $m['member_role'], 'full_name' => $m['full_name'], 'id_number' => $m['id_number'],
            'birth_date' => $m['birth_date'], 'relationship' => $m['relationship'],
            'benefit_death' => $m['benefit_death'], 'benefit_disability' => $m['benefit_disability'],
            'benefit_medical' => $m['benefit_medical'], 'share_percent' => $m['share_percent'],
        ], $members);
        if (!riskReplaceMembers($conn, $to_policy_id, $company_id, $parsed, $username)) return false;
    }
    return true;
}
