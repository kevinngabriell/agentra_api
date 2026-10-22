<?php

// Premium payment tracking (migration_payments.sql). Functions only; routing is in policies/index.php.
//
//   GET    /policies/{id}/payments                              due date, payment record, instalment schedule
//   PUT    /policies/{id}/payments                              set payment_due_date and/or replace the instalment schedule
//   POST   /policies/{id}/payments/{installment_id}/pay         record one instalment as paid
//   GET    /policies/overdue-premiums                           unpaid / overdue premium list (?scope=attention|overdue|due_soon|all)

require_once __DIR__ . '/../helpers/payments.php';

function payStrictDate(mixed $value, string $field): string {
    $s = is_string($value) ? trim($value) : '';
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    $errors = DateTime::getLastErrors();
    if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $d->format('Y-m-d') !== $s) {
        throw ApiException::from('ERR_PAY_002', ['field' => $field]);
    }
    return $s;
}

function payPastDate(mixed $value, string $field): string {
    $s = payStrictDate($value, $field);
    if ($s > date('Y-m-d')) throw ApiException::from('ERR_PAY_003', ['field' => $field]);
    return $s;
}

function payPositiveMoney(mixed $value, string $field): int {
    if (is_int($value)) {
        $n = $value;
    } elseif (is_string($value) && preg_match('/^\d{1,13}$/', trim($value))) {
        $n = (int)trim($value);
    } else {
        throw ApiException::from('ERR_PAY_004', ['field' => $field]);
    }
    if ($n <= 0 || $n > 9_999_999_999_999) throw ApiException::from('ERR_PAY_004', ['field' => $field]);
    return $n;
}

function payReference($conn, mixed $value): string {
    $s = trim((string)$value);
    return $s === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, mb_substr($s, 0, 100)) . "'";
}

function payFindPolicy($conn, string $policy_id, string $company_id): array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT policy_id, policy_number, coverage_start, payment_status, payment_due_date, payment_date,
            payment_reference, premium_amount, customer_premium_amount, renewal_status
        FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_PAY_001');
    return mysqli_fetch_assoc($res);
}

/** What the customer is billed: customer_premium_amount when computed, else the gross premium. */
function payExpectedAmount(array $policy): int {
    $billed = (int)$policy['customer_premium_amount'];
    return $billed > 0 ? $billed : (int)$policy['premium_amount'];
}

function payEffectiveDueDate(array $policy): string {
    if (!empty($policy['payment_due_date'])) return $policy['payment_due_date'];
    return (new DateTime($policy['coverage_start']))->modify('+' . PAYMENT_WARRANTY_DAYS . ' days')->format('Y-m-d');
}

function payDaysUntil(string $date): int {
    return (int)(new DateTime(date('Y-m-d')))->diff(new DateTime($date))->format('%r%a');
}

