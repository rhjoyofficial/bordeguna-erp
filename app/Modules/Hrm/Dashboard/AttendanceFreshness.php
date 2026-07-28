<?php

namespace App\Modules\Hrm\Dashboard;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\DailyAttendance;
use Illuminate\Support\Carbon;

/**
 * How current a day's attendance figures actually are — **an operational state
 * in its own right, not a caveat bolted onto a number.**
 *
 * This exists because of RPT-04's headline finding. `ProcessAttendance` is never
 * scheduled: it runs when an HR user presses the button on the attendance grid,
 * or as a side effect of applying a correction, leave or movement. So a "Present
 * today" tile is not reporting on today — it is reporting on **whatever was last
 * processed**, and those are different claims that happen to agree only some of
 * the time.
 *
 * Correction C11b settled that the dashboard states this rather than papering
 * over it. The three facts a reader needs, and which no single figure can carry:
 *
 *  1. **When the day was last processed.** "0 present" at 09:00 means nothing
 *     has run; the same figure at 18:00 means nobody came in.
 *  2. **How many people have punched but have no processed day.** This is the
 *     size of the gap between what the devices know and what the figures show,
 *     and it is the number that tells HR to go and press process.
 *  3. **Whether the figures include provisional days.** POL-06/07 make Absent
 *     and Missing-punch proposals the engine may make but a human must confirm.
 *     A tile that counted them silently would assert something the system
 *     explicitly does not.
 *
 * ⚠ **It reports. It processes nothing.** Triggering `ProcessAttendance` from
 * here would put the 2b engine behind a `GET` — slow, and a write on a read
 * path. Scheduling is its own slice, and this class is what makes the dashboard
 * honest in the meantime rather than a reason not to schedule.
 */
final class AttendanceFreshness
{
    public function __construct(
        public readonly ?Carbon $processedAt = null,
        public readonly int $unprocessedEmployees = 0,
        public readonly int $provisionalDays = 0,
    ) {}

    /**
     * Resolve the freshness of one date, within the dashboard's own scope.
     *
     * ⚠ The office/department filters are applied to the *unprocessed* count as
     * well as to the figures themselves. A department manager told that 40
     * people are unprocessed, when 38 of them are in another department, has
     * been handed somebody else's problem.
     */
    public static function for(Carbon $date, DashboardFilters $filters, BusinessContext $context): self
    {
        $day = $date->toDateString();

        $processed = DailyAttendance::query()
            ->whereDate('attendance_date', $day)
            ->max('processed_at');

        $provisional = DailyAttendance::query()
            ->whereDate('attendance_date', $day)
            ->where('is_provisional', true)
            ->count();

        return new self(
            processedAt: $processed === null ? null : Carbon::parse($processed),
            unprocessedEmployees: self::unprocessed($day, $filters, $context),
            provisionalDays: $provisional,
        );
    }

    /**
     * Employees with a punch on the date but no processed day behind it.
     *
     * This is the honest measure of the gap. Counting *punches* would overstate
     * it — somebody who clocked in and out four times is one unprocessed
     * person, not four — so it counts distinct employees.
     */
    private static function unprocessed(string $day, DashboardFilters $filters, BusinessContext $context): int
    {
        return AttendancePunch::query()
            ->whereDate('punched_at', $day)
            ->whereNotExists(function ($query) use ($day) {
                $query->selectRaw('1')
                    ->from('daily_attendances')
                    ->whereColumn('daily_attendances.employee_id', 'attendance_punches.employee_id')
                    ->whereRaw('date(daily_attendances.attendance_date) = ?', [$day]);
            })
            ->when(
                $filters->departmentId() !== null || $filters->officeId() !== null,
                fn ($query) => $query->whereExists(function ($inner) use ($filters, $day, $context) {
                    $inner->selectRaw('1')
                        ->from('employee_assignments')
                        ->whereColumn('employee_assignments.employee_id', 'attendance_punches.employee_id')
                        ->where('employee_assignments.business_id', $context->id())
                        ->where('employee_assignments.is_primary', true)
                        ->whereDate('employee_assignments.effective_from', '<=', $day)
                        ->where(function ($dates) use ($day) {
                            $dates->whereNull('employee_assignments.effective_to')
                                ->orWhereDate('employee_assignments.effective_to', '>=', $day);
                        })
                        ->when(
                            $filters->officeId(),
                            fn ($q, $id) => $q->where('employee_assignments.office_id', $id),
                        )
                        ->when(
                            $filters->departmentId(),
                            fn ($q, $id) => $q->where('employee_assignments.department_id', $id),
                        );
                }),
            )
            ->distinct()
            ->count('attendance_punches.employee_id');
    }

    /**
     * The sentence that goes under the figure.
     *
     * Deliberately never empty for an attendance tile: "these figures are
     * current" is itself information, and a tile that says nothing when
     * everything is fine trains people to ignore the line where it matters.
     */
    public function note(Carbon $date): string
    {
        if ($this->processedAt === null) {
            // The case the headline finding is about, stated plainly rather than
            // left to be inferred from a zero.
            return $this->unprocessedEmployees > 0
                ? sprintf(
                    'Not processed yet — %d %s punched today and %s not counted here.',
                    $this->unprocessedEmployees,
                    $this->plural('employee has', 'employees have'),
                    $this->plural('is', 'are'),
                )
                : 'No attendance has been processed for '.$date->format('d M Y').'.';
        }

        $note = 'As at '.$this->processedAt->format('d M Y, h:i A');

        if ($this->unprocessedEmployees > 0) {
            $note .= sprintf(
                ' — %d %s punched since and %s not counted yet',
                $this->unprocessedEmployees,
                $this->plural('employee has', 'employees have'),
                $this->plural('is', 'are'),
            );
        }

        if ($this->provisionalDays > 0) {
            $note .= sprintf(
                '. Includes %d provisional day%s awaiting review (POL-06/07)',
                $this->provisionalDays,
                $this->provisionalDays === 1 ? '' : 's',
            );
        }

        return $note.'.';
    }

    /**
     * ⚠ Agreement, not just an "s".
     *
     * Caught in the browser check, which is the only place it could have been:
     * every test asserted `toContain('1 employee')`, and "1 employee **have**
     * punched today" satisfies that perfectly while reading as broken English
     * on the most-looked-at line of the dashboard. The verb has to move with
     * the noun, so both are chosen together.
     */
    private function plural(string $singular, string $plural): string
    {
        return $this->unprocessedEmployees === 1 ? $singular : $plural;
    }

    /**
     * Whether the figure should be read as settled. Used by the page to mark a
     * tile visually, so "stale" is apparent before anybody reads the small
     * print — the whole point of treating this as a state rather than a note.
     */
    public function isStale(): bool
    {
        return $this->processedAt === null || $this->unprocessedEmployees > 0;
    }
}
