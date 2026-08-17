<?php

namespace App\Modules\Hrm\Attendance;

use App\Modules\Hrm\Attendance\Contracts\AttendanceStage;
use App\Modules\Hrm\Attendance\Data\AttendanceComputation;
use App\Modules\Hrm\Attendance\Data\AttendanceOutcome;
use App\Modules\Hrm\Attendance\Data\DayContext;
use App\Modules\Hrm\Attendance\Data\PunchPair;
use App\Modules\Hrm\Attendance\Stages\ApplyGraceAndThresholds;
use App\Modules\Hrm\Attendance\Stages\ApplyOccurrencePolicy;
use App\Modules\Hrm\Attendance\Stages\CalculateWorkedTime;
use App\Modules\Hrm\Attendance\Stages\CollapseRepeatScans;
use App\Modules\Hrm\Attendance\Stages\DetermineStatus;
use App\Modules\Hrm\Attendance\Stages\PairPunches;
use App\Modules\Hrm\Attendance\Stages\ResolveMealBreak;
use App\Modules\Hrm\Enums\AttendanceStatus;

/**
 * Turns one immutable day of inputs into one payroll-ready result.
 *
 * The engine is pure: it reads no database, no clock and no configuration of
 * its own. Everything arrives on the DayContext (gathered by
 * DayContextBuilder — pipeline stages 1-3) and everything it decides is
 * derived here in a fixed order:
 *
 *   4a. collapse repeat scans (C20 — one cleaned list for every later stage)
 *   4b. pair the punches
 *   4c. resolve the meal break (C21 — reads the middle punches, never pairs)
 *   5.  calculate worked and payable time
 *   6.  determine the baseline status
 *   7.  apply grace, lateness and half-day thresholds
 *   8.  apply the occurrence-tier consequence
 *   9.  produce the result
 *
 * Because of that, a day can be recalculated at any time and reproduce exactly
 * the same answer from the same inputs — which is what makes an attendance
 * figure defensible to the person whose pay depends on it, and what lets the
 * entire rule set be tested from fixtures with no database at all.
 */
class AttendanceEngine
{
    /** @var array<int, AttendanceStage> */
    private array $stages;

    /**
     * @param  array<int, AttendanceStage>|null  $stages
     */
    public function __construct(?array $stages = null)
    {
        $this->stages = $stages ?? [
            new CollapseRepeatScans,
            new PairPunches,
            new ResolveMealBreak,
            new CalculateWorkedTime,
            new DetermineStatus,
            new ApplyGraceAndThresholds,
            new ApplyOccurrencePolicy,
        ];
    }

    public function process(DayContext $context): AttendanceOutcome
    {
        $computation = array_reduce(
            $this->stages,
            fn (AttendanceComputation $carry, AttendanceStage $stage) => $stage->handle($carry),
            AttendanceComputation::start($context),
        );

        return $this->produce($computation);
    }

    /**
     * Stage 9 — freeze the computation into the result payroll consumes,
     * carrying its own derivation so every figure can be explained later.
     */
    private function produce(AttendanceComputation $computation): AttendanceOutcome
    {
        $status = $computation->status ?? AttendanceStatus::Absent;

        return new AttendanceOutcome(
            employeeId: $computation->context->employeeId,
            date: $computation->context->date->copy()->startOfDay(),
            status: $status,
            consequence: $computation->consequence,
            firstIn: $computation->firstIn,
            lastOut: $computation->lastOut,
            workedMinutes: $computation->workedMinutes,
            breakMinutes: $computation->breakMinutes,
            payableMinutes: $computation->payableMinutes,
            lateMinutes: $computation->lateMinutes,
            earlyLeaveMinutes: $computation->earlyLeaveMinutes,
            graceWindowKey: $computation->graceWindowKey,
            graceWindowLabel: $computation->graceWindowLabel,
            occurrenceNumber: $computation->occurrenceNumber,
            // POL-06: the system never finalises a day that needed a judgement
            // call. HR review is what makes it payroll-eligible.
            isProvisional: $status->requiresHumanReview() || in_array('needs_review', $computation->flags, true),
            holdsPayroll: $status->holdsPayroll(),
            pairs: array_map(fn (PunchPair $pair) => [
                'in' => $pair->in->format('Y-m-d H:i:s'),
                'out' => $pair->out?->format('Y-m-d H:i:s'),
                'minutes' => $pair->minutes(),
            ], $computation->pairs),
            notes: $computation->notes,
            flags: array_values(array_unique($computation->flags)),
            meal: $computation->meal,
        );
    }
}
