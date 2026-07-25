<?php

namespace App\Modules\Hrm\Payroll\Data;

use App\Modules\Hrm\Payroll\PayrollConfig;
use App\Modules\Hrm\Salary\SalaryBreakdown;
use Illuminate\Support\Carbon;

/**
 * Everything one employee's payslip needs — the complete, immutable input to
 * the engine, and PAY-12's cutoff snapshot.
 *
 * Once this exists the payslip is fully determined: no stage reads the
 * database, the clock or anything else. That is what makes a payroll
 * reproducible months later, what lets the whole rule set be tested from
 * fixtures, and what PAY-12 means when it says an approved payroll must never
 * silently recalculate — the snapshot is the record, not the live data.
 */
final readonly class EmployeePayrollContext
{
    /**
     * @param  array<int, PayrollInput>  $allowances  bonuses and allowances (ADJ-05)
     * @param  array<int, PayrollInput>  $loanInstallments  ADJ-07 — supplied by Phase 3e
     * @param  array<int, PayrollInput>  $adjustments  ADJ-08 — supplied by Phase 3d
     */
    public function __construct(
        public int $employeeId,
        public PayrollPeriodSpec $period,
        public SalaryBreakdown $salary,
        public AttendanceFacts $attendance,
        public float $overtimeHours,
        public array $allowances,
        public array $loanInstallments,
        public array $adjustments,
        public PayrollConfig $config,
        public ?string $joiningDate = null,
        public ?string $separationDate = null,
    ) {}

    /**
     * PAY-02: one day's pay is Gross ÷ 30, never Gross ÷ the number of days the
     * period happens to span.
     */
    public function dailyRate(): float
    {
        $base = $this->config->absenceRateBase === 'basic' ? $this->salary->basic : $this->salary->gross;

        return $base / max(1, $this->config->divisorDays);
    }

    public function dailyRateBaseAmount(): float
    {
        return $this->config->absenceRateBase === 'basic' ? $this->salary->basic : $this->salary->gross;
    }

    /**
     * ADJ-02 through Correction C7a: Basic ÷ divisor ÷ standard hours by
     * default, with the base configurable.
     */
    public function overtimeHourlyRate(): float
    {
        $base = $this->config->overtimeRateBase === 'gross' ? $this->salary->gross : $this->salary->basic;

        return $base
            / max(1, $this->config->divisorDays)
            / max(0.01, $this->config->standardWorkingHours)
            * $this->config->overtimeMultiplier;
    }

    public function overtimeRateBaseAmount(): float
    {
        return $this->config->overtimeRateBase === 'gross' ? $this->salary->gross : $this->salary->basic;
    }

    public function joining(): ?Carbon
    {
        return $this->joiningDate === null ? null : Carbon::parse($this->joiningDate)->startOfDay();
    }

    public function separation(): ?Carbon
    {
        return $this->separationDate === null ? null : Carbon::parse($this->separationDate)->startOfDay();
    }

    /**
     * Whether the employee was on the books for only part of the period
     * (PAY-05 joining, PAY-06 separation).
     */
    public function isPartialPeriod(): bool
    {
        return $this->period->employedDays($this->joining(), $this->separation()) < $this->period->calendarDays();
    }
}
