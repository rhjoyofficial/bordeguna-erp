<?php

namespace App\Modules\Accounting\Enums;

/**
 * What one register movement is. Each has its own journal shape (see
 * Actions\Loans), and each journal goes through PostJournal.
 */
enum LoanEntryKind: string
{
    case Disbursement = 'disbursement';
    case Receipt = 'receipt';
    case Repayment = 'repayment';
    case Accrual = 'accrual';
    case AccrualAdjustment = 'accrual_adjustment';
    case WriteOff = 'write_off';
    case Waiver = 'waiver';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Disbursement => 'Disbursed',
            self::Receipt => 'Loan received',
            self::Repayment => 'Repayment',
            self::Accrual => 'Interest accrued',
            self::AccrualAdjustment => 'Accrual adjustment',
            self::WriteOff => 'Principal written off',
            self::Waiver => 'Interest waived',
            self::Manual => 'Voucher naming this loan',
        };
    }

    /** Recorded from the loan page and reversed there (a manual one by reversing its voucher). */
    public function reversibleHere(): bool
    {
        return $this !== self::Manual;
    }

    public function isAccrual(): bool
    {
        return in_array($this, [self::Accrual, self::AccrualAdjustment], true);
    }
}
