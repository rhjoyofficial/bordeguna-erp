<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Corrections\EmployeeActionPolicy;
use App\Modules\Hrm\Corrections\HrCorrectionRequests;
use App\Modules\Hrm\Corrections\HrRecordCorrector;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Undo a separation before the final pay (corrections wave 3, 24 Oct 2026;
 * FINAL-PLAN 3.4: "Undo separation before final settlement. HR requests, a
 * second approver confirms").
 *
 * Asking: the policy is asked under the lock and a request for a second person
 * is raised (HrCorrectionRequest); nothing changes while it waits.
 *
 * Approved, in one transaction: the record is locked and its fingerprint must
 * still match; the policy is asked again for the person who asked (the final
 * pay may have been approved meanwhile). Then:
 *   - the status goes back to what it was before (status_before_separation,
 *     Active for a separation recorded before that column existed);
 *   - the leaving type, date and reason are cleared (History keeps them);
 *   - the assignments the separation closed on that date are opened again;
 *   - the days after the leaving date, which separating deleted, are judged
 *     again up to yesterday (rule #18 still applies to any frozen day).
 *
 * ⚠ The ERP login is NOT switched back on. Separating may have revoked it, and
 * giving access back is a security decision of its own: the message says so,
 * and it is done under Users.
 */
class UndoSeparation
{
    /** Days after the leaving date judged again, at most (about two cycles). */
    private const MAX_DAYS = 62;

    public function __construct(
        private readonly EmployeeActionPolicy $policy,
        private readonly HrCorrectionRequests $requests,
        private readonly ProcessAttendance $process,
    ) {}

    /**
     * @return array{outcome: 'undone'|'requested', request: ?HrCorrectionRequest}
     */
    public function handle(Employee $employee, User $by, ActionReason $reason, ?HrCorrectionRequest $approved = null, ?User $approver = null): array
    {
        return DB::transaction(function () use ($employee, $by, $reason, $approved, $approver) {
            $current = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($approved !== null) {
                $this->requests->claim($approved, $current, HrRecordCorrector::fingerprintOf($current));
            }

            $availability = $this->policy->availability($current, $by, DocumentAction::Reverse);

            if (! $availability->allowed) {
                throw new InvalidArgumentException($availability->blockers[0]->message);
            }

            $date = Carbon::parse($current->separation_date);
            $name = $this->policy->numberOf($current);

            if ($approved === null) {
                return ['outcome' => 'requested', 'request' => $this->requests->ask(
                    HrCorrectionRequest::UNDO_SEPARATION,
                    $current,
                    "{$name}, left on {$date->format('d/m/Y')} ({$current->separation_type?->label()})",
                    $by,
                    $reason,
                    'A separation is undone only when a second person confirms it.',
                    HrRecordCorrector::fingerprintOf($current),
                )];
            }

            $before = [
                'separation_type' => $current->separation_type?->value,
                'separation_date' => $date->toDateString(),
                'separation_reason' => $current->separation_reason,
            ];

            $current->update([
                'status' => EmployeeStatus::tryFrom((string) $current->status_before_separation) ?? EmployeeStatus::Active,
                'status_before_separation' => null,
                'separation_type' => null,
                'separation_date' => null,
                'separation_reason' => null,
            ]);

            $reopened = EmployeeAssignment::withoutGlobalScope(BusinessScope::class)
                ->where('employee_id', $current->id)
                ->whereDate('effective_to', $date->toDateString())
                ->update(['effective_to' => null]);

            $this->judgeDaysAfter($current, $date);

            CorrectionLog::event('separation_undone', $by, $current, [
                'number' => $name,
                'reason' => $reason->display(),
                'before' => $before,
                'assignments_reopened' => $reopened,
                'approved_by' => $approver?->name,
            ]);

            if ($approver !== null) {
                $this->requests->markExecuted($approved, $approver);
            }

            return ['outcome' => 'undone', 'request' => null];
        });
    }

    private function judgeDaysAfter(Employee $employee, Carbon $leftOn): void
    {
        $yesterday = Carbon::yesterday();
        $day = $leftOn->copy()->addDay()->startOfDay();
        $count = 0;

        $this->process->deferringFrozen(function () use ($employee, $yesterday, &$day, &$count) {
            while ($day->lessThanOrEqualTo($yesterday) && $count < self::MAX_DAYS) {
                $this->process->forDay($employee, $day->copy(), force: true);
                $day->addDay();
                $count++;
            }
        });
    }
}
