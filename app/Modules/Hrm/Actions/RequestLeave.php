<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Modules\Hrm\Support\LeaveDayCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a leave request (LEV-04/05/06/07).
 *
 * Two rules are settled here rather than at approval time, so the employee sees
 * the real cost before anyone commits to it:
 *
 *  - LEV-06 — holidays and weekly offs inside the range are not charged, so a
 *    Thursday-to-Saturday absence across a Friday weekend costs two days.
 *  - LEV-07 — there is no such thing as a negative paid balance. Whatever the
 *    balance cannot cover is split off as unpaid days on the same request,
 *    which is exactly what "converts to Unpaid Leave" means.
 */
class RequestLeave
{
    public function __construct(
        private readonly LeaveDayCalculator $calculator,
        private readonly LeaveBalance $balances,
        private readonly NotifyStageApprovers $notify,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Employee $employee,
        LeaveType $type,
        string $start,
        string $end,
        LeaveDayPortion $portion,
        string $reason,
        User $requestedBy,
    ): LeaveRequest {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();

        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
        }

        if ($portion->isPartial() && ! $startDate->equalTo($endDate)) {
            throw ValidationException::withMessages(['day_portion' => 'A half day applies to a single date only.']);
        }

        if ($portion->isPartial() && ! $type->allows_half_day) {
            throw ValidationException::withMessages(['day_portion' => "{$type->name} cannot be taken as a half day."]);
        }

        $this->refuseOverlap($employee, $startDate, $endDate, $portion);

        $calculated = $this->calculator->calculate($employee, $startDate, $endDate, $portion);

        if ($calculated['days'] <= 0) {
            throw ValidationException::withMessages([
                'start_date' => 'Every day in that range is a holiday or weekly off, so there is nothing to charge.',
            ]);
        }

        [$paidDays, $unpaidDays] = $this->split($employee, $type, $calculated);

        return DB::transaction(function () use (
            $employee, $type, $startDate, $endDate, $portion, $reason,
            $requestedBy, $calculated, $unpaidDays
        ) {
            $request = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'day_portion' => $portion,
                'days' => $calculated['days'],
                'unpaid_days' => $unpaidDays,
                'reason' => $reason,
                'covered_dates' => $calculated['dates'],
                'requested_by' => $requestedBy->id,
            ]);

            $approval = $request->submitForApproval($requestedBy);
            $this->notify->handle($approval, $request->summaryLine());

            return $request->fresh();
        });
    }

    /**
     * LEV-07: split the request into what the balance covers and what becomes
     * unpaid.
     *
     * ⚠⚠ "Draws on no balance" and "unpaid" are two different facts, and this
     * method conflated them until 24 Aug 2026: every non-balance type was
     * treated as entirely unpaid, so a Special Leave day the boss granted as
     * PAID - and a maternity day the law grants as paid - carried a Leave
     * Without Pay deduction into payroll. Found the day Joy granted exactly
     * such a day. A non-balance type is now unpaid only when the type itself
     * says it is (is_paid=false, the Unpaid type); a paid non-balance type
     * charges no quota and deducts no salary.
     *
     * Rule #17 (14 Oct 2026): a leave across New Year is weighed against each
     * leave year's own balance for that year's days, so only the days of a
     * year whose balance is short become unpaid. It used to draw the whole
     * leave from the starting year.
     *
     * @param  array{days: float, dates: array<int, string>}  $calculated
     * @return array{0: float, 1: float}
     */
    private function split(Employee $employee, LeaveType $type, array $calculated): array
    {
        $days = (float) $calculated['days'];

        if (! $type->deducts_balance) {
            return $type->is_paid ? [0.0, 0.0] : [0.0, $days];
        }

        $paid = 0.0;

        foreach ($this->calculator->daysByYear($calculated['dates'], $days) as $year => $yearDays) {
            $available = max(0.0, $this->balances->for($employee->id, $type->id, $year));
            $paid += min($yearDays, $available);
        }

        return [round($paid, 2), round($days - $paid, 2)];
    }

    /**
     * One day is asked for once. A second request over days already waiting or
     * already granted was accepted silently (production, 17 Sep 2026: the same
     * two days twice in the approver's inbox), and approving both would have
     * charged the balance twice for one absence. A rejected or returned request
     * frees its days. Two different halves of one date are two absences, not
     * one, and are allowed.
     */
    private function refuseOverlap(Employee $employee, Carbon $start, Carbon $end, LeaveDayPortion $portion): void
    {
        $clash = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->whereHas('approvalRequest', fn ($query) => $query->whereIn('status', [
                ApprovalStatus::Pending->value,
                ApprovalStatus::Approved->value,
            ]))
            ->get()
            ->first(fn (LeaveRequest $existing) => ! ($portion->isPartial()
                && $existing->day_portion->isPartial()
                && $existing->day_portion !== $portion));

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages([
            'start_date' => sprintf(
                'Leave for %s to %s is already %s. Ask for different days, or have that request rejected first.',
                Carbon::parse($clash->start_date)->format('j M Y'),
                Carbon::parse($clash->end_date)->format('j M Y'),
                $clash->approvalRequest?->status === ApprovalStatus::Approved ? 'approved' : 'waiting for approval',
            ),
        ]);
    }
}
