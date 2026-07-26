<?php

namespace App\Modules\Hrm\Payroll\Data;

use App\Modules\Hrm\Enums\SalaryComponentType;

/**
 * A money item supplied to payroll from outside the salary structure — an
 * allowance or bonus (ADJ-05), a loan installment (ADJ-07), or an adjustment
 * or arrear (ADJ-08).
 *
 * The amount is always positive and the type says which side it falls on, so a
 * stray minus sign can never quietly flip a deduction into a payment.
 *
 * These arrive as plain values because the records that produce them are built
 * in later slices. The seam exists now for the same reason `DayContext` carried
 * `hasApprovedLeave` before Leave was built: filling it later must not mean
 * reopening the engine.
 */
final readonly class PayrollInput
{
    public function __construct(
        public string $code,
        public string $label,
        public float $amount,
        public SalaryComponentType $type,
        public ?string $note = null,
        public string $source = 'input',
        /**
         * Optional provenance for an input that is one of a counted series —
         * a loan installment is "3 of 12" at a stated rate, and PAY-13 asks for
         * the quantity and rate behind every amount, not just the amount.
         *
         * Null for a bonus or an adjustment, which are single sums with nothing
         * to count.
         */
        public ?float $quantity = null,
        public ?float $rate = null,
    ) {}

    public static function earning(string $code, string $label, float $amount, ?string $note = null, string $source = 'input'): self
    {
        return new self($code, $label, abs($amount), SalaryComponentType::Earning, $note, $source);
    }

    public static function deduction(
        string $code,
        string $label,
        float $amount,
        ?string $note = null,
        string $source = 'input',
        ?float $quantity = null,
        ?float $rate = null,
    ): self {
        return new self($code, $label, abs($amount), SalaryComponentType::Deduction, $note, $source, $quantity, $rate);
    }

    public function isEarning(): bool
    {
        return $this->type === SalaryComponentType::Earning;
    }
}
