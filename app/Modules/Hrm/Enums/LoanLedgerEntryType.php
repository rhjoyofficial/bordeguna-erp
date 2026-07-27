<?php

namespace App\Modules\Hrm\Enums;

/**
 * What moved a loan balance.
 *
 * Four kinds, and each names a genuinely different event rather than a
 * different sign. "Repayment 5,000" and "Early settlement 5,000" reduce the
 * balance identically and mean quite different things to the person who owed it
 * — which is the same reason ADJ-08 named six adjustment types instead of one
 * signed amount.
 *
 * `RepaymentReversed` arrives with Correction C9c: a salary payment that failed
 * never took the installment, so the debt comes back. It is a compensating
 * entry, not a deletion — the ledger stays append-only, and "we collected it,
 * then the transfer bounced" reads as the two events it actually was.
 */
enum LoanLedgerEntryType: string
{
    case Disbursement = 'disbursement';
    case Repayment = 'repayment';
    case RepaymentReversed = 'repayment_reversed';
    case EarlySettlement = 'early_settlement';

    public function label(): string
    {
        return match ($this) {
            self::Disbursement => 'Disbursed',
            self::Repayment => 'Payroll repayment',
            self::RepaymentReversed => 'Repayment reversed',
            self::EarlySettlement => 'Early settlement',
        };
    }

    /**
     * Whether entries of this type increase what is owed. Repayments and
     * settlements are stored negative, so this is about intent rather than sign
     * — it is what the ledger view uses to explain a movement.
     */
    public function increasesDebt(): bool
    {
        return $this === self::Disbursement || $this === self::RepaymentReversed;
    }
}