function payInstallments($conn, string $policy_id, string $company_id): array {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $res = mysqli_query($conn, "SELECT installment_id, seq, due_date, amount, paid_date, payment_reference
        FROM " . APP_SCHEMA . ".policy_installments WHERE policy_id = '$pid' AND company_id = '$company_id' ORDER BY seq");
    if (!$res) throw new RuntimeException('payInstallments: ' . mysqli_error($conn));
    return array_map(function ($r) {
        $r['seq']    = (int)$r['seq'];
        $r['amount'] = (int)$r['amount'];
        $r['is_paid'] = $r['paid_date'] !== null;
        $r['days_until_due'] = $r['is_paid'] ? null : payDaysUntil($r['due_date']);
        $r['urgency']        = $r['is_paid'] ? null : paymentUrgency($r['days_until_due']);
        return $r;
    }, mysqli_fetch_all($res, MYSQLI_ASSOC));
}

function getPolicyPayments($conn, string $policy_id, string $company_id): void {
    $policy       = payFindPolicy($conn, $policy_id, $company_id);
    $installments = payInstallments($conn, $policy_id, $company_id);

    $due = payEffectiveDueDate($policy);
    $next = null;
    foreach ($installments as $i) {
        if (!$i['is_paid']) { $next = $i; break; }
    }
    if ($next) $due = $next['due_date'];

    $unpaid = $policy['payment_status'] === 'unpaid' && $policy['renewal_status'] !== 'cancelled';
    $days   = payDaysUntil($due);

    jsonResponse(200, 'Payments retrieved', [
        'policy_id'         => $policy['policy_id'],
        'payment_status'    => $policy['payment_status'],
        'expected_amount'   => payExpectedAmount($policy),
        'payment_due_date'  => $policy['payment_due_date'],
        'effective_due_date' => $due,
        'is_default_due_date' => empty($policy['payment_due_date']),
        'payment_date'      => $policy['payment_date'],
        'payment_reference' => $policy['payment_reference'],
        'days_until_due'    => $unpaid ? $days : null,
        'urgency'           => $unpaid ? paymentUrgency($days) : null,
        'installments'      => $installments,
    ]);
}

// PUT: payment_due_date (optional) and installments (optional; [] clears the schedule).
function savePolicyPayments($conn, string $policy_id, string $company_id, array $input, string $username): void {
    $policy = payFindPolicy($conn, $policy_id, $company_id);
    $pid    = mysqli_real_escape_string($conn, $policy_id);
    $user_s = mysqli_real_escape_string($conn, $username);
    $now    = date('Y-m-d H:i:s');

    $sets = [];
    if (array_key_exists('payment_due_date', $input)) {
        $sets[] = ($input['payment_due_date'] === null || $input['payment_due_date'] === '')
            ? 'payment_due_date = NULL'
            : "payment_due_date = '" . payStrictDate($input['payment_due_date'], 'payment_due_date') . "'";
    }

    $schedule = null;
    if (array_key_exists('installments', $input)) {
        if (!is_array($input['installments'])) throw ApiException::from('ERR_PAY_009');
        $existing_paid = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".policy_installments
            WHERE policy_id = '$pid' AND paid_date IS NOT NULL"));
        if ((int)$existing_paid['n'] > 0) throw ApiException::from('ERR_PAY_006');

        $schedule = [];
        if (count($input['installments']) > 0) {
            if (count($input['installments']) < 2 || count($input['installments']) > 12) throw ApiException::from('ERR_PAY_009');
            $total = 0;
            foreach (array_values($input['installments']) as $idx => $row) {
                $row = is_array($row) ? $row : [];
                $amount = payPositiveMoney($row['amount'] ?? null, 'installments.' . ($idx + 1) . '.amount');
                $schedule[] = ['seq' => $idx + 1, 'due_date' => payStrictDate($row['due_date'] ?? null, 'installments.' . ($idx + 1) . '.due_date'), 'amount' => $amount];
                $total += $amount;
            }
            $expected = payExpectedAmount($policy);
            if ($total !== $expected) throw ApiException::from('ERR_PAY_005', ['total' => $total, 'expected' => $expected]);
            for ($i = 1; $i < count($schedule); $i++) {
                if ($schedule[$i]['due_date'] < $schedule[$i - 1]['due_date']) {
                    Response::validationError(['installments' => 'Tanggal jatuh tempo cicilan harus berurutan']);
                }
            }
        }
    }

    if (!$sets && $schedule === null) {
        Response::validationError(['payment_due_date' => 'payment_due_date atau installments wajib diisi']);
    }

    try {
        mysqli_begin_transaction($conn);
        if ($sets) {
            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET " . implode(', ', $sets) . ", updated_by = '$user_s', updated_at = '$now'
                WHERE policy_id = '$pid' AND company_id = '$company_id'");
        }
        if ($schedule !== null) {
            mysqli_query($conn, "DELETE FROM " . APP_SCHEMA . ".policy_installments WHERE policy_id = '$pid' AND company_id = '$company_id'");
            foreach ($schedule as $row) {
                $iid = 'inst_' . bin2hex(random_bytes(10));
                mysqli_query($conn, "INSERT INTO " . APP_SCHEMA . ".policy_installments
                    (installment_id, company_id, policy_id, seq, due_date, amount, created_by, created_at)
                    VALUES ('$iid', '$company_id', '$pid', {$row['seq']}, '{$row['due_date']}', {$row['amount']}, '$user_s', '$now')");
            }
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        throw ApiException::from('ERR_PAY_010');
    }

    $desc = $schedule !== null
        ? (count($schedule) > 0 ? 'Jadwal cicilan premi diatur (' . count($schedule) . ' cicilan)' : 'Jadwal cicilan premi dihapus')
        : 'Jatuh tempo premi diubah';
    insertPolicyLog($conn, $policy_id, $company_id, 'payment_schedule_changed', $desc, $username);

    getPolicyPayments($conn, $policy_id, $company_id);
}

// POST /payments/{installment_id}/pay — marks one instalment paid; the policy becomes "paid" with the last one.
function payInstallment($conn, string $policy_id, string $company_id, string $installment_id, array $input, string $username): void {
    $policy = payFindPolicy($conn, $policy_id, $company_id);
    $pid    = mysqli_real_escape_string($conn, $policy_id);
    $iid    = mysqli_real_escape_string($conn, $installment_id);
    $user_s = mysqli_real_escape_string($conn, $username);

    $res = mysqli_query($conn, "SELECT seq, amount, paid_date FROM " . APP_SCHEMA . ".policy_installments
        WHERE installment_id = '$iid' AND policy_id = '$pid' AND company_id = '$company_id' LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) throw ApiException::from('ERR_PAY_007');
    $inst = mysqli_fetch_assoc($res);
    if ($inst['paid_date'] !== null) throw ApiException::from('ERR_PAY_008');

    $paid_date = isset($input['payment_date']) && $input['payment_date'] !== '' ? payPastDate($input['payment_date'], 'payment_date') : date('Y-m-d');
    $ref_s     = payReference($conn, $input['payment_reference'] ?? '');
    $now       = date('Y-m-d H:i:s');

    try {
        mysqli_begin_transaction($conn);
        mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policy_installments SET paid_date = '$paid_date', payment_reference = $ref_s
            WHERE installment_id = '$iid' AND company_id = '$company_id'");
        $left = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".policy_installments
            WHERE policy_id = '$pid' AND paid_date IS NULL"));
        if ((int)$left['n'] === 0 && $policy['payment_status'] === 'unpaid') {
            mysqli_query($conn, "UPDATE " . APP_SCHEMA . ".policies SET payment_status = 'paid', payment_date = '$paid_date',
                payment_reference = $ref_s, updated_by = '$user_s', updated_at = '$now'
                WHERE policy_id = '$pid' AND company_id = '$company_id'");
        }
        mysqli_commit($conn);
    } catch (\Throwable $e) {
        mysqli_rollback($conn);
        RequestLogger::setException($e);
        throw ApiException::from('ERR_PAY_010');
    }

    insertPolicyLog($conn, $policy_id, $company_id, 'payment_status_changed',
        'Cicilan ke-' . $inst['seq'] . ' dibayar (Rp ' . number_format((int)$inst['amount'], 0, ',', '.') . ')', $username);

    getPolicyPayments($conn, $policy_id, $company_id);
}

// GET /policies/overdue-premiums
function listOverduePremiums($conn, string $company_id, array $q): void {
    $res = mysqli_query($conn, "SELECT p.policy_id, p.policy_number, p.product_type, p.coverage_start, p.coverage_end,
            p.premium_amount, p.customer_premium_amount, p.payment_due_date,
            c.customer_id, c.display_name AS customer_name, c.customer_type,
            c.personal_whatsapp, c.pic_whatsapp,
            i.short_name AS insurer_short_name,
            (SELECT MIN(pi.due_date) FROM " . APP_SCHEMA . ".policy_installments pi WHERE pi.policy_id = p.policy_id AND pi.paid_date IS NULL) AS next_installment_due
        FROM " . APP_SCHEMA . ".policies p
        JOIN " . APP_SCHEMA . ".customers c ON c.customer_id = p.customer_id
        LEFT JOIN " . APP_SCHEMA . ".insurers i ON i.insurer_id = p.insurer_id
        WHERE p.company_id = '$company_id' AND p.payment_status = 'unpaid' AND p.renewal_status <> 'cancelled'");
    if (!$res) throw new RuntimeException('listOverduePremiums: ' . mysqli_error($conn));

    $inst_amount = [];
    $ia = mysqli_query($conn, "SELECT policy_id, due_date, amount FROM " . APP_SCHEMA . ".policy_installments
        WHERE company_id = '$company_id' AND paid_date IS NULL ORDER BY policy_id, seq");
    while ($ia && ($r = mysqli_fetch_assoc($ia))) {
        $inst_amount[$r['policy_id']] ??= (int)$r['amount']; // first unpaid by seq
    }

    $rows = [];
    $summary = ['overdue_count' => 0, 'overdue_amount' => 0, 'due_soon_count' => 0, 'due_soon_amount' => 0, 'cancellation_risk_count' => 0];
    while ($r = mysqli_fetch_assoc($res)) {
        $due = $r['next_installment_due'] ?: ($r['payment_due_date'] ?: payEffectiveDueDate($r));
        $days = payDaysUntil($due);
        $urgency = paymentUrgency($days);
        $billed = (int)$r['customer_premium_amount'] > 0 ? (int)$r['customer_premium_amount'] : (int)$r['premium_amount'];
        $amount = $r['next_installment_due'] ? ($inst_amount[$r['policy_id']] ?? $billed) : $billed;

        if ($urgency === 'overdue' || $urgency === 'cancellation_risk') {
            $summary['overdue_count']++;
            $summary['overdue_amount'] += $amount;
        }
        if ($urgency === 'cancellation_risk') $summary['cancellation_risk_count']++;
        if ($urgency === 'due_soon') {
            $summary['due_soon_count']++;
            $summary['due_soon_amount'] += $amount;
        }

        $rows[] = [
            'policy_id' => $r['policy_id'], 'policy_number' => $r['policy_number'], 'product_type' => $r['product_type'],
            'customer_id' => $r['customer_id'], 'customer_name' => $r['customer_name'], 'customer_type' => $r['customer_type'],
            'whatsapp' => $r['customer_type'] === 'company' ? $r['pic_whatsapp'] : $r['personal_whatsapp'],
            'insurer_short_name' => $r['insurer_short_name'],
            'coverage_start' => $r['coverage_start'], 'coverage_end' => $r['coverage_end'],
            'due_date' => $due, 'amount_due' => $amount, 'billed_amount' => $billed,
            'is_installment' => $r['next_installment_due'] !== null,
            'days_until_due' => $days, 'urgency' => $urgency,
        ];
    }

    $scope = (string)($q['scope'] ?? 'attention');
    $rows = array_values(array_filter($rows, fn($r) => match ($scope) {
        'all'      => true,
        'overdue'  => in_array($r['urgency'], ['overdue', 'cancellation_risk'], true),
        'due_soon' => $r['urgency'] === 'due_soon',
        default    => $r['urgency'] !== 'upcoming',
    }));
    usort($rows, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));

    jsonResponse(200, 'Overdue premiums retrieved', ['data' => array_slice($rows, 0, 200), 'summary' => $summary]);
}
