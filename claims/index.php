<?php

require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../helpers/policy_log.php';
require_once __DIR__ . '/../helpers/claims.php';

// Claims registered against policies. Rules (status flow, deductible, document
// checklist) live in helpers/claims.php; tables in migration_claims.sql.
// Claim documents are uploaded through the documents module (claim_id +
// claim_requirement) and listed with GET /documents?claim_id=...

// ── Validation helpers ────────────────────────────────────────────────────────

// Whole, non-negative Rupiah. Accepts ints and digit strings (the web form sends strings).
function claimMoney(mixed $value, string $field): int {
    if (is_int($value)) {
        $n = $value;
    } elseif (is_float($value) && floor($value) === $value) {
        $n = (int)$value;
    } elseif (is_string($value) && preg_match('/^\d{1,13}$/', trim($value))) {
        $n = (int)trim($value);
    } else {
        throw ApiException::from('ERR_CLAIM_007', ['field' => $field]);
    }
    if ($n < 0 || $n > 9_999_999_999_999) {
        throw ApiException::from('ERR_CLAIM_007', ['field' => $field]);
    }
    return $n;
}

// Strict YYYY-MM-DD (rejects 2026-02-31 and the like).
function claimDate(mixed $value, string $field): string {
    $s = is_string($value) ? trim($value) : '';
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    $errors = DateTime::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $d->format('Y-m-d') !== $s) {
        throw ApiException::from('ERR_CLAIM_003', ['field' => $field]);
    }
    return $s;
}

function claimPastDate(mixed $value, string $field): string {
    $s = claimDate($value, $field);
    if ($s > date('Y-m-d')) throw ApiException::from('ERR_CLAIM_004', ['field' => $field]);
    return $s;
}

// NULL-able string column from free text.
function claimText($conn, mixed $value, int $max): string {
    $s = trim((string)$value);
    return $s === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, mb_substr($s, 0, $max)) . "'";
}

function claimIdr(?int $n): string {
    return $n === null ? '-' : 'Rp ' . number_format($n, 0, ',', '.');
}

// ── Shared query pieces ───────────────────────────────────────────────────────

function claimSelectSql(): string {
    return "SELECT cl.claim_id, cl.claim_number, cl.insurer_claim_number,
                cl.policy_id, p.policy_number, p.product_type, p.object_insured,
                p.coverage_start, p.coverage_end, p.premium_amount, p.sum_insured,
                p.insurer_id, i.name AS insurer_name, i.short_name AS insurer_short_name,
                cl.customer_id, c.display_name AS customer_name, c.customer_type,
                cl.date_of_loss, cl.reported_date, cl.cause, cl.loss_location, cl.description,
                cl.estimated_loss, cl.deductible, cl.approved_amount, cl.settlement_amount, cl.payment_date,
                cl.status, cl.status_changed_at, cl.surveyor_name, cl.survey_date, cl.rejection_reason,
                cl.created_by, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS created_by_name,
                cl.created_at, cl.updated_at
            FROM " . APP_SCHEMA . ".claims cl
            JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cl.policy_id
            JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = cl.customer_id
            LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
            LEFT JOIN " . CORE_SCHEMA . ".app_user u ON u.user_id COLLATE utf8mb4_unicode_ci = cl.created_by";
}

function normalizeClaim(array $row): array {
    foreach (['estimated_loss', 'deductible', 'premium_amount', 'sum_insured'] as $k) $row[$k] = (int)$row[$k];
    foreach (['approved_amount', 'settlement_amount'] as $k) $row[$k] = $row[$k] === null ? null : (int)$row[$k];
    $row['created_by_name'] = $row['created_by_name'] !== '' ? $row['created_by_name'] : null;
    $row['status_label']    = CLAIM_STATUS_LABELS[$row['status']] ?? $row['status'];
    return $row;
}

