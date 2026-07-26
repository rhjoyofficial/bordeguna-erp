<?php

namespace App\Modules\Hrm\Enums;

/**
 * The six adjustment types of ADJ-08, which ticked "সবগুলো" — all of them.
 *
 * They are kept distinct rather than collapsed into a signed amount because the
 * sign is the least interesting thing about an adjustment. "Recovery 3,200" and
 * "Penalty 3,200" deduct the same money and mean entirely different things, and
 * the person reading their payslip is asking which one it was.
 */
enum PayrollAdjustmentType: string
{
    case Addition = 'addition';
    case Deduction = 'deduction';
    case Arrear = 'arrear';
    case Reimbursement = 'reimbursement';
    case Penalty = 'penalty';
    case Recovery = 'recovery';

    public function label(): string
    {
        return match ($this) {
            self::Addition => 'Addition',
            self::Deduction => 'Deduction',
            self::Arrear => 'Arrear',
            self::Reimbursement => 'Reimbursement',
            self::Penalty => 'Penalty',
            self::Recovery => 'Recovery',
        };
    }

    /**
     * Which side of the payslip this falls on. Stored amounts are always
     * positive; this is what decides their direction.
     */
    public function componentType(): SalaryComponentType
    {
        return $this->isEarning() ? SalaryComponentType::Earning : SalaryComponentType::Deduction;
    }

    public function isEarning(): bool
    {
        return in_array($this, [self::Addition, self::Arrear, self::Reimbursement], true);
    }

    /**
     * An arrear is produced by an approved salary revision (SAL-05) rather than
     * typed in, so ADJ-09's attachment requirement would have nothing to attach.
     * Its evidence is the revision itself, which is stronger than a file.
     */
    public function isSystemGenerated(): bool
    {
        return $this === self::Arrear;
    }

    public function describe(): string
    {
        return match ($this) {
            self::Addition => 'An extra payment outside the salary structure.',
            self::Deduction => 'A one-off deduction outside the salary structure.',
            self::Arrear => 'A back-payment owed from an earlier period.',
            self::Reimbursement => 'Money the employee spent on the company\'s behalf.',
            self::Penalty => 'A disciplinary deduction.',
            self::Recovery => 'Money owed to the company being taken back.',
        };
    }
}
