<?php

// Endorsement premium ledger and cancellation (v2.1.0). Functions only; routing is in policies/index.php.
//
//   GET  /policies/endorsements                      company-wide ledger (?status=open_debit|open_credit|commission_pending|all, ?policy_id)
//   GET  /policies/{id}/endorsements                 a policy's notes + billing summary
//   POST /policies/{id}/endorsements                 record a note the insurer issued (optionally applying it to the contract)
//   POST /policies/{id}/endorsements/preview         compute the charged premium without saving
//   PUT  /policies/{id}/endorsements/{eid}           settle the note / its commission, edit references, void
//   POST /policies/{id}/cancel                       cancel the policy with a refund (credit note) and commission clawback
//   POST /policies/{id}/cancel/preview               refund the cancellation would give

require_once __DIR__ . '/../helpers/endorsements.php';
require_once __DIR__ . '/../helpers/schedules.php';
require_once __DIR__ . '/../helpers/policy_log.php';

function listPolicyEndorsements($conn, string $company_id, string $policy_id): void {
    $policy = endFetchPolicy($conn, $company_id, $policy_id);
    $rows = dbAll($conn, "SELECT e.*, p.policy_number FROM {app}.policy_endorsements e JOIN {app}.policies p ON p.policy_id = e.policy_id
        WHERE e.policy_id = ? AND e.company_id = ? ORDER BY e.effective_date, e.created_at", [$policy_id, $company_id]);
    $p = dbOne($conn, "SELECT debit_note_number, cancellation_date, cancellation_reason, cancellation_method, cancellation_note FROM {app}.policies WHERE policy_id = ?", [$policy_id]);
    jsonResponse(200, 'Endorsements retrieved', [
        'items' => array_map('endShape', $rows),
        'summary' => endPolicySummary($conn, $policy) + ['debit_note_number' => $p['debit_note_number']],
        'cancellation' => $p['cancellation_date'] ? [
            'date' => $p['cancellation_date'], 'reason' => $p['cancellation_reason'],
            'method' => $p['cancellation_method'], 'note' => $p['cancellation_note'],
        ] : null,
    ]);
}

function listCompanyEndorsements($conn, string $company_id, array $q): void {
    $where = ['e.company_id = ?', 'e.is_void = 0'];
    $params = [$company_id];
    switch ($q['status'] ?? 'all') {
        case 'open_debit':  $where[] = "e.note_type = 'debit' AND e.settled_date IS NULL"; break;
        case 'open_credit': $where[] = "e.note_type = 'credit' AND e.settled_date IS NULL"; break;
        case 'commission_pending': $where[] = "e.commission_status = 'pending'"; break;
    }
    if (!empty($q['policy_id'])) { $where[] = 'e.policy_id = ?'; $params[] = (string)$q['policy_id']; }
    if (!empty($q['insurer_id'])) { $where[] = 'p.insurer_id = ?'; $params[] = (string)$q['insurer_id']; }
    if (!empty($q['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$q['month'])) { $where[] = "DATE_FORMAT(e.effective_date, '%Y-%m') = ?"; $params[] = $q['month']; }
    $rows = dbAll($conn, "SELECT e.*, p.policy_number, p.product_type, c.display_name AS customer_name, i.short_name AS insurer_short_name
        FROM {app}.policy_endorsements e
        JOIN {app}.policies p ON p.policy_id = e.policy_id
        JOIN {app}.customers c ON c.customer_id = p.customer_id
        LEFT JOIN {app}.insurers i ON i.insurer_id = p.insurer_id
        WHERE " . implode(' AND ', $where) . " ORDER BY e.effective_date DESC, e.created_at DESC LIMIT 500", $params);
    $items = array_map('endShape', $rows);
    $sum = fn(callable $f) => array_sum(array_map($f, $items));
    jsonResponse(200, 'Endorsements retrieved', [
        'items' => $items,
        'summary' => [
            'count' => count($items),
            'debit_open' => $sum(fn($r) => $r['note_type'] === 'debit' && !$r['is_settled'] ? $r['gross_amount'] : 0),
            'credit_open' => $sum(fn($r) => $r['note_type'] === 'credit' && !$r['is_settled'] ? -$r['gross_amount'] : 0),
            'commission_pending' => $sum(fn($r) => $r['commission_status'] === 'pending' ? $r['commission_delta'] : 0),
        ],
    ]);
}

/** Validates a manual ledger request. */
function endParseManual(array $in): array {
    return [
        'endorsement_type'     => inEnum($in, 'endorsement_type', END_TYPES) ?? 'other',
        'effective_date'       => inDate($in, 'effective_date', true),
        'endorsement_number'   => inText($in, 'endorsement_number', 60),
        'description'          => inText($in, 'description', 1000),
        'calc_method'          => inEnum($in, 'calc_method', END_CALC_METHODS) ?? 'pro_rata',
        'annual_premium_delta' => inMoney($in, 'annual_premium_delta', false, true) ?? 0,
        'premium_delta'        => inMoney($in, 'premium_delta', false, true),
        'sum_insured_delta'    => inMoney($in, 'sum_insured_delta', false, true) ?? 0,
        'materai_amount'       => inMoney($in, 'endorsement_materai') ?? 0,
        'biaya_polis'          => inMoney($in, 'endorsement_biaya_polis') ?? 0,
        'due_date'             => inDate($in, 'due_date'),
        'apply_to_contract'    => inBool($in, 'apply_to_contract') === 1,
    ];
}

function previewEndorsement($conn, string $company_id, string $policy_id, array $in): void {
    $policy = endFetchPolicy($conn, $company_id, $policy_id);
    $e = endParseManual($in + ['effective_date' => $in['effective_date'] ?? date('Y-m-d')]);
    if ($e['calc_method'] === 'manual' && $e['premium_delta'] === null) throw ApiException::from('ERR_V21_002', ['field' => 'premium_delta']);
    $eff = max($e['effective_date'], $policy['coverage_start']);
    $premium = endChargedPremium($e['calc_method'], $e['annual_premium_delta'], $policy['coverage_start'], $policy['coverage_end'], $eff, $e['premium_delta']);
    [$comm, $tax] = endCommission($premium, (float)$policy['commission_rate'], (float)$policy['commission_tax_rate']);
    jsonResponse(200, 'Endorsement preview', [
        'premium_delta' => $premium, 'commission_delta' => $comm, 'commission_tax_delta' => $tax,
        'gross_amount' => $premium + $e['materai_amount'] + $e['biaya_polis'],
        'pro_rata_factor' => round(endProRataFactor($policy['coverage_start'], $policy['coverage_end'], $eff), 4),
        'remaining_days' => max(0, endDays($eff, $policy['coverage_end'])),
        'period_days' => max(1, endDays($policy['coverage_start'], $policy['coverage_end'])),
        'short_period_percent' => $e['annual_premium_delta'] >= 0
            ? shortPeriodPercent(endMonthsCeil($eff, $policy['coverage_end']))
            : shortPeriodPercent(endMonthsCeil($policy['coverage_start'], $eff)),
    ]);
}

function addEndorsement($conn, string $company_id, string $policy_id, array $in, string $username): void {
    $policy = endFetchPolicy($conn, $company_id, $policy_id);
    if ($policy['renewal_status'] === 'cancelled') throw ApiException::from('ERR_V21_014');
    $e = endParseManual($in);
    if ($e['calc_method'] === 'manual' && $e['premium_delta'] === null) throw ApiException::from('ERR_V21_002', ['field' => 'premium_delta']);
    if ($e['effective_date'] < $policy['coverage_start'] || $e['effective_date'] > $policy['coverage_end']) {
        throw ApiException::from('ERR_V21_013', ['start' => $policy['coverage_start'], 'end' => $policy['coverage_end']]);
    }

    $row = dbTransaction($conn, function () use ($conn, $policy, $e, $username, $policy_id, $company_id) {
        // Applying to the contract moves the current annual figures (only for policies priced without coverage items;
        // policies with items change through the items, which bill themselves).
        if ($e['apply_to_contract']) {
            $hasItems = (int)dbValue($conn, "SELECT COUNT(*) FROM {app}.policy_coverages WHERE policy_id = ?", [$policy_id]) > 0;
            if (!$hasItems && ($e['annual_premium_delta'] !== 0 || $e['sum_insured_delta'] !== 0)) {
                dbExec($conn, "UPDATE {app}.policies SET premium_amount = GREATEST(0, premium_amount + ?), sum_insured = GREATEST(0, sum_insured + ?),
                        commission_amount = ROUND(GREATEST(0, premium_amount) * commission_rate / 100) WHERE policy_id = ?",
                    [$e['annual_premium_delta'], $e['sum_insured_delta'], $policy_id]);
                dbExec($conn, "UPDATE {app}.policies SET commission_tax_amount = ROUND(commission_amount * commission_tax_rate),
                        net_commission_amount = commission_amount - ROUND(commission_amount * commission_tax_rate),
                        customer_premium_amount = premium_amount + materai_amount + biaya_polis - commission_amount - diskon WHERE policy_id = ?", [$policy_id]);
            }
        }
        $row = endRecord($conn, $policy, $e + ['source' => 'manual', 'force' => true], $username);
        insertPolicyLog($conn, $policy_id, $company_id, 'endorsement',
            'Endorsemen: ' . ($e['description'] ?? $e['endorsement_type']) . ' — ' . ($row['note_number'] ?? 'tanpa nota')
            . ' ' . ($row['gross_amount'] < 0 ? 'pengembalian ' : '') . 'Rp ' . number_format(abs($row['gross_amount']), 0, ',', '.'),
            $username, null, null, 'policy_endorsements', $row['endorsement_id'],
            ['kind' => 'endorsement', 'endorsement_number' => $e['endorsement_number'], 'effective_date' => $e['effective_date'],
             'premium_delta' => $row['premium_delta'], 'note_number' => $row['note_number']]);
        return $row;
    });
    jsonResponse(201, 'Endorsement recorded', $row);
}

function updateEndorsement($conn, string $company_id, string $policy_id, string $endorsement_id, array $in, string $username): void {
    $row = endFetch($conn, $company_id, $endorsement_id);
    if (!$row || $row['policy_id'] !== $policy_id) throw ApiException::from('ERR_V21_004');

    $set = inPresent($in, [
        'endorsement_number' => fn($i) => inText($i, 'endorsement_number', 60),
        'description' => fn($i) => inText($i, 'description', 1000),
        'due_date' => fn($i) => inDate($i, 'due_date'),
        'settled_date' => fn($i) => inDate($i, 'settled_date'),
        'settled_reference' => fn($i) => inText($i, 'settled_reference', 100),
        'commission_status' => fn($i) => inEnum($i, 'commission_status', END_COMMISSION_STATUSES, true),
        'commission_settled_date' => fn($i) => inDate($i, 'commission_settled_date'),
    ]);
    if (array_key_exists('is_void', $in)) $set['is_void'] = inBool($in, 'is_void') ?? 0;
    if (isset($set['commission_status']) && in_array($set['commission_status'], ['received', 'clawed_back'], true) && empty($set['commission_settled_date'])) {
        $set['commission_settled_date'] = date('Y-m-d');
    }
    if (!$set) throw ApiException::from('ERR_V21_002', ['field' => 'data']);
    $set['updated_by'] = $username;
    dbUpdate($conn, 'policy_endorsements', $set, ['endorsement_id' => $endorsement_id, 'company_id' => $company_id]);

    $what = [];
    if (array_key_exists('settled_date', $set)) $what[] = $set['settled_date'] ? (($row['note_type'] === 'credit' ? 'dikembalikan ' : 'dibayar ') . $set['settled_date']) : 'pelunasan dibatalkan';
    if (isset($set['commission_status'])) $what[] = 'komisi ' . $set['commission_status'];
    if (!empty($set['is_void'])) $what[] = 'dibatalkan (void)';
    if ($what) {
        insertPolicyLog($conn, $policy_id, $company_id, 'endorsement', 'Nota ' . ($row['note_number'] ?? $endorsement_id) . ': ' . implode(', ', $what),
            $username, null, null, 'policy_endorsements', $endorsement_id, ['kind' => 'endorsement']);
    }
    jsonResponse(200, 'Endorsement updated', endFetch($conn, $company_id, $endorsement_id));
}

// ── Cancellation ─────────────────────────────────────────────────────────────

function cancelParse(array $in, array $policy): array {
    $c = [
        'cancellation_date' => inDate($in, 'cancellation_date', true),
        'reason' => inEnum($in, 'reason', CANCELLATION_REASONS, true),
        'method' => inEnum($in, 'method', CANCELLATION_METHODS, true),
        'refund_amount' => inMoney($in, 'refund_amount'),
        'note' => inText($in, 'note', 500),
        'endorsement_number' => inText($in, 'endorsement_number', 60),
    ];
    if ($c['cancellation_date'] < $policy['coverage_start'] || $c['cancellation_date'] > $policy['coverage_end']) {
        throw ApiException::from('ERR_V21_013', ['start' => $policy['coverage_start'], 'end' => $policy['coverage_end']]);
    }
    if ($c['method'] === 'manual' && $c['refund_amount'] === null) throw ApiException::from('ERR_V21_002', ['field' => 'refund_amount']);
    return $c;
}

/** Premium the customer was billed in total: the original note plus every endorsement note. */
function cancelBilledPremium($conn, array $policy): int {
    return endPolicySummary($conn, $policy)['billed_premium'];
}

function previewCancellation($conn, string $company_id, string $policy_id, array $in): void {
    $policy = endFetchPolicy($conn, $company_id, $policy_id);
    $c = cancelParse($in, $policy);
    $billed = cancelBilledPremium($conn, $policy);
    $refund = cancellationRefund($c['method'], $billed, $policy['coverage_start'], $policy['coverage_end'], $c['cancellation_date'], $c['refund_amount']);
    [$comm, $tax] = endCommission(-$refund, (float)$policy['commission_rate'], (float)$policy['commission_tax_rate']);
    jsonResponse(200, 'Cancellation preview', [
        'billed_premium' => $billed, 'refund_amount' => $refund, 'retained_premium' => $billed - $refund,
        'commission_clawback' => -$comm, 'commission_tax_clawback' => -$tax,
        'months_on_risk' => endMonthsCeil($policy['coverage_start'], $c['cancellation_date']),
        'short_period_percent' => shortPeriodPercent(endMonthsCeil($policy['coverage_start'], $c['cancellation_date'])),
        'pro_rata_factor' => round(endProRataFactor($policy['coverage_start'], $policy['coverage_end'], $c['cancellation_date']), 4),
        'payment_status' => $policy['payment_status'],
    ]);
}

function cancelPolicy($conn, string $company_id, string $policy_id, array $in, string $username): void {
    $policy = endFetchPolicy($conn, $company_id, $policy_id);
    if ($policy['renewal_status'] === 'cancelled') throw ApiException::from('ERR_V21_005');
    $c = cancelParse($in, $policy);
    $billed = cancelBilledPremium($conn, $policy);
    $refund = cancellationRefund($c['method'], $billed, $policy['coverage_start'], $policy['coverage_end'], $c['cancellation_date'], $c['refund_amount']);
    $reasonLabels = ['insured_request' => 'permintaan tertanggung', 'non_payment' => 'premi tidak dibayar', 'sold' => 'objek dijual',
                     'moved_insurer' => 'pindah asuransi', 'insurer_request' => 'permintaan asuransi', 'other' => 'lainnya'];

    $row = dbTransaction($conn, function () use ($conn, $policy, $c, $refund, $username, $policy_id, $company_id, $reasonLabels) {
        $row = endRecord($conn, $policy, [
            'endorsement_type' => 'cancellation', 'effective_date' => $c['cancellation_date'],
            'endorsement_number' => $c['endorsement_number'], 'calc_method' => 'manual', 'premium_delta' => -$refund,
            'annual_premium_delta' => 0, 'sum_insured_delta' => -(int)$policy['sum_insured'],
            'description' => 'Pembatalan polis (' . $reasonLabels[$c['reason']] . ', ' . $c['method'] . ')' . ($c['note'] ? ': ' . $c['note'] : ''),
            'due_date' => $refund > 0 ? date('Y-m-d', strtotime('+14 days')) : null,
            'source' => 'cancellation', 'force' => true,
        ], $username);

        // Cancelled from inception: the original commission is never earned.
        if ($c['method'] === 'full') {
            dbExec($conn, "UPDATE {app}.commissions SET status = 'cancelled', updated_at = NOW() WHERE policy_id = ? AND status = 'pending'", [$policy_id]);
            dbExec($conn, "UPDATE {app}.policy_endorsements SET commission_status = 'waived' WHERE endorsement_id = ?", [$row['endorsement_id']]);
        }

        dbExec($conn, "UPDATE {app}.policies SET renewal_status = 'cancelled', cancellation_date = ?, cancellation_reason = ?, cancellation_method = ?,
                cancellation_note = ?, updated_by = ?, updated_at = NOW() WHERE policy_id = ? AND company_id = ?",
            [$c['cancellation_date'], $c['reason'], $c['method'], $c['note'], $username, $policy_id, $company_id]);

        // Banks and leasing companies with an interest must be told.
        foreach (dbAll($conn, "SELECT party_id, name, party_type FROM {app}.policy_parties WHERE policy_id = ?", [$policy_id]) as $party) {
            dbInsert($conn, 'tasks', [
                'task_id' => dbNewId('tsk'), 'company_id' => $company_id,
                'title' => mb_substr('Kirim pemberitahuan pembatalan polis ' . $policy['policy_number'] . ' ke ' . $party['name'], 0, 200),
                'notes' => 'Polis dibatalkan per ' . $c['cancellation_date'] . '. ' . (PARTY_TYPE_LABELS[$party['party_type']] ?? '') . ' perlu menerima pemberitahuan tertulis.',
                'due_date' => date('Y-m-d', strtotime('+3 days')), 'status' => 'open',
                'customer_id' => dbValue($conn, "SELECT customer_id FROM {app}.policies WHERE policy_id = ?", [$policy_id]),
                'policy_id' => $policy_id, 'created_by' => $username,
            ]);
        }

        insertPolicyLog($conn, $policy_id, $company_id, 'renewal_status_changed',
            'Polis dibatalkan per ' . $c['cancellation_date'] . ' (' . $reasonLabels[$c['reason']] . '). Pengembalian premi Rp ' . number_format($refund, 0, ',', '.'),
            $username, $policy['renewal_status'], 'cancelled', 'policy_endorsements', $row['endorsement_id'],
            ['kind' => 'cancellation', 'method' => $c['method'], 'refund' => $refund, 'note_number' => $row['note_number']]);
        return $row;
    });

    jsonResponse(200, 'Policy cancelled', ['endorsement' => $row, 'refund_amount' => $refund]);
}
