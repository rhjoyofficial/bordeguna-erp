<?php

namespace App\Modules\Hrm\Payroll\Data;

/**
 * The finished payslip — the payroll-ready result of one employee-period.
 *
 * Carries its own derivation so a figure stays explainable after the rules have
 * moved on, which is what PAY-13 asks for and what P2's calculation-provenance
 * pattern requires of every derived money amount.
 */
final readonly class PayslipOutcome
{
    /**
     * @param  array<int, PayslipLineData>  $lines
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $blockReasons
     */
    public function __construct(
        public int $employeeId,
        public PayrollPeriodSpec $period,
        public array $lines,
        /** Gross from the salary structure, before anything the month did. */
        public float $grossSalary,
        public float $totalEarnings,
        public float $totalDeductions,
        public float $net,
        public bool $isBlocked,
        public array $blockReasons,
        public array $notes,
        /** @var array<string, mixed> */
        public array $config,
    ) {}

    public function line(string $code): ?PayslipLineData
    {
        foreach ($this->lines as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }

    public function hasLine(string $code): bool
    {
        return $this->line($code) !== null;
    }

    /**
     * @return array<int, PayslipLineData>
     */
    public function earnings(): array
    {
        return array_values(array_filter($this->lines, fn (PayslipLineData $l) => $l->isEarning()));
    }

    /**
     * @return array<int, PayslipLineData>
     */
    public function deductions(): array
    {
        return array_values(array_filter($this->lines, fn (PayslipLineData $l) => $l->isDeduction()));
    }

    /**
     * The stored derivation — what persistence writes and a payslip explains
     * from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'period' => [
                'year' => $this->period->year,
                'month' => $this->period->month,
                'start' => $this->period->start->toDateString(),
                'end' => $this->period->end->toDateString(),
                'divisor_days' => $this->period->divisorDays,
            ],
            'gross_salary' => $this->grossSalary,
            'total_earnings' => $this->totalEarnings,
            'total_deductions' => $this->totalDeductions,
            'net' => $this->net,
            'is_blocked' => $this->isBlocked,
            'block_reasons' => $this->blockReasons,
            'notes' => $this->notes,
            'config' => $this->config,
            'lines' => array_map(fn (PayslipLineData $line) => $line->toArray(), $this->lines),
        ];
    }
}
