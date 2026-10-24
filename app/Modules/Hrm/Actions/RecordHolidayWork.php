<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\MovementType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Movement;
use Illuminate\Support\Facades\DB;

/**
 * Credits work done on a holiday or weekly off, for several people at once.
 *
 * ⚠⚠ WHY THIS EXISTS. POL-09 says holiday work never counts unless approved,
 * and approving it meant raising one movement per person through the ordinary
 * request form. On a real holiday - Eid-e-Milad-un-Nabi, when two of nine
 * people came in - that is the difference between a task somebody does and a
 * task somebody means to do. Joy asked for it to be assignable to named people
 * for a date, before or after the day itself.
 *
 * ⚠⚠ IT DOES NOT BYPASS THE APPROVAL WORKFLOW. Each person still gets a real
 * movement and a real approval request; this action simply raises them together
 * and then approves each one the caller is entitled to approve. A movement that
 * needs another stage is left waiting for that stage, exactly as if it had been
 * raised by hand, and the caller is told how many.
 *
 * ⛔ IT PAYS NOBODY ANYTHING EXTRA. An approved holiday day counts as a normal
 * present day, which is what the engine already did. Whether holiday work earns
 * a premium rate or a compensatory day off is a decision about money that has
 * never been made, and inventing one here would put an unagreed rate into
 * payroll.
 */
class RecordHolidayWork
{
    public function __construct(
        private readonly RequestMovement $request,
        private readonly ApplyMovement $apply,
        private readonly NotifyStageApprovers $notify,
    ) {}

    /**
     * @param  array<int, int>  $employeeIds
     * @return array{approved: int, pending: int, skipped: int}
     */
    public function handle(array $employeeIds, string $date, string $reason, User $actor): array
    {
        $approved = 0;
        $pending = 0;
        $skipped = 0;

        foreach (array_unique($employeeIds) as $employeeId) {
            $employee = Employee::find($employeeId);

            if ($employee === null) {
                $skipped++;

                continue;
            }

            // ⚠ Somebody already credited for this day is skipped rather than
            // given a second movement. Pressing the button twice, or adding one
            // more name to a list that already went through, must not double
            // anything.
            if ($this->alreadyCredited($employee, $date)) {
                $skipped++;

                continue;
            }

            $outcome = DB::transaction(function () use ($employee, $date, $reason, $actor) {
                $movement = $this->request->handle(
                    $employee,
                    MovementType::HolidayWork,
                    $date,
                    ['reason' => $reason],
                    $actor,
                );

                $approval = $movement->approvalRequest;

                if ($approval === null || ! $approval->canBeActionedBy($actor)) {
                    return 'pending';
                }

                if (! $approval->approve($actor, 'Recorded as work on a non-working day.')) {
                    // More stages to go: real, saved, and waiting for somebody
                    // else - never silently treated as done.
                    $this->notify->handle($approval->fresh(), $movement->summaryLine());

                    return 'pending';
                }

                // Applying reprocesses the day, which is what turns the
                // uncredited punches into a counted day.
                $this->apply->handle($movement);

                return 'approved';
            });

            $outcome === 'approved' ? $approved++ : $pending++;
        }

        return ['approved' => $approved, 'pending' => $pending, 'skipped' => $skipped];
    }

    /**
     * Whether this person already has a holiday-work movement for the day that
     * has not been rejected.
     */
    private function alreadyCredited(Employee $employee, string $date): bool
    {
        // ⚠ Movements carry no status column of their own: the verdict lives on
        // the approval request. A rejected one is not a credit and must not
        // block the day from being recorded properly later.
        return Movement::query()
            ->where('employee_id', $employee->id)
            ->where('type', MovementType::HolidayWork)
            ->whereDate('movement_date', $date)
            // A cancelled one is not a credit either (corrections wave 3).
            ->whereNull('cancelled_at')
            ->whereDoesntHave('approvalRequest', fn ($query) => $query->where('status', ApprovalStatus::Rejected))
            ->exists();
    }
}
