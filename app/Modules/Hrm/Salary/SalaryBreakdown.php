<?php

namespace App\Modules\Hrm\Salary;

/**
 * A salary structure with every component resolved to money — the contractual
 * figures, before anything that happened in a particular month.
 *
 * This is deliberately *not* a payslip. It answers "what is this person paid",
 * not "what are they owed this month": no attendance, no absence, no overtime,
 * no loans. Phase 3b snapshots one of these at the payroll cutoff and applies
 * the month's facts to it, which keeps the contractual figure and the monthly
 * outcome cleanly separable — and keeps attendance logic out of Payroll.
 */
final readonly class SalaryBreakdown
{
    /**
     * @param  array<int, ResolvedSalaryLine>  $lines
     */
    public function __construct(
        public array $lines,
        public float $basic,
        public float $gross,
        public float $contractualDeductions,
    ) {}

    public static function empty(): self
    {
        return new self([], 0.0, 0.0, 0.0);
    }

    /**
     * @return array<int, ResolvedSalaryLine>
     */
    public function earnings(): array
    {
        return array_values(array_filter($this->lines, fn (ResolvedSalaryLine $line) => $line->isEarning()));
    }

    /**
     * @return array<int, ResolvedSalaryLine>
     */
    public function deductions(): array
    {
        return array_values(array_filter($this->lines, fn (ResolvedSalaryLine $line) => $line->isDeduction()));
    }

    /**
     * Net of the *contractual* figures only. A month's actual net additionally
     * reflects attendance, overtime, loans and adjustments — all of which are
     * Phase 3b's business, not this object's.
     */
    public function contractualNet(): float
    {
        return round($this->gross - $this->contractualDeductions, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'basic' => $this->basic,
            'gross' => $this->gross,
            'contractual_deductions' => $this->contractualDeductions,
            'contractual_net' => $this->contractualNet(),
            'lines' => array_map(fn (ResolvedSalaryLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