function findClaim($conn, string $claim_id, string $company_id): array {
    $id = mysqli_real_escape_string($conn, $claim_id);
    $result = mysqli_query($conn, claimSelectSql() . "
        WHERE cl.claim_id = '$id' AND cl.company_id = '$company_id' AND cl.is_deleted = 0 LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) {
        throw ApiException::from('ERR_CLAIM_001');
    }
    return normalizeClaim(mysqli_fetch_assoc($result));
}

function claimPaginate(int $total, int $page, int $limit): array {
    return ['total' => $total, 'page' => $page, 'limit' => $limit, 'total_pages' => (int)ceil($total / $limit)];
}

// ── LIST ──────────────────────────────────────────────────────────────────────
function listClaims($conn, string $company_id, array $q): void {
    $page   = max(1, (int)($q['page'] ?? 1));
    $limit  = min(50, max(1, (int)($q['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "cl.company_id = '$company_id' AND cl.is_deleted = 0";

    $status = (string)($q['status'] ?? '');
    if ($status === 'open') {
        $where .= " AND cl.status IN ('" . implode("','", CLAIM_OPEN_STATUSES) . "')";
    } elseif ($status !== '') {
        if (!in_array($status, CLAIM_STATUSES, true)) throw ApiException::from('ERR_CLAIM_008');
        $where .= " AND cl.status = '$status'";
    }

    $columns = ['customer_id' => 'cl.customer_id', 'policy_id' => 'cl.policy_id', 'insurer_id' => 'p.insurer_id', 'product_type' => 'p.product_type'];
    foreach ($columns as $param => $column) {
        if (!empty($q[$param])) {
            $value = mysqli_real_escape_string($conn, (string)$q[$param]);
            $where .= " AND $column = '$value'";
        }
    }

    if (!empty($q['params'])) {
        $s = mysqli_real_escape_string($conn, trim((string)$q['params']));
        $where .= " AND (cl.claim_number LIKE '%$s%' OR cl.insurer_claim_number LIKE '%$s%' OR cl.cause LIKE '%$s%'
                         OR c.display_name LIKE '%$s%' OR p.policy_number LIKE '%$s%')";
    }

    $from = " FROM " . APP_SCHEMA . ".claims cl
              JOIN " . APP_SCHEMA . ".policies p ON p.policy_id = cl.policy_id
              JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = cl.customer_id";

    $result = mysqli_query($conn, claimSelectSql() . " WHERE $where ORDER BY cl.date_of_loss DESC, cl.created_at DESC LIMIT $limit OFFSET $offset");
    $count  = mysqli_query($conn, "SELECT COUNT(*) AS total $from WHERE $where");
    if (!$result || !$count) {
        throw new RuntimeException('listClaims: ' . mysqli_error($conn));
    }

    $rows     = array_map('normalizeClaim', mysqli_fetch_all($result, MYSQLI_ASSOC));
    $provided = claimProvidedKeysFor($conn, $company_id, array_column($rows, 'claim_id'));
    foreach ($rows as &$row) {
        [$row['docs_required'], $row['docs_provided']] = claimRequiredProgress($row['product_type'], $provided[$row['claim_id']] ?? []);
    }
    unset($row);

    jsonResponse(200, 'Claims retrieved', [
        'data'       => $rows,
        'pagination' => claimPaginate((int)mysqli_fetch_assoc($count)['total'], $page, $limit),
    ]);
}

// ── SUMMARY (stat cards + dashboard widget) ───────────────────────────────────
function getClaimSummary($conn, string $company_id): void {
    $res = mysqli_query($conn, "SELECT status, COUNT(*) AS total,
            COALESCE(SUM(estimated_loss), 0) AS estimated, COALESCE(SUM(approved_amount), 0) AS approved,
            COALESCE(SUM(settlement_amount), 0) AS settled
        FROM " . APP_SCHEMA . ".claims WHERE company_id = '$company_id' AND is_deleted = 0 GROUP BY status");
    if (!$res) {
        throw new RuntimeException('getClaimSummary: ' . mysqli_error($conn));
    }

    $by_status = array_fill_keys(CLAIM_STATUSES, 0);
    $total = $open = $open_estimated = $approved_unpaid = $paid_total = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        $n = (int)$row['total'];
        $by_status[$row['status']] = $n;
        $total += $n;
        if (in_array($row['status'], CLAIM_OPEN_STATUSES, true)) {
            $open += $n;
            $open_estimated += (int)$row['estimated'];
        }
        if ($row['status'] === 'approved') $approved_unpaid = (int)$row['approved'];
        if ($row['status'] === 'paid')     $paid_total      = (int)$row['settled'];
    }

    jsonResponse(200, 'Claim summary retrieved', [
        'total'             => $total,
        'open'              => $open,
        'by_status'         => $by_status,
        'pending_documents' => claimsPendingDocumentsCount($conn, $company_id),
        'open_estimated'    => $open_estimated,
        'approved_unpaid'   => $approved_unpaid,
        'paid_total'        => $paid_total,
    ]);
}

// ── STATS: claim history + claim ratio for one customer or policy ─────────────
// claim_ratio = incurred / premium * 100, where incurred is the settlement for paid
// claims and the approved amount for approved ones. Open, rejected and unapproved
// claims are not counted; cancelled policies carry no premium.
function getClaimStats($conn, string $company_id, array $q): void {
    $scope = [];
    foreach (['customer_id' => 'customer_id', 'policy_id' => 'policy_id'] as $param => $column) {
        if (!empty($q[$param])) $scope[$column] = mysqli_real_escape_string($conn, (string)$q[$param]);
    }
    if (!$scope) {
        Response::validationError(['customer_id' => 'customer_id atau policy_id wajib diisi']);
    }

    $claimWhere = "company_id = '$company_id' AND is_deleted = 0";
    $policyWhere = "company_id = '$company_id' AND renewal_status <> 'cancelled'";
    foreach ($scope as $column => $value) {
        $claimWhere  .= " AND $column = '$value'";
        $policyWhere .= " AND $column = '$value'";
    }

    $claims = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total,
            COALESCE(SUM(status IN ('" . implode("','", CLAIM_OPEN_STATUSES) . "')), 0) AS open_count,
            COALESCE(SUM(status = 'rejected'), 0) AS rejected_count,
            COALESCE(SUM(status = 'paid'), 0) AS paid_count,
            COALESCE(SUM(estimated_loss), 0) AS estimated_total,
            COALESCE(SUM(CASE WHEN status IN ('approved', 'paid') THEN COALESCE(settlement_amount, approved_amount) ELSE 0 END), 0) AS incurred_total,
            COALESCE(SUM(settlement_amount), 0) AS settled_total,
            MAX(date_of_loss) AS last_claim_date
        FROM " . APP_SCHEMA . ".claims WHERE $claimWhere"));
    $premium = (int)mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(premium_amount), 0) AS total FROM " . APP_SCHEMA . ".policies WHERE $policyWhere"))['total'];

    $incurred = (int)$claims['incurred_total'];
    jsonResponse(200, 'Claim stats retrieved', [
        'claim_count'     => (int)$claims['total'],
        'open_count'      => (int)$claims['open_count'],
        'rejected_count'  => (int)$claims['rejected_count'],
        'paid_count'      => (int)$claims['paid_count'],
        'estimated_total' => (int)$claims['estimated_total'],
        'incurred_total'  => $incurred,
        'settled_total'   => (int)$claims['settled_total'],
        'premium_total'   => $premium,
        'claim_ratio'     => $premium > 0 ? round($incurred / $premium * 100, 1) : null,
        'last_claim_date' => $claims['last_claim_date'],
    ]);
}

// ── DETAIL ────────────────────────────────────────────────────────────────────
function getClaimDetail($conn, string $company_id, string $claim_id): void {
    $claim = findClaim($conn, $claim_id, $company_id);

    $provided = claimProvidedKeysFor($conn, $company_id, [$claim['claim_id']])[$claim['claim_id']] ?? [];
    $checklist = claimChecklist($claim['product_type'], $provided);
    [$claim['docs_required'], $claim['docs_provided']] = claimRequiredProgress($claim['product_type'], $provided);

    $id  = mysqli_real_escape_string($conn, $claim_id);
    $res = mysqli_query($conn, "SELECT e.event_id, e.event_type, e.from_status, e.to_status, e.channel, e.description,
            e.event_date, e.created_by, TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS created_by_name, e.created_at
        FROM " . APP_SCHEMA . ".claim_events e
        LEFT JOIN " . CORE_SCHEMA . ".app_user u ON u.user_id COLLATE utf8mb4_unicode_ci = e.created_by
        WHERE e.claim_id = '$id' AND e.company_id = '$company_id'
        ORDER BY e.event_date DESC, e.created_at DESC, e.event_id DESC");
    if (!$res) {
        throw new RuntimeException('getClaimDetail: ' . mysqli_error($conn));
    }
    $events = array_map(function ($e) {
        $e['created_by_name'] = $e['created_by_name'] !== '' ? $e['created_by_name'] : null;
        return $e;
    }, mysqli_fetch_all($res, MYSQLI_ASSOC));

    $claim['checklist'] = $checklist;
    $claim['events']    = $events;
    jsonResponse(200, 'Claim found', $claim);
}

// ── CREATE ────────────────────────────────────────────────────────────────────
function nextClaimNumber($conn, string $company_id): string {
    $prefix = 'CLM-' . date('Y') . '-';
    // Soft-deleted claims are included so a number is never reused.
    $res = mysqli_query($conn, "SELECT COALESCE(MAX(CAST(SUBSTRING(claim_number, " . (strlen($prefix) + 1) . ") AS UNSIGNED)), 0) AS n
        FROM " . APP_SCHEMA . ".claims WHERE company_id = '$company_id' AND claim_number LIKE '$prefix%'");
    return $prefix . str_pad((string)((int)mysqli_fetch_assoc($res)['n'] + 1), 4, '0', STR_PAD_LEFT);
}

function createClaim($conn, string $company_id, array $input, string $username): void {
    requireFields($input, ['policy_id', 'date_of_loss', 'cause']);

    $pid = mysqli_real_escape_string($conn, (string)$input['policy_id']);
    $res = mysqli_query($conn, "SELECT policy_id, customer_id, coverage_start, coverage_end, renewal_status, policy_number
        FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_CLAIM_002');
    $policy = mysqli_fetch_assoc($res);
    if ($policy['renewal_status'] === 'cancelled') throw ApiException::from('ERR_CLAIM_006');

    $date_of_loss = claimPastDate($input['date_of_loss'], 'date_of_loss');
    if ($date_of_loss < $policy['coverage_start'] || $date_of_loss > $policy['coverage_end']) {
        throw ApiException::from('ERR_CLAIM_005', ['start' => $policy['coverage_start'], 'end' => $policy['coverage_end']]);
    }

    $reported_date = date('Y-m-d');
    if (isset($input['reported_date']) && $input['reported_date'] !== '') {
        $reported_date = claimPastDate($input['reported_date'], 'reported_date');
        if ($reported_date < $date_of_loss) {
            Response::validationError(['reported_date' => 'Tanggal lapor tidak boleh sebelum tanggal kejadian']);
        }
    }

    $estimated = claimMoney($input['estimated_loss'] ?? 0, 'estimated_loss');
    $deductible = claimMoney($input['deductible'] ?? 0, 'deductible');

    $cause_s   = claimText($conn, $input['cause'], 200);
    $loc_s     = claimText($conn, $input['loss_location'] ?? '', 300);
    $desc_s    = claimText($conn, $input['description'] ?? '', 5000);
    $insref_s  = claimText($conn, $input['insurer_claim_number'] ?? '', 100);
    $user_s    = mysqli_real_escape_string($conn, $username);
    $customer  = mysqli_real_escape_string($conn, $policy['customer_id']);
    $claim_id  = 'clm_' . bin2hex(random_bytes(10));
    $now       = date('Y-m-d H:i:s');

    // Two agents registering at once can draw the same number; the unique key catches it.
    for ($attempt = 1; ; $attempt++) {
        $claim_number = nextClaimNumber($conn, $company_id);
        try {
            mysqli_begin_transaction($conn);
            mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".claims
                (claim_id, company_id, claim_number, insurer_claim_number, policy_id, customer_id, date_of_loss, reported_date,
                 cause, loss_location, description, estimated_loss, deductible, status, status_changed_at, created_by, created_at)
                VALUES
                ('$claim_id', '$company_id', '$claim_number', $insref_s, '$pid', '$customer', '$date_of_loss', '$reported_date',
                 $cause_s, $loc_s, $desc_s, $estimated, $deductible, 'reported', '$now', '$user_s', '$now')");
            insertClaimEvent($conn, $claim_id, $company_id, 'registered',
                'Klaim didaftarkan. Estimasi kerugian ' . claimIdr($estimated), $username, null, 'reported', null, $reported_date);
            mysqli_commit($conn);
            break;
        } catch (\Throwable $e) {
            mysqli_rollback($conn);
            if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062 && $attempt < 3) continue;
            RequestLogger::setException($e);
            throw ApiException::from('ERR_CLAIM_015');
        }
    }

    insertPolicyLog($conn, $policy['policy_id'], $company_id, 'claim_registered',
        "Klaim $claim_number didaftarkan: " . trim((string)$input['cause']), $username, null, null, 'claims', $claim_id);

    jsonResponse(201, 'Claim registered successfully', findClaim($conn, $claim_id, $company_id));
}

// ── UPDATE DETAILS ────────────────────────────────────────────────────────────
function updateClaim($conn, string $company_id, string $claim_id, array $input, string $username): void {
    $claim = findClaim($conn, $claim_id, $company_id);
    if ($claim['status'] === 'paid') throw ApiException::from('ERR_CLAIM_010', ['status' => $claim['status_label']]);

    $updates = [];
    $changes = [];   // human-readable lines for the timeline

    $text = function (string $field, string $label, int $max) use ($conn, $input, $claim, &$updates, &$changes) {
        if (!array_key_exists($field, $input)) return;
        $new = trim((string)$input[$field]);
        if ($new === (string)($claim[$field] ?? '')) return;
        if ($field === 'cause' && $new === '') {
            Response::validationError(['cause' => 'cause wajib diisi']);
        }
        $updates[] = "$field = " . claimText($conn, $new, $max);
        $changes[] = "$label diperbarui";
    };
    $text('cause', 'Penyebab', 200);
    $text('loss_location', 'Lokasi kejadian', 300);
    $text('description', 'Kronologi', 5000);
    $text('insurer_claim_number', 'No. klaim asuransi', 100);
    $text('surveyor_name', 'Surveyor', 150);

    $money = function (string $field, string $label) use ($input, $claim, &$updates, &$changes) {
        if (!array_key_exists($field, $input)) return;
        $new = claimMoney($input[$field], $field);
        if ($new === $claim[$field]) return;
        $updates[] = "$field = $new";
        $changes[] = "$label: " . claimIdr($claim[$field]) . ' → ' . claimIdr($new);
    };
    $money('estimated_loss', 'Estimasi kerugian');
    $money('deductible', 'Risiko sendiri');
    if ($claim['status'] === 'approved') $money('approved_amount', 'Jumlah disetujui');

    if (array_key_exists('date_of_loss', $input)) {
        $new = claimPastDate($input['date_of_loss'], 'date_of_loss');
        if ($new !== $claim['date_of_loss']) {
            if ($new < $claim['coverage_start'] || $new > $claim['coverage_end']) {
                throw ApiException::from('ERR_CLAIM_005', ['start' => $claim['coverage_start'], 'end' => $claim['coverage_end']]);
            }
            $updates[] = "date_of_loss = '$new'";
            $changes[] = "Tanggal kejadian: {$claim['date_of_loss']} → $new";
        }
    }
    if (array_key_exists('reported_date', $input)) {
        $new = claimPastDate($input['reported_date'], 'reported_date');
        if ($new !== $claim['reported_date']) {
            $updates[] = "reported_date = '$new'";
            $changes[] = "Tanggal lapor: {$claim['reported_date']} → $new";
        }
    }
    if (array_key_exists('survey_date', $input)) {
        $new = ($input['survey_date'] === null || $input['survey_date'] === '') ? null : claimDate($input['survey_date'], 'survey_date');
        if ($new !== $claim['survey_date']) {
            $updates[] = 'survey_date = ' . ($new === null ? 'NULL' : "'$new'");
            $changes[] = 'Tanggal survei diperbarui';
        }
    }

    if (!$updates) throw ApiException::from('ERR_CLAIM_011');

    $updates[] = "updated_by = '" . mysqli_real_escape_string($conn, $username) . "'";
    $id = mysqli_real_escape_string($conn, $claim_id);
    try {
        mysqli_begin_transaction($conn);
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".claims SET " . implode(', ', $updates) . "
            WHERE claim_id = '$id' AND company_id = '$company_id'");
        insertClaimEvent($conn, $claim_id, $company_id, 'updated', implode('; ', $changes), $username);
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        throw ApiException::from('ERR_CLAIM_015');
    }

    jsonResponse(200, 'Claim updated successfully', findClaim($conn, $claim_id, $company_id));
}

// ── STATUS CHANGE ─────────────────────────────────────────────────────────────
function changeClaimStatus($conn, string $company_id, string $claim_id, array $input, string $username): void {
    $claim = findClaim($conn, $claim_id, $company_id);
    $from  = $claim['status'];
    $to    = (string)($input['status'] ?? '');
    if (!in_array($to, CLAIM_STATUSES, true)) throw ApiException::from('ERR_CLAIM_008');
    if (!in_array($to, CLAIM_TRANSITIONS[$from], true)) {
        throw ApiException::from('ERR_CLAIM_009', ['from' => CLAIM_STATUS_LABELS[$from], 'to' => CLAIM_STATUS_LABELS[$to]]);
    }

    $set     = ["status = '$to'", "status_changed_at = '" . date('Y-m-d H:i:s') . "'"];
    $missing = [];
    $note    = trim((string)($input['note'] ?? ''));
    $detail  = [];

    if (!empty($input['insurer_claim_number'])) {
        $set[] = 'insurer_claim_number = ' . claimText($conn, $input['insurer_claim_number'], 100);
    }

    switch ($to) {
        case 'survey':
            if (!empty($input['surveyor_name'])) $set[] = 'surveyor_name = ' . claimText($conn, $input['surveyor_name'], 150);
            if (!empty($input['survey_date']))   $set[] = "survey_date = '" . claimDate($input['survey_date'], 'survey_date') . "'";
            break;

        case 'approved':
            if (!isset($input['approved_amount']) || $input['approved_amount'] === '') {
                $missing['approved_amount'] = 'approved_amount wajib diisi';
                break;
            }
            $approved = claimMoney($input['approved_amount'], 'approved_amount');
            if ($approved <= 0) {
                $missing['approved_amount'] = 'approved_amount harus lebih dari 0';
                break;
            }
            $set[] = "approved_amount = $approved";
            $detail[] = 'Disetujui ' . claimIdr($approved);
            if (isset($input['deductible']) && $input['deductible'] !== '') {
                $deductible = claimMoney($input['deductible'], 'deductible');
                $set[] = "deductible = $deductible";
                $detail[] = 'risiko sendiri ' . claimIdr($deductible);
            }
            break;

        case 'rejected':
            $reason = trim((string)($input['rejection_reason'] ?? ''));
            if ($reason === '') {
                $missing['rejection_reason'] = 'rejection_reason wajib diisi';
                break;
            }
            $set[] = 'rejection_reason = ' . claimText($conn, $reason, 500);
            $detail[] = 'Alasan: ' . mb_substr($reason, 0, 500);
            break;

        case 'paid':
            if (!isset($input['settlement_amount']) || $input['settlement_amount'] === '') $missing['settlement_amount'] = 'settlement_amount wajib diisi';
            if (empty($input['payment_date'])) $missing['payment_date'] = 'payment_date wajib diisi';
            if ($missing) break;

            $settlement = claimMoney($input['settlement_amount'], 'settlement_amount');
            if ($claim['approved_amount'] !== null && $settlement > $claim['approved_amount']) {
                throw ApiException::from('ERR_CLAIM_014');
            }
            $payment_date = claimPastDate($input['payment_date'], 'payment_date');
            if ($payment_date < $claim['date_of_loss']) throw ApiException::from('ERR_CLAIM_016');
            $set[] = "settlement_amount = $settlement";
            $set[] = "payment_date = '$payment_date'";
            $detail[] = 'Dibayar ' . claimIdr($settlement) . " pada $payment_date";
            break;

        case 'documents_complete':
            // Reopening a rejected claim clears the old reason; it stays in the timeline.
            if ($from === 'rejected') $set[] = 'rejection_reason = NULL';
            break;
    }
    if ($missing) Response::validationError($missing);

    $description = CLAIM_STATUS_LABELS[$from] . ' → ' . CLAIM_STATUS_LABELS[$to];
    if ($detail) $description .= '. ' . implode(', ', $detail);
    if ($note !== '') $description .= ". Catatan: $note";

    $set[] = "updated_by = '" . mysqli_real_escape_string($conn, $username) . "'";
    $id = mysqli_real_escape_string($conn, $claim_id);
    try {
        mysqli_begin_transaction($conn);
        // The status guard makes a double-click or a concurrent change fail instead of skipping a step.
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".claims SET " . implode(', ', $set) . "
            WHERE claim_id = '$id' AND company_id = '$company_id' AND status = '$from'");
        if (mysqli_affected_rows($conn) !== 1) {
            mysqli_rollback($conn);
            throw ApiException::from('ERR_CLAIM_009', ['from' => CLAIM_STATUS_LABELS[$from], 'to' => CLAIM_STATUS_LABELS[$to]]);
        }
        insertClaimEvent($conn, $claim_id, $company_id, 'status_changed', $description, $username, $from, $to);
        mysqli_commit($conn);
    } catch (ApiException $e) {
        throw $e;
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        throw ApiException::from('ERR_CLAIM_015');
    }

    insertPolicyLog($conn, $claim['policy_id'], $company_id, 'claim_status_changed',
        "Klaim {$claim['claim_number']}: $description", $username, $from, $to, 'claims', $claim_id);

    jsonResponse(200, 'Claim status updated', findClaim($conn, $claim_id, $company_id));
}

// ── FOLLOW-UP LOG / NOTES ─────────────────────────────────────────────────────
function addClaimEvent($conn, string $company_id, string $claim_id, array $input, string $username): void {
    findClaim($conn, $claim_id, $company_id);

    $type = (string)($input['event_type'] ?? 'followup');
    if (!in_array($type, ['followup', 'note'], true)) throw ApiException::from('ERR_CLAIM_013');
    requireFields($input, ['description']);

    $channel = null;
    if ($type === 'followup' && !empty($input['channel'])) {
        $channel = (string)$input['channel'];
        if (!in_array($channel, CLAIM_FOLLOWUP_CHANNELS, true)) throw ApiException::from('ERR_CLAIM_017');
    }
    $event_date = empty($input['event_date']) ? date('Y-m-d') : claimPastDate($input['event_date'], 'event_date');

    try {
        insertClaimEvent($conn, $claim_id, $company_id, $type, mb_substr(trim((string)$input['description']), 0, 2000),
            $username, null, null, $channel, $event_date);
    } catch (\Throwable $e) {
        RequestLogger::setException($e);
        throw ApiException::from('ERR_CLAIM_015');
    }

    getClaimDetail($conn, $company_id, $claim_id);
}

// ── DELETE (soft) ─────────────────────────────────────────────────────────────
function deleteClaim($conn, string $company_id, string $claim_id, string $username): void {
    $claim = findClaim($conn, $claim_id, $company_id);
    $id    = mysqli_real_escape_string($conn, $claim_id);

    $docs = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM " . APP_SCHEMA . ".documents
        WHERE claim_id = '$id' AND company_id = '$company_id' AND is_deleted = 0"))['total'];
    if ($claim['status'] !== 'reported' || (int)$docs > 0) throw ApiException::from('ERR_CLAIM_012');

    $user = mysqli_real_escape_string($conn, $username);
    mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".claims
        SET is_deleted = 1, deleted_at = '" . date('Y-m-d H:i:s') . "', deleted_by = '$user', updated_by = '$user'
        WHERE claim_id = '$id' AND company_id = '$company_id'");

    insertPolicyLog($conn, $claim['policy_id'], $company_id, 'claim_deleted',
        "Klaim {$claim['claim_number']} dihapus", $username, null, null, 'claims', $claim_id);

    jsonResponse(200, 'Claim deleted successfully');
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
// $action and $parts are available from the parent router (index.php via require)
//
//   GET    /claims                      list (filters: status|open, insurer_id, product_type, customer_id, policy_id, params, page, limit)
//   POST   /claims                      register (policy_id, date_of_loss, cause, loss_location, description, estimated_loss, deductible, reported_date, insurer_claim_number)
//   GET    /claims/summary              counts per status, pending-documents count, amounts
//   GET    /claims/stats                claim history + claim ratio (?customer_id= | ?policy_id=)
//   GET    /claims/{id}                 detail with document checklist and event timeline
//   PUT    /claims/{id}                 edit details (not once paid)
//   DELETE /claims/{id}                 soft delete (only "reported" with no documents)
//   PUT    /claims/{id}/status          move through the status flow (see CLAIM_TRANSITIONS)
//   POST   /claims/{id}/events          follow-up or note (event_type, description, channel, event_date)

$authUser   = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$company_id = $authUser['company_id'] ?? null;
$username   = $authUser['sub'] ?? $authUser['user_id'] ?? '';

if (!$company_id) {
    jsonResponse(400, 'company_id is required');
}
$sub_action = $parts[4] ?? '';

try {
    $conn       = getConn();
    $company_id = mysqli_real_escape_string($conn, $company_id);

    if ($action === 'summary') {
        if ($method !== 'GET') Response::methodNotAllowed();
        getClaimSummary($conn, $company_id);

    } elseif ($action === 'stats') {
        if ($method !== 'GET') Response::methodNotAllowed();
        getClaimStats($conn, $company_id, $_GET);

    } elseif ($action !== '' && $sub_action === 'status') {
        if ($method !== 'PUT') Response::methodNotAllowed();
        changeClaimStatus($conn, $company_id, $action, input(), $username);

    } elseif ($action !== '' && $sub_action === 'events') {
        if ($method !== 'POST') Response::methodNotAllowed();
        addClaimEvent($conn, $company_id, $action, input(), $username);

    } elseif ($action !== '' && $sub_action === '') {
        switch ($method) {
            case 'GET':
                getClaimDetail($conn, $company_id, $action);
                break;
            case 'PUT':
                updateClaim($conn, $company_id, $action, input(), $username);
                break;
            case 'DELETE':
                deleteClaim($conn, $company_id, $action, $username);
                break;
            default:
                Response::methodNotAllowed();
        }

    } elseif ($action === '') {
        switch ($method) {
            case 'GET':
                listClaims($conn, $company_id, $_GET);
                break;
            case 'POST':
                createClaim($conn, $company_id, input(), $username);
                break;
            default:
                Response::methodNotAllowed();
        }

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
