<?php

namespace App\Modules\Hrm\Salary;

use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Enums\SalaryComponentType;

/**
 * One component of a salary structure with its figure worked out.
 *
 * Carries the formula alongside the amount, because PAY-13 requires every
 * amount to store its source, formula and quantity/rate — a payslip line that
 * holds only a number cannot explain itself once the rules move on. Phase 3b
 * copies these straight onto the payslip.
 */
final readonly class ResolvedSalaryLine
{
    public function __construct(
        public int $componentId,
        public string $code,
        public string $name,
        public SalaryComponentType $type,
        public SalaryCalculationBasis $basis,
        public bool $isBasic,
        public bool $isStatutory,
        /** The stored input: an amount for a fixed component, otherwise a percentage. */
        public float $value,
        /** The resolved money amount, rounded to 2 decimals per PAY-10. */
        public float $amount,
        public string $formula,
    ) {}

    public function isEarning(): bool
    {
        return $this->type === SalaryComponentType::Earning;
    }

    public function isDeduction(): bool
    {
        return $this->type === SalaryComponentType::Deduction;
    }

    /**
     * Whether this line's amount is decided by a payroll period's attendance
     * rather than by the structure alone (Correction C14).
     *
     * Such a line arrives here resolved to 0.00 with the rate kept on `value`,
     * is excluded from Gross, and is priced by the engine's
     * `ApplyAttendanceAllowances` stage. `ResolveSalaryLines` skips it for
     * exactly that reason — copying it would put a 0.00 line on the payslip
     * that the allowance stage then duplicates.
     */
    public function isPricedByAttendance(): bool
    {
        return $this->basis->isPricedByAttendance();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component_id' => $this->componentId,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'basis' => $this->basis->value,
            'is_basic' => $this->isBasic,
            'is_statutory' => $this->isStatutory,
            'value' => $this->value,
            'amount' => $this->amount,
            'formula' => $this->formula,
        ];
    }
}
