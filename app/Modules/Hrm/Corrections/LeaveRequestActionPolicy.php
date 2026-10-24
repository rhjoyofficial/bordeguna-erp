<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveYearEndRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Leave in the corrections process (corrections wave 3, 24 Oct 2026;
 * FINAL-PLAN 3.4 "Approved leave or movement"). The stage is worked out:
 *
 *   Waiting     sent for approval     nothing here (the approver rejects it; the
 *                                     sender's Withdraw is the approvals inbox's,
 *                                     wave 2)
 *   Done        approved and applied  Cancel: the days go back to the balance and
 *                                     every covered day is judged again
 *   Cancelled, rejected, sent back    nothing
 *
 * ⚠⚠ After that month's payroll is approved the cancellation needs HR plus one
 * other approver (ApprovalRoute::SecondPerson). Payroll is never rewritten: the
 * difference lands on the next open payroll as an arrear or a deduction, the
 * rule #18 path every late attendance change already takes.
 *
 * ⚠ It posts no journal, so the corrections service (Reverse, Correct) never
 * runs on it; Cancel is LeaveController's route, asking THIS policy again
 * under the lock. Registered for the History tab and the actions endpoint.
 */
class LeaveRequestActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'hrm.leave_request';

    public const PERMISSION = 'hrm.leave.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return LeaveRequest::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION) || $user->can('hrm.leave.approve');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var LeaveRequest $document */
        return match ($document->approvalRequest?->status) {
            ApprovalStatus::Pending => DocumentStage::Waiting,
            ApprovalStatus::Returned => DocumentStage::Draft,
            default => DocumentStage::Done,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::LeaveLedger, EffectKind::Payroll], [LeaveLedgerCheck::class, FrozenPayrollCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var LeaveRequest $document */
        $stage = $this->stageOf($document);

        if (! $this->inEffect($document) || ! $user->can(self::PERMISSION)) {
            return new ActionSet($stage, []);
        }

        return new ActionSet($stage, [$this->availability($document, $user, DocumentAction::Cancel)]);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var LeaveRequest $document */
        $document->loadMissing('approvalRequest');
        $number = $this->numberOf($document);
        $blockers = [];

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($action !== DocumentAction::Cancel) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a leave request.");
        } elseif ($document->isCancelled()) {
            $blockers[] = new Blocker('cancelled', "{$number} is already cancelled.");
        } elseif (! $this->inEffect($document)) {
            $blockers[] = new Blocker('not_in_effect', $this->stageOf($document) === DocumentStage::Waiting
                ? "{$number} is still waiting for approval, so the approver rejects it instead."
                : "{$number} was never approved, so there is nothing to cancel.");
        }

        if ($blockers === []) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        if ($blockers === [] && HrCorrectionRequest::pendingFor(HrCorrectionRequest::CANCEL_LEAVE, $document) !== null) {
            $blockers[] = new Blocker('requested', "A cancellation of {$number} is already waiting for a second person.");
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: true,
            requiresReason: true,
            reasonContext: 'cancellation',
            approval: FrozenPayrollCheck::frozenDate($document->covered_dates ?? []) !== null ? ApprovalRoute::SecondPerson : ApprovalRoute::None,
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var LeaveRequest $document */
        $name = $document->employee?->fullName();

        return 'Leave #'.$document->getKey().($name ? " of {$name}" : '');
    }

    public function urlOf(Model $document): ?string
    {
        /** @var LeaveRequest $document */
        return route('hrm.leave.index', ['employee_id' => $document->employee_id, 'year' => Carbon::parse($document->start_date)->year]);
    }

    /** Approved, applied and not cancelled: its days are charged now. */
    public function inEffect(LeaveRequest $leave): bool
    {
        return $leave->isApplied() && ! $leave->isCancelled()
            && $leave->approvalRequest?->status === ApprovalStatus::Approved;
    }

    /**
     * A leave year whose year-end is committed: its carry-forward and lapse were
     * worked out from the balance as it stood, so giving days back into it now
     * would leave that sum wrong. Null when every year it touches is still open.
     */
    public static function closedYear(LeaveRequest $leave): ?int
    {
        return collect($leave->covered_dates ?? [])
            ->map(fn (string $date) => (int) substr($date, 0, 4))
            ->unique()
            ->first(fn (int $year) => LeaveYearEndRun::query()->where('year', $year)->where('is_dry_run', false)->exists());
    }
}
