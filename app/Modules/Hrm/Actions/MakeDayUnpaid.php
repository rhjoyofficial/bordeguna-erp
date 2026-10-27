<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Payroll\Data\PayrollPeriodSpec;
use App\Modules\Hrm\Support\ForgivenessValue;
use App\Modules\Hrm\Support\PayrollCycle;
use App\Modules\Hrm\Support\PayrollInputWindow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ 27 Oct 2026 (Joy): "one day one employee came but break some rules for
 * that office give him that day unpaid, how it can be done show me."
 *
 * From HRM, Attendance, Daily: a day the employee WORKED is made unpaid, full
 * or half, with a reason. The attendance day itself is not touched (they did
 * come; that stays true). The money is a Penalty payroll adjustment:
 *
 *  - on the payroll month that contains the day (24th to 23rd cycle);
 *  - for exactly one day's pay as payroll prices a day of Leave Without Pay,
 *    ForgivenessValue::dayRate, half of it for a half day. No second formula;
 *  - through the same approval as every other adjustment (it waits in the
 *    inbox, and pays nothing until approved);
 *  - source-keyed to the day (`day_unpaid:{employee}:{date}`), so one day is
 *    made unpaid once. The existing Cancel on the adjustment undoes it, and a
 *    cancelled, withdrawn or rejected one frees the day to be made unpaid again.
 *
 * No attachment, unlike a hand-typed adjustment (ADJ-09): the evidence is the
 * attendance day it names, which the system holds.
 *
 * Only a worked day: an absent day already loses its pay to the absence rule,
 * a leave day has its own pay decision (DecideLeavePay), a holiday or weekly
 * off is not a working day, and a missing punch must be fixed first.
 */
class MakeDayUnpaid
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    public static function sourceKey(int $employeeId, Carbon|string $date): string
    {
        return 'day_unpaid:'.$employeeId.':'.Carbon::parse($date)->toDateString();
    }

    /**
     * @throws ValidationException
     */
    public function handle(Employee $employee, string $date, bool $halfDay, string $reason, User $by): PayrollAdjustment
    {
        $day = Carbon::parse($date)->startOfDay();
        $shown = $day->format('d/m/Y');

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say which rule was broken, so the payslip can explain the deduction.']);
        }

        $attendance = DailyAttendance::query()->where('employee_id', $employee->id)->onDate($day->toDateString())->first();

        if (($refusal = self::refusal($attendance, $halfDay, $shown)) !== null) {
            throw ValidationException::withMessages(['date' => $refusal]);
        }

        $spec = PayrollPeriodSpec::containing($day, PayrollCycle::startDay());
        $period = PayrollPeriod::forMonth($spec->year, $spec->month);

        if (! PayrollInputWindow::accepts($period)) {
            throw ValidationException::withMessages(['date' => sprintf(
                'Payroll for %s is already %s, so %s can no longer be made unpaid there. Put a Penalty on the next open payroll from Payroll, Inputs.',
                $period->label(),
                strtolower($period->latestRun()->status->label()),
                $shown,
            )]);
        }

        $rate = ForgivenessValue::dayRate($employee->id, $day);
        $amount = round($halfDay ? $rate / 2 : $rate, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['date' => "{$employee->fullName()} has no salary structure on {$shown}, so there is no day's pay to take."]);
        }

        return DB::transaction(function () use ($employee, $day, $shown, $halfDay, $reason, $by, $period, $amount, $attendance) {
            $key = self::sourceKey($employee->id, $day);
            $this->freeKeyOfClosedOne($key);

            $adjustment = PayrollAdjustment::once($key, [
                'employee_id' => $employee->id,
                'type' => PayrollAdjustmentType::Penalty,
                'amount' => $amount,
                'payroll_period_id' => $period->id,
                'reason' => self::reasonLine($halfDay, $shown, trim($reason)),
                'origin_type' => $attendance->getMorphClass(),
                'origin_id' => $attendance->id,
                'requested_by' => $by->id,
            ]) ?? throw ValidationException::withMessages(['date' => "{$shown} is already made unpaid for {$employee->fullName()}. Cancel that penalty first to change it."]);

            $approval = $adjustment->submitForApproval($by);
            $this->notify->handle($approval, $adjustment->summaryLine());

            return $adjustment->fresh();
        });
    }

    /** Why this day cannot be made unpaid, or null. The row's button uses it too. */
    public static function refusal(?DailyAttendance $day, bool $halfDay, string $shown): ?string
    {
        if ($day === null) {
            return "No attendance is processed for {$shown} yet. Process the day first.";
        }

        return match ($day->status) {
            AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::FieldDuty => null,
            // A half day already loses half to the attendance rule; the other half is what is left.
            AttendanceStatus::HalfDay => $halfDay ? null : "{$shown} is already a half day, so only the other half can be made unpaid. Choose Half day.",
            AttendanceStatus::Absent => "{$shown} is absent, so the absence deduction already takes that day's pay.",
            AttendanceStatus::OnLeave => "{$shown} is a leave day. Decide its pay on the leave instead (HRM, Leave, Pay decision).",
            AttendanceStatus::MissingPunch => "{$shown} has a missing punch. Fix it first, then decide.",
            AttendanceStatus::Holiday, AttendanceStatus::WeeklyOff => "{$shown} is a {$day->status->label()}, not a working day, so there is no day's pay to take.",
        };
    }

    /** "Rule break, full day unpaid (20/10/2026): {reason}". The row shows the part after ": ". */
    public static function reasonLine(bool $halfDay, string $shown, string $reason): string
    {
        return sprintf('Rule break, %s unpaid (%s): %s', $halfDay ? 'half day' : 'full day', $shown, $reason);
    }

    /**
     * A penalty for this day that was cancelled, withdrawn or rejected no longer
     * stands, so its key is renamed (as CancelPayrollAdjustment does) and the
     * day can be decided again. One that waits or stands keeps it: twice is
     * refused.
     */
    private function freeKeyOfClosedOne(string $key): void
    {
        $existing = PayrollAdjustment::query()->withoutGlobalScope(BusinessScope::class)
            ->with('approvalRequest')
            ->where('source_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            return;
        }

        $closed = $existing->isCancelled()
            || $existing->approvalRequest?->status === ApprovalStatus::Rejected;

        if ($closed) {
            $existing->forceFill(['source_key' => "{$key}|closed:{$existing->id}"])->save();
        }
    }
}
