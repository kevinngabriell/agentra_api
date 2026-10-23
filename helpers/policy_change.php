<?php

// How a change to an issued policy is classified, locked and logged.
//
//   correction   the agent fixes their own input mistake. No new policy number, nothing for the customer to
//                acknowledge. A reason is required. Logged as 'policy_updated' with metadata.kind = 'correction'.
//   endorsement  the insurer changed the contract. Logged as 'endorsement'; the insurer's endorsement number and
//                effective date are kept in the log metadata when given.
//
// Request fields (JSON body): change_kind, reason, endorsement_number, effective_date.
// Corrections that move money or the customer are refused once the policy is paid, has a claim, or was renewed:
// from then on other records depend on the numbers, so the change has to be an endorsement.

const CHANGE_KINDS       = ['correction', 'endorsement'];
const CHANGE_REASON_MIN  = 5;
const CHANGE_REASON_MAX  = 500;

/** Reads and validates the change fields. `$default` is used when the request names no kind. */
function policyChangeParse(array $input, string $default = 'endorsement'): array {
    $kind = $input['change_kind'] ?? $default;
    if (!is_string($kind) || !in_array($kind, CHANGE_KINDS, true)) throw ApiException::from('ERR_CHG_001');

    $get = function (string $key, int $max) use ($input): ?string {
        $v = $input[$key] ?? null;
        if ($v === null) return null;
        if (!is_scalar($v) || is_bool($v)) throw ApiException::from('ERR_CHG_004', ['field' => $key]);
        $s = trim((string)$v);
        if ($s === '') return null;
        if (mb_strlen($s) > $max) throw ApiException::from('ERR_CHG_005', ['field' => $key, 'max' => $max]);
        return $s;
    };

    $reason = $get('reason', CHANGE_REASON_MAX);
    if ($kind === 'correction' && ($reason === null || mb_strlen($reason) < CHANGE_REASON_MIN)) {
        throw ApiException::from('ERR_CHG_002', ['min' => CHANGE_REASON_MIN]);
    }

    $number = $kind === 'endorsement' ? $get('endorsement_number', 60) : null;
    $date   = $kind === 'endorsement' ? $get('effective_date', 10) : null;
    if ($date !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)))) {
        throw ApiException::from('ERR_CHG_004', ['field' => 'effective_date']);
    }

    return ['kind' => $kind, 'reason' => $reason, 'endorsement_number' => $number, 'effective_date' => $date];
}

/** Why a correction of money or the customer is no longer allowed, or null when it still is. */
function policyChangeLockReason($conn, string $policy_id, string $company_id): ?string {
    $pid = mysqli_real_escape_string($conn, $policy_id);
    $cid = mysqli_real_escape_string($conn, $company_id);

    $res = mysqli_query($conn, "SELECT payment_status, renewal_status FROM " . APP_SCHEMA . ".policies WHERE policy_id = '$pid' AND company_id = '$cid' LIMIT 1");
    $p   = $res ? mysqli_fetch_assoc($res) : null;
    if (!$p) return null;

    $paidParts = mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".policy_installments WHERE policy_id = '$pid' AND paid_date IS NOT NULL");
    if ($p['payment_status'] === 'paid' || ($paidParts && (int)mysqli_fetch_assoc($paidParts)['n'] > 0)) return 'premi sudah dibayar';

    $claims = mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".claims WHERE policy_id = '$pid' AND company_id = '$cid' AND is_deleted = 0");
    if ($claims && (int)mysqli_fetch_assoc($claims)['n'] > 0) return 'sudah ada klaim';

    $renewed = mysqli_query($conn, "SELECT COUNT(*) AS n FROM " . APP_SCHEMA . ".policies WHERE previous_policy_id = '$pid' AND company_id = '$cid'");
    if ($p['renewal_status'] === 'renewed' || ($renewed && (int)mysqli_fetch_assoc($renewed)['n'] > 0)) return 'polis sudah diperpanjang';

    return null;
}

/** Throws when `$change` is a correction of something the policy's state no longer allows. */
function policyChangeGuard($conn, string $policy_id, string $company_id, array $change): void {
    if ($change['kind'] !== 'correction') return;
    $why = policyChangeLockReason($conn, $policy_id, $company_id);
    if ($why !== null) throw ApiException::from('ERR_CHG_003', ['why' => $why]);
}

/**
 * Writes the policy log entry for a change. `$text` is the part after "Koreksi: " / "Endorsemen: ".
 * `$meta` is merged with the change fields.
 */
function policyChangeLog($conn, string $policy_id, string $company_id, array $change, string $text, string $username,
                         ?string $ref_type = null, ?string $ref_id = null, array $meta = []): void {
    $isCorrection = $change['kind'] === 'correction';
    $meta['kind'] = $change['kind'];
    foreach (['reason', 'endorsement_number', 'effective_date'] as $k) {
        if (($change[$k] ?? null) !== null) $meta[$k] = $change[$k];
    }
    insertPolicyLog($conn, $policy_id, $company_id, $isCorrection ? 'policy_updated' : 'endorsement',
        ($isCorrection ? 'Koreksi: ' : 'Endorsemen: ') . $text, $username, null, null, $ref_type, $ref_id, $meta);
}
