<?php

namespace App\Modules\Accounting\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 2, accounts (B7, 24 Oct 2026): the one shape every
 * posted accounts document shares (vouchers, money transfers, inter-business
 * transfers, manual journals, party adjustments), so the rules cannot drift
 * between five copies of IncomeVoucherActionPolicy.
 *
 *   Reverse / Correct   offered on a posted record to whoever holds the type's
 *                       permission; refused when already reversed, when a
 *                       correction already waits, when a dependency check
 *                       finds something (a bank match, a settled invoice), or
 *                       when the month is closed and the person is not the
 *                       Owner (then requestable to the Owner)
 *   anything else       the subclass answers (Fix details, Withdraw), or it
 *                       is "not offered"
 *
 * ⚠ The approval route is worked out exactly as the Phase 0 slice does: the
 * Tk 20,000 second-person rule on the record's own total, the Owner for a
 * closed month. The correction service asks again under the lock.
 */
abstract class AccountsDocumentPolicy implements DocumentActionPolicy
{
    public function __construct(
        protected readonly CorrectionDatePolicy $dates,
        protected readonly SecondPersonRule $secondPerson,
    ) {}

    /** The permission Reverse and Correct need. */
    abstract protected function correctPermission(): string;

    /** @return list<DocumentAction> the correction actions offered on a posted record, in display order */
    abstract protected function correctionActions(Model $document): array;

    /** The record's own business date. */
    abstract protected function dateOf(Model $document): Carbon;

    /** The record's own total in paisa (what the Tk 20,000 rule reads). */
    abstract protected function totalMinorOf(Model $document): int;

    abstract protected function isReversed(Model $document): bool;

    /** "PV-2026-27-0041 was already reversed on 15 Oct by Musa." */
    abstract protected function alreadyDone(Model $document): Blocker;

    /** Whether the record reached the books (a draft or a waiting voucher did not). */
    protected function isPosted(Model $document): bool
    {
        return true;
    }

    /** Blockers only this type knows (a cheque status, a receiver's closed month). */
    protected function extraBlockers(Model $document, User $user, DocumentAction $action): array
    {
        return [];
    }

    /** The non-correction actions (Fix details, Withdraw), in display order. */
    protected function otherActions(Model $document, User $user): array
    {
        return [];
    }

    /** Availability of an action this base class does not know. */
    protected function otherAvailability(Model $document, User $user, DocumentAction $action): ?ActionAvailability
    {
        return null;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('accounting.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = [];

        // ⚠ Somebody without the permission sees no correction button at all.
        if ($this->isPosted($document) && $user->can($this->correctPermission())) {
            foreach ($this->correctionActions($document) as $action) {
                $actions[] = $this->availability($document, $user, $action);
            }
        }

        foreach ($this->otherActions($document, $user) as $action) {
            $actions[] = $this->availability($document, $user, $action);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        if (! $action->isCorrection() || ! in_array($action, $this->correctionActions($document), true)) {
            return $this->otherAvailability($document, $user, $action)
                ?? new ActionAvailability($action, false, [new Blocker('not_offered', "{$action->label()} is not offered on this record.")], $this->correctPermission(), false, true, null);
        }

        $blockers = [];
        $number = $this->numberOf($document);

        if (! $user->can($this->correctPermission())) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        if (! $this->isPosted($document)) {
            $blockers[] = new Blocker('not_posted', "{$number} has not reached the books, so there is nothing to {$action->value}.");
        } elseif ($this->isReversed($document)) {
            $blockers[] = $this->alreadyDone($document);
        }

        $waiting = CorrectionRequest::query()
            ->where('pending_key', $document->getMorphClass().':'.$document->getKey())
            ->exists();

        if ($waiting) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$number} is already waiting for approval.");
        }

        if ($this->isPosted($document) && ! $this->isReversed($document)) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }

            array_push($blockers, ...$this->extraBlockers($document, $user, $action));
        }

        $dates = $this->dates->resolve($this->dateOf($document)->copy(), $action, ReverseDateChoice::OriginalDate, null, $user);

        if ($dates->blocker !== null && $dates->blocker->code === 'period_closed_not_owner') {
            $blockers[] = $dates->blocker;
        }

        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        $above = $action === DocumentAction::Reverse && $this->secondPerson->applies((int) $document->getAttribute('business_id'), abs($this->totalMinorOf($document)));

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $this->correctPermission(),
            primary: true,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Correct ? 'correction' : 'reversal',
            approval: $this->secondPerson->route($closedNotOwner || $dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    /** A plain "who, when" for the already-reversed sentence. */
    protected function reversedSentence(string $number, ?Carbon $at, ?string $by, ?string $successor): Blocker
    {
        $on = $at?->format('j M') ?? 'an earlier day';
        $by ??= 'somebody';

        return $successor === null
            ? new Blocker('already_reversed', "{$number} was already reversed on {$on} by {$by}.")
            : new Blocker('already_corrected', "{$number} was already corrected on {$on} by {$by}. Correct {$successor} instead.");
    }

    /** An ActionAvailability for an action that posts nothing (no dates, no second person). */
    protected function plain(DocumentAction $action, array $blockers, string $permission, bool $primary, string $reasonContext): ActionAvailability
    {
        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $permission,
            primary: $primary,
            requiresReason: true,
            reasonContext: $reasonContext,
            approval: ApprovalRoute::None,
        );
    }
}
