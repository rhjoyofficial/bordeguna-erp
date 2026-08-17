<?php

namespace App\Modules\Hrm\Attendance\Data;

use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use Illuminate\Support\Carbon;

/**
 * The payroll-ready result of one employee-day — pipeline stage 9.
 *
 * This is what persistence stores and what Phase-3 payroll consumes. It carries
 * its own derivation (`notes`) so a payslip line traces back to the rules that
 * produced it without re-running anything, which is the calculation-provenance
 * requirement the plan sets for every derived figure.
 */
final readonly class AttendanceOutcome
{
    /**
     * @param  array<int, string>  $notes
     * @param  array<int, string>  $flags
     * @param  array<int, array{in: string, out: string|null, minutes: int}>  $pairs
     * @param  array<string, mixed>|null  $meal  the meal resolver's structured result (C21) — how the break was resolved, from what, and any overrun
     */
    public function __construct(
        public int $employeeId,
        public Carbon $date,
        public AttendanceStatus $status,
        public AttendanceConsequence $consequence,
        public ?Carbon $firstIn,
        public ?Carbon $lastOut,
        public int $workedMinutes,
        public int $breakMinutes,
        public int $payableMinutes,
        public int $lateMinutes,
        public int $earlyLeaveMinutes,
        public ?string $graceWindowKey,
        public ?string $graceWindowLabel,
        public ?int $occurrenceNumber,
        public bool $isProvisional,
        public bool $holdsPayroll,
        public array $pairs,
        public array $notes,
        public array $flags,
        public ?array $meal = null,
    ) {}

    public function workedHours(): float
    {
        return round($this->workedMinutes / 60, 2);
    }

    public function payableHours(): float
    {
        return round($this->payableMinutes / 60, 2);
    }

    /**
     * The stored derivation — every figure plus the reasoning behind it.
     *
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return [
            'notes' => $this->notes,
            'flags' => $this->flags,
            'pairs' => $this->pairs,
            'late_minutes' => $this->lateMinutes,
            'early_leave_minutes' => $this->earlyLeaveMinutes,
            'grace_window' => $this->graceWindowKey,
            'occurrence_number' => $this->occurrenceNumber,
            'meal' => $this->meal,
        ];
    }
}
