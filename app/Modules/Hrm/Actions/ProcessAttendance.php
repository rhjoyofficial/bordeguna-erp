<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Attendance\AttendanceEngine;
use App\Modules\Hrm\Attendance\Data\AttendanceOutcome;
use App\Modules\Hrm\Attendance\DayContextBuilder;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\ScheduleResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Gathers, computes and stores — the only place the three meet.
 *
 * The engine itself stays pure; this action is what gives it inputs and what
 * writes the answer down. Keeping persistence out here means a day can be
 * recalculated at will (processed attendance is derived, unlike the punches it
 * came from), and the rules can be tested with no database in sight.
 *
 * A month is processed in date order because POL-03's ladders escalate on a
 * running count — day 12's consequence depends on days 1-11 already being on
 * record.
 */
class ProcessAttendance
{
    public function __construct(
        private readonly DayContextBuilder $builder,
        private readonly AttendanceEngine $engine,
        private readonly ScheduleResolver $resolver,
    ) {}

    /**
     * Recompute one employee-day. Returns null when the day is finalised and
     * `$force` was not given — a human's decision is not overwritten silently
     * (POL-06).
     */
    public function forDay(Employee $employee, Carbon|string $date, bool $force = false): ?DailyAttendance
    {
        $date = Carbon::parse($date)->startOfDay();

        $existing = DailyAttendance::where('employee_id', $employee->id)->onDate($date)->first();

        if ($existing?->isFinalized() && ! $force) {
            return null;
        }

        $policy = AttendancePolicy::inForceOn($date);
        $outcome = $this->engine->process($this->builder->build($employee, $date, $policy));

        return $this->store($employee, $outcome, $policy);
    }

    /**
     * Every employee working in the business on a date.
     *
     * @return Collection<int, DailyAttendance>
     */
    public function forDate(Carbon|string $date, bool $force = false): Collection
    {
        $date = Carbon::parse($date)->startOfDay();

        return Employee::whereHas('assignments', fn ($query) => $query->activeOn($date)->where('is_primary', true))
            ->orderBy('id')->get()
            ->map(fn (Employee $employee) => $this->forDay($employee, $date, $force))
            ->filter()
            ->values();
    }

    /**
     * A date range for one employee, in order, so the occurrence counters build
     * up exactly as they would have day by day.
     *
     * @return Collection<int, DailyAttendance>
     */
    public function forRange(Employee $employee, Carbon|string $from, Carbon|string $to, bool $force = false): Collection
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        $results = collect();

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $result = $this->forDay($employee, $date, $force);

            if ($result !== null) {
                $results->push($result);
            }
        }

        return $results;
    }

    private function store(Employee $employee, AttendanceOutcome $outcome, ?AttendancePolicy $policy): DailyAttendance
    {
        $assignment = $this->resolver->resolveFor($employee, $outcome->date);

        // Matched with whereDate rather than updateOrCreate: `attendance_date`
        // is a date cast, so it is stored as "Y-m-d 00:00:00" and an equality
        // match against "Y-m-d" silently misses, then collides with the unique
        // index on insert.
        $existing = DailyAttendance::where('employee_id', $employee->id)
            ->onDate($outcome->date)
            ->first();

        $attributes = array_merge(
            [
                'employee_id' => $employee->id,
                'attendance_date' => $outcome->date,
            ],
            [
                'status' => $outcome->status,
                'consequence' => $outcome->consequence,
                'first_in' => $outcome->firstIn,
                'last_out' => $outcome->lastOut,
                'worked_minutes' => $outcome->workedMinutes,
                'break_minutes' => $outcome->breakMinutes,
                'payable_minutes' => $outcome->payableMinutes,
                'late_minutes' => $outcome->lateMinutes,
                'early_leave_minutes' => $outcome->earlyLeaveMinutes,
                'grace_window_key' => $outcome->graceWindowKey,
                'occurrence_number' => $outcome->occurrenceNumber,
                'is_provisional' => $outcome->isProvisional,
                'holds_payroll' => $outcome->holdsPayroll,
                'flags' => $outcome->flags,
                'calculation' => $outcome->provenance(),
                // Provenance: which rules produced this, captured at the time.
                // After a POL-12 revision the current policy is no longer the
                // one that judged this day.
                'policy_id' => $policy?->id,
                'policy_version' => $policy?->version,
                'shift_id' => $assignment?->shift_id,
                'processed_at' => now(),
                // A recalculation re-opens the day for review; finalising is
                // always a fresh human decision.
                'finalized_at' => null,
                'finalized_by' => null,
            ],
        );

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return DailyAttendance::create($attributes);
    }
}
