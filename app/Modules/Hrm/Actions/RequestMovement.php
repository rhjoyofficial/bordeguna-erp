<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Support\EarlyLeaveRules;
use App\Modules\Hrm\Support\PayrollCycle;
use App\Modules\Hrm\Support\WorkFromHomeAllowance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raises a movement request — field duty, official work, or work on a holiday
 * (POL-09 / POL-10).
 *
 * Separate from leave by LEV-04's design: a movement draws on no balance and is
 * confirmed by a manager rather than charged to a quota.
 */
class RequestMovement
{
    public function __construct(
        private readonly NotifyStageApprovers $notify,
        private readonly WorkFromHomeAllowance $allowance,
    ) {}

    /**
     * @param  array<string, mixed>  $data  from_time/to_time/place/reason
     */
    public function handle(Employee $employee, MovementType $type, string $date, array $data, User $requestedBy): Movement
    {
        $this->assertWithinWorkFromHomeCap($employee, $type, $date);
        $this->assertEarlyLeaveIsComplete($employee, $type, $date, $data);

        return DB::transaction(function () use ($employee, $type, $date, $data, $requestedBy) {
            $movement = Movement::create([
                'employee_id' => $employee->id,
                'type' => $type,
                'movement_date' => $date,
                'from_time' => $data['from_time'] ?? null,
                // Early leave has one time, the time of leaving; a closing time
                // would read as a window the person comes back for.
                'to_time' => $type->isEarlyLeave() ? null : ($data['to_time'] ?? null),
                'place' => $data['place'] ?? null,
                'reason' => $data['reason'],
                'requested_by' => $requestedBy->id,
            ]);

            $approval = $movement->submitForApproval($requestedBy);
            $this->notify->handle($approval, $movement->summaryLine());

            return $movement->fresh();
        });
    }

    /**
     * ⭐ Early leave (27 Oct 2026): it needs the time of leaving, one request
     * per day, and stays inside the monthly cap when one is set
     * (EarlyLeaveRules::monthlyCap, none by default).
     *
     * ⚠ In the action for the same reason as the WFH cap: the employee's own
     * screen and HR's screen both reach it.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertEarlyLeaveIsComplete(Employee $employee, MovementType $type, string $date, array $data): void
    {
        if (! $type->isEarlyLeave()) {
            return;
        }

        if (blank($data['from_time'] ?? null)) {
            throw ValidationException::withMessages([
                'from_time' => 'Say what time you will leave (or left). An early leave without a time cannot be matched to the punch.',
            ]);
        }

        $standing = Movement::query()
            ->where('employee_id', $employee->id)
            ->where('type', MovementType::EarlyLeave)
            ->onDate($date)
            ->whereNull('cancelled_at')
            ->whereHas('approvalRequest', fn ($query) => $query->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved]))
            ->exists();

        if ($standing) {
            throw ValidationException::withMessages([
                'movement_date' => 'An early leave request for this day is already waiting or approved.',
            ]);
        }

        $cap = EarlyLeaveRules::monthlyCap();
        $when = Carbon::parse($date);

        if ($cap !== null && EarlyLeaveRules::usedIn($employee, $when) >= $cap) {
            // ⭐ 27 Oct 2026: the cap is per 24-23 cycle, and the message names its dates.
            $cycle = PayrollCycle::containing($when);

            throw ValidationException::withMessages([
                'movement_date' => "{$employee->fullName()} has already used all {$cap} early leave request(s) for ".PayrollCycle::describe($cycle['year'], $cycle['month']).', counting requests still awaiting approval.',
            ]);
        }
    }

    /**
     * C-WFH-4: the monthly cap on working from home.
     *
     * ⚠⚠ ENFORCED IN THE ACTION, not in the two controllers that call it. HR
     * raises movements on one screen and employees raise their own on another;
     * a cap living in a request rule would hold on whichever screen somebody
     * remembered to put it, which is how a cap becomes advice.
     *
     * ⚠ A cap of zero means work from home is not allowed here, and the message
     * says that rather than reporting "0 of 0 used", which reads like a bug.
     */
    private function assertWithinWorkFromHomeCap(Employee $employee, MovementType $type, string $date): void
    {
        if (! $type->isWorkFromHome()) {
            return;
        }

        $when = Carbon::parse($date);
        $summary = $this->allowance->summaryFor($employee, $when);

        if ($summary['cap'] === 0) {
            throw ValidationException::withMessages([
                'type' => 'Work from home is not allowed in this business. The monthly limit is set to zero in Settings, Business.',
            ]);
        }

        if ($summary['remaining'] < 1) {
            throw ValidationException::withMessages([
                'movement_date' => "{$employee->fullName()} has already used all {$summary['cap']} work-from-home day(s) for {$summary['month']}, counting requests still awaiting approval.",
            ]);
        }
    }
}
