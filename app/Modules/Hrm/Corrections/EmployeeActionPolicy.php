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
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Support\FrozenAttendance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ An employee in the corrections process (corrections wave 3, 24 Oct 2026;
 * FINAL-PLAN 3.4):
 *
 *   Draft, never used     Delete (to the Recycle bin; the Owner restores)
 *   Separated             Undo separation, before the final pay is approved.
 *                         HR asks, a second person confirms. After that it is
 *                         a reinstatement, not an undo.
 *   anyone else           nothing here
 *
 * ⚠ Undo separation uses the Reverse verb (the contract has no "Undo"; FINAL-
 * PLAN 2 retired "Undo" into Reverse), drawn as "Undo separation" on screen.
 * The corrections service never runs it: the effect profile is empty (an
 * employee posts nothing), and the action is EmployeeController's route asking
 * this policy again under the lock.
 */
class EmployeeActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'hrm.employee';

    public const PERMISSION = 'hrm.employees.manage';

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return Employee::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var Employee $document */
        return match ($document->status) {
            EmployeeStatus::Draft => DocumentStage::Draft,
            EmployeeStatus::Separated => DocumentStage::LeftCompany,
            default => DocumentStage::ListData,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([], []);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var Employee $document */
        $stage = $this->stageOf($document);

        if (! $user->can(self::PERMISSION)) {
            return new ActionSet($stage, []);
        }

        $verb = match ($stage) {
            DocumentStage::Draft => DocumentAction::Delete,
            DocumentStage::LeftCompany => DocumentAction::Reverse,
            default => null,
        };

        return new ActionSet($stage, $verb === null ? [] : [$this->availability($document, $user, $verb)]);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var Employee $document */
        $blockers = [];
        $name = $this->numberOf($document);

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($action === DocumentAction::Delete) {
            if (($why = $document->whyNotRemovable()) !== null) {
                $blockers[] = new Blocker('used', $why);
            }
        } elseif ($action === DocumentAction::Reverse) {
            $blockers = [...$blockers, ...$this->undoBlockers($document, $name)];
        } else {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on an employee.");
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: true,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Delete ? 'deletion' : 'reversal',
            // "HR requests, a second approver confirms" (FINAL-PLAN 3.4).
            approval: $action === DocumentAction::Reverse ? ApprovalRoute::SecondPerson : ApprovalRoute::None,
        );
    }

    public function numberOf(Model $document): string
    {
        /** @var Employee $document */
        return $document->fullName().' ('.$document->employee_code.')';
    }

    public function urlOf(Model $document): ?string
    {
        return route('hrm.employees.show', $document->getKey());
    }

    /** @return list<Blocker> */
    private function undoBlockers(Employee $employee, string $name): array
    {
        if ($employee->status !== EmployeeStatus::Separated || $employee->separation_date === null) {
            return [new Blocker('not_separated', "{$name} is not separated, so there is nothing to undo.")];
        }

        $date = Carbon::parse($employee->separation_date);

        // ⛔ The final pay is worked out from the leaving date. Once the payroll
        // for that cycle is approved, undoing the separation would change a
        // number nobody sees again: that is a reinstatement, not an undo.
        if (FrozenAttendance::runCovering($date) !== null) {
            return [new Blocker('final_pay_approved', "Payroll for the cycle holding {$date->format('d/m/Y')} is approved, so this needs a reinstatement instead of an undo.")];
        }

        $newer = EmployeeAssignment::withoutGlobalScope(BusinessScope::class)
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '>', $date->toDateString())
            ->exists();

        if ($newer) {
            return [new Blocker('newer_assignment', "{$name} has an assignment that starts after the leaving date, so the separation can no longer be undone.")];
        }

        if (HrCorrectionRequest::pendingFor(HrCorrectionRequest::UNDO_SEPARATION, $employee) !== null) {
            return [new Blocker('requested', "Undoing the separation of {$name} is already waiting for a second person.")];
        }

        return [];
    }
}
