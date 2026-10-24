<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Hrm\Corrections\HrCorrectionRequests;
use App\Modules\Hrm\Corrections\HrRecordCorrector;
use App\Modules\Hrm\Corrections\MovementActionPolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Models\Movement;
use App\Modules\Hrm\Support\FrozenAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Cancel an approved movement (corrections wave 3, 24 Oct 2026; FINAL-PLAN
 * 3.4). The same shape as CancelLeaveRequest without a balance: the row stays
 * marked Cancelled with who, when and why, and the day is judged again without
 * it. Payroll already approved for that day: a second person first, and the
 * difference goes to the next open payroll (rule #18); locked payroll is never
 * changed.
 */
class CancelMovement
{
    public function __construct(
        private readonly MovementActionPolicy $policy,
        private readonly HrCorrectionRequests $requests,
        private readonly ProcessAttendance $process,
    ) {}

    /**
     * @return array{outcome: 'cancelled'|'requested', request: ?HrCorrectionRequest}
     */
    public function handle(Movement $movement, User $by, ActionReason $reason, ?HrCorrectionRequest $approved = null, ?User $approver = null): array
    {
        return DB::transaction(function () use ($movement, $by, $reason, $approved, $approver) {
            $current = Movement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();
            $current->load(['approvalRequest', 'employee']);

            if ($approved !== null) {
                $this->requests->claim($approved, $current, HrRecordCorrector::fingerprintOf($current));
            }

            $availability = $this->policy->availability($current, $by, DocumentAction::Cancel);

            if (! $availability->allowed) {
                throw new InvalidArgumentException($availability->blockers[0]->message);
            }

            $date = Carbon::parse($current->movement_date);

            if ($approved === null && $availability->approval === ApprovalRoute::SecondPerson) {
                return ['outcome' => 'requested', 'request' => $this->requests->ask(
                    HrCorrectionRequest::CANCEL_MOVEMENT,
                    $current,
                    $this->label($current),
                    $by,
                    $reason,
                    "Payroll for {$date->format('d/m/Y')} is already approved, so the difference goes to the next open payroll.",
                    HrRecordCorrector::fingerprintOf($current),
                )];
            }

            $current->update([
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason->display(),
            ]);

            $employee = $current->employee;

            if ($employee !== null) {
                if ($date->isAfter(Carbon::today()) && FrozenAttendance::runCovering($date) === null) {
                    DailyAttendance::query()->where('employee_id', $employee->id)->whereDate('attendance_date', $date->toDateString())->delete();
                } else {
                    $this->process->deferringFrozen(fn () => $this->process->forDay($employee, $date, force: true));
                }
            }

            CorrectionLog::event('cancelled', $by, $current, array_filter([
                'number' => $this->policy->numberOf($current),
                'reason' => $reason->display(),
                'approved_by' => $approver?->name,
            ], fn ($value) => $value !== null));

            if ($approved !== null && $approver !== null) {
                $this->requests->markExecuted($approved, $approver);
            }

            return ['outcome' => 'cancelled', 'request' => null];
        });
    }

    /** "Movement #4 of Karim Ahmed, Field duty on 06/10/2026". */
    public function label(Movement $movement): string
    {
        return sprintf('%s, %s on %s', $this->policy->numberOf($movement), $movement->type->label(), Carbon::parse($movement->movement_date)->format('d/m/Y'));
    }
}
