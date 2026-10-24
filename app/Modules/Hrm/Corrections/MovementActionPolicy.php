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
use App\Modules\Hrm\Models\Movement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ A movement (out of office, field duty, holiday work, work from home) in
 * the corrections process (corrections wave 3, 24 Oct 2026; FINAL-PLAN 3.4).
 * Same shape as LeaveRequestActionPolicy without a balance: Cancel on an
 * approved one judges the day again without it. After that month's payroll
 * is approved it needs a second person and the difference goes to the next
 * open payroll (rule #18); a locked attendance period refuses.
 */
class MovementActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'hrm.movement';

    public const PERMISSION = 'hrm.leave.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Movement::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION) || $user->can('hrm.leave.approve');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var Movement $document */
        return match ($document->approvalRequest?->status) {
            ApprovalStatus::Pending => DocumentStage::Waiting,
            ApprovalStatus::Returned => DocumentStage::Draft,
            default => DocumentStage::Done,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Payroll], [FrozenPayrollCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var Movement $document */
        $stage = $this->stageOf($document);

        if (! $this->inEffect($document) || ! $user->can(self::PERMISSION)) {
            return new ActionSet($stage, []);
        }

        return new ActionSet($stage, [$this->availability($document, $user, DocumentAction::Cancel)]);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var Movement $document */
        $document->loadMissing('approvalRequest');
        $number = $this->numberOf($document);
        $blockers = [];

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($action !== DocumentAction::Cancel) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a movement.");
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

        if ($blockers === [] && HrCorrectionRequest::pendingFor(HrCorrectionRequest::CANCEL_MOVEMENT, $document) !== null) {
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
            approval: FrozenPayrollCheck::frozenDate(FrozenPayrollCheck::datesOf($document)) !== null ? ApprovalRoute::SecondPerson : ApprovalRoute::None,
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var Movement $document */
        $name = $document->employee?->fullName();

        return 'Movement #'.$document->getKey().($name ? " of {$name}" : '');
    }

    public function urlOf(Model $document): ?string
    {
        /** @var Movement $document */
        return route('hrm.leave.index', ['employee_id' => $document->employee_id, 'year' => Carbon::parse($document->movement_date)->year]);
    }

    public function inEffect(Movement $movement): bool
    {
        return $movement->isApplied() && ! $movement->isCancelled()
            && $movement->approvalRequest?->status === ApprovalStatus::Approved;
    }
}
