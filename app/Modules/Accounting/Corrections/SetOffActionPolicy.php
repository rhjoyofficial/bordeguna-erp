<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Set-offs in the corrections process (decision D-F, 18 Oct 2026): which
 * buttons, which blockers.
 *
 *   Reverse   posted and not reversed; one ledger entry; its allocations
 *             intact; the month open, or the Owner (a closed month is
 *             requestable to the Owner); the Tk 20,000 second person
 *   Correct   NOT offered: a wrong set-off is reversed and raised again, so
 *             the new one goes through the approval inbox like the first
 *   Edit, Delete   never: a pending set-off is rejected in the inbox instead
 *
 * `effectProfile()` = Ledger (LedgerEntryCheck) + Allocation (the bills and
 * invoices it cleared, SetOffAllocationCheck).
 */
class SetOffActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'accounting.set_off';

    public const PERMISSION = 'accounting.correct';

    public function __construct(
        private readonly CorrectionDatePolicy $dates,
        private readonly SecondPersonRule $secondPerson,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return SetOff::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('purchasing.reports.view') && $user->can('sales.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var SetOff $document */
        return match (true) {
            $document->isPending() => DocumentStage::Waiting,
            $document->status === SetOff::REJECTED => DocumentStage::ApprovedNoEffect,
            default => DocumentStage::Done,
        };
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Allocation],
            [LedgerEntryCheck::class, SetOffAllocationCheck::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var SetOff $document */
        $actions = [];

        // ⚠ Only a set-off that reached the books can be reversed; somebody
        // without the permission sees no correction button at all.
        if ($user->can(self::PERMISSION) && ($document->isPosted() || $document->isReversed())) {
            $actions[] = $this->availability($document, $user, DocumentAction::Reverse);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var SetOff $document */
        $offered = $action === DocumentAction::Reverse;
        $blockers = [];

        if (! $offered) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a set-off. Reverse it and raise a new one.");
        }

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        if ($document->isReversed()) {
            $document->loadMissing('reversedBy:id,name');
            $on = $document->reversed_at?->format('j M') ?? 'an earlier day';
            $blockers[] = new Blocker('already_reversed', "{$document->number} was already reversed on {$on} by ".($document->reversedBy?->name ?? 'somebody').'.');
        } elseif (! $document->isPosted()) {
            $blockers[] = new Blocker('not_posted', "{$document->number} never reached the books, so there is nothing to reverse.");
        }

        $waiting = CorrectionRequest::query()
            ->where('pending_key', $document->getMorphClass().':'.$document->getKey())
            ->exists();

        if ($waiting) {
            $blockers[] = new Blocker('request_waiting', "A reversal of {$document->number} is already waiting for approval.");
        }

        if ($document->isPosted()) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        $dates = $this->dates->resolve($document->set_off_on->copy(), $action, ReverseDateChoice::OriginalDate, null, $user);

        if ($dates->blocker !== null && $dates->blocker->code === 'period_closed_not_owner') {
            $blockers[] = $dates->blocker;
        }

        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        $above = $offered && $this->secondPerson->applies((int) $document->business_id, $document->money()->minor);

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: $offered,
            requiresReason: true,
            reasonContext: 'reversal',
            approval: $this->secondPerson->route($closedNotOwner || $dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('accounting.set-offs.show', $document);
    }
}
