<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Provisional days and the requests waiting over them (Joy's HRM queue,
 * 27 Oct 2026). One reader for the four places that ask: My attendance ("days
 * needing your action"), Needs attention, the reminders and the payroll block.
 *
 * ⚠ A provisional day is the system's proposal (POL-06) and blocks payroll.
 * "Something is filed" means a leave, a movement (early leave included) or a
 * correction is still WAITING over that date: the day is then the approver's
 * to settle, not the employee's.
 */
class AttendanceFollowUp
{
    public const HINT_LEAVE = 'leave';

    public const HINT_EARLY_LEAVE = 'early_leave';

    public const HINT_MOVEMENT = 'movement';

    public const HINT_CORRECTION = 'correction';

    /**
     * The current payroll cycle up to today: the window both sides look at.
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon} from, to (never past today), cycle end
     */
    public static function currentWindow(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $cycle = PayrollCycle::containing($today);

        return [$cycle['start'], $cycle['end']->lessThan($today) ? $cycle['end'] : $today, $cycle['end']];
    }

    /**
     * Provisional days in a range, oldest first, for one employee or everyone.
     *
     * @return Collection<int, DailyAttendance>
     */
    public function provisionalDays(Carbon $from, Carbon $to, ?int $employeeId = null): Collection
    {
        return DailyAttendance::with('employee:id,employee_code,first_name,last_name,user_id')
            ->needingReview()
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->orderBy('attendance_date')
            ->orderBy('employee_id')
            ->get();
    }

    /**
     * What is still waiting over each date: employee id => date => hint. The
     * first match wins in the order leave, early leave, movement, correction.
     *
     * @param  array<int, int>|null  $employeeIds  null = everyone
     * @return array<int, array<string, string>>
     */
    public function pendingHints(Carbon $from, Carbon $to, ?array $employeeIds = null): array
    {
        $hints = [];
        $put = function (int $employeeId, string $date, string $hint) use (&$hints, $from, $to) {
            if ($date < $from->toDateString() || $date > $to->toDateString()) {
                return;
            }

            $hints[$employeeId][$date] ??= $hint;
        };
        $pending = fn ($query) => $query->where('status', ApprovalStatus::Pending);

        LeaveRequest::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->whereNull('applied_at')
            ->whereNull('cancelled_at')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereHas('approvalRequest', $pending)
            ->get()
            ->each(function (LeaveRequest $leave) use ($put) {
                foreach ($leave->covered_dates ?? [] as $date) {
                    $put((int) $leave->employee_id, (string) $date, self::HINT_LEAVE);
                }
            });

        Movement::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->whereNull('applied_at')
            ->whereNull('cancelled_at')
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->whereHas('approvalRequest', $pending)
            // Early leave first, so a date with both reads as the early leave.
            ->get()
            ->sortBy(fn (Movement $m) => $m->type === MovementType::EarlyLeave ? 0 : 1)
            ->each(fn (Movement $m) => $put(
                (int) $m->employee_id,
                Carbon::parse($m->movement_date)->toDateString(),
                $m->type === MovementType::EarlyLeave ? self::HINT_EARLY_LEAVE : self::HINT_MOVEMENT,
            ));

        AttendanceCorrection::query()
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->whereNull('applied_at')
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->whereHas('approvalRequest', $pending)
            ->get()
            ->each(fn (AttendanceCorrection $c) => $put(
                (int) $c->employee_id,
                Carbon::parse($c->attendance_date)->toDateString(),
                self::HINT_CORRECTION,
            ));

        return $hints;
    }

    /**
     * The employee's own list on My attendance: provisional days in the current
     * payroll cycle with nothing filed over them, each with why it is open.
     *
     * @return list<array{date: string, status: string, reason: string, reason_key: string, last_out: string|null, early_leave_minutes: int}>
     */
    public function daysNeedingAction(Employee $employee, ?Carbon $today = null): array
    {
        [$from, $to] = self::currentWindow($today);
        $hints = $this->pendingHints($from, $to, [$employee->id])[$employee->id] ?? [];

        return $this->provisionalDays($from, $to, $employee->id)
            ->reject(fn (DailyAttendance $day) => isset($hints[$day->attendance_date->toDateString()]))
            ->map(fn (DailyAttendance $day) => [
                'date' => $day->attendance_date->toDateString(),
                'status' => $day->status->label(),
                'reason' => self::reasonFor($day)[0],
                'reason_key' => self::reasonFor($day)[1],
                'last_out' => $day->last_out?->format('H:i'),
                'early_leave_minutes' => (int) $day->early_leave_minutes,
            ])
            ->values()
            ->all();
    }

    /**
     * Why a provisional day is open, in English and as a dictionary key.
     *
     * @return array{0: string, 1: string}
     */
    public static function reasonFor(DailyAttendance $day): array
    {
        $flags = $day->flags ?? [];

        return match (true) {
            $day->holds_payroll => ['A punch is missing.', 'early.reason.missing_punch'],
            in_array('early_departure', $flags, true) => ['Left before the shift ended.', 'early.reason.early_departure'],
            $day->status->value === 'absent' => ['Absent, with no leave filed.', 'early.reason.absent'],
            in_array('unapproved_holiday_work', $flags, true) => ['Worked on a day off without approval.', 'early.reason.holiday_work'],
            default => ['Waiting for HR review.', 'early.reason.review'],
        };
    }

    /** "pending leave covers this day" and its siblings, for the payroll issue text. */
    public static function hintText(string $hint): string
    {
        return match ($hint) {
            self::HINT_LEAVE => 'pending leave covers this day',
            self::HINT_EARLY_LEAVE => 'pending early leave request covers this day',
            self::HINT_MOVEMENT => 'pending movement covers this day',
            default => 'pending correction covers this day',
        };
    }
}
