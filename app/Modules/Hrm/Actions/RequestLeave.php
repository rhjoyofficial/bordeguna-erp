<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
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

        $calculated = $this->calculator->calculate($employee, $startDate, $endDate, $portion);

        if ($calculated['days'] <= 0) {
            throw ValidationException::withMessages([
                'start_date' => 'Every day in that range is a holiday or weekly off, so there is nothing to charge.',
            ]);
        }

        [$paidDays, $unpaidDays] = $this->split($employee, $type, $startDate, $calculated['days']);

        return DB::transaction(function () use (
            $employee, $type, $startDate, $endDate, $portion, $reason,
            $requestedBy, $calculated, $paidDays, $unpaidDays
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
     * @return array{0: float, 1: float}
     */
    private function split(Employee $employee, LeaveType $type, Carbon $start, float $days): array
    {
        if (! $type->deducts_balance) {
            return $type->is_paid ? [0.0, 0.0] : [0.0, $days];
        }

        $available = max(0.0, $this->balances->for($employee->id, $type->id, (int) $start->year));
        $paid = min($days, $available);

        return [round($paid, 2), round($days - $paid, 2)];
    }
}
