<?php

// Premium payment rules shared by policies/payments.php and the policy list.

// Premium payment warranty: how long after inception the premium may stay unpaid.
const PAYMENT_WARRANTY_DAYS = 30;

// Days overdue after which an unpaid policy is flagged as at risk of cancellation.
const PAYMENT_CANCELLATION_RISK_DAYS = 14;

// Days before the due date at which a premium counts as "due soon".
const PAYMENT_DUE_SOON_DAYS = 7;

/** SQL expression for the effective policy-level due date (alias p). */
function paymentDueExpr(string $alias = 'p'): string {
    return "COALESCE($alias.payment_due_date, DATE_ADD($alias.coverage_start, INTERVAL " . PAYMENT_WARRANTY_DAYS . " DAY))";
}

/** overdue | cancellation_risk | due_soon | upcoming, from days until due (negative = overdue). */
function paymentUrgency(int $days_until_due): string {
    if ($days_until_due < -PAYMENT_CANCELLATION_RISK_DAYS) return 'cancellation_risk';
    if ($days_until_due < 0) return 'overdue';
    if ($days_until_due <= PAYMENT_DUE_SOON_DAYS) return 'due_soon';
    return 'upcoming';
}
