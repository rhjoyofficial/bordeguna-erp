<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
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
use App\Modules\Purchasing\Models\SupplierDiscount;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Supplier discounts in the corrections process (ruling P3, 18 Oct 2026):
 * which buttons, which blockers.
 *
 *   Reverse   not reversed; one ledger entry; its allocation on the bill
 *             intact; no unit of a discounted batch gone since (the cost
 *             would go back onto units that are no longer there); the month
 *             open, or the Owner (a closed month is requestable to the
 *             Owner); the Tk 20,000 second person
 *   Correct   NOT offered: a wrong discount is reversed and recorded again
 *   Edit, Delete   never
 *
 * `effectProfile()` = Ledger (LedgerEntryCheck) + Allocation (the bill it
 * reduced, SupplierDiscountAllocationCheck) + Stock (the batches whose cost it
 * lowered, SupplierDiscountStockCheck).
 */
class SupplierDiscountActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'purchasing.supplier_discount';

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
        return SupplierDiscount::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('purchasing.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Allocation, EffectKind::Stock],
            [LedgerEntryCheck::class, SupplierDiscountAllocationCheck::class, SupplierDiscountStockCheck::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = [];

        // Somebody without the permission sees no correction button at all.
        if ($user->can(self::PERMISSION)) {
            $actions[] = $this->availability($document, $user, DocumentAction::Reverse);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var SupplierDiscount $document */
        $offered = $action === DocumentAction::Reverse;
        $blockers = [];

        if (! $offered) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on a discount. Reverse it and record it again.");
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
        }

        $waiting = CorrectionRequest::query()
            ->where('pending_key', $document->getMorphClass().':'.$document->getKey())
            ->exists();

        if ($waiting) {
            $blockers[] = new Blocker('request_waiting', "A reversal of {$document->number} is already waiting for approval.");
        }

        if (! $document->isReversed()) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        $dates = $this->dates->resolve($document->discount_on->copy(), $action, ReverseDateChoice::OriginalDate, null, $user);

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
        /** @var SupplierDiscount $document */
        return route('purchasing.bills.show', $document->supplier_bill_id);
    }
}
