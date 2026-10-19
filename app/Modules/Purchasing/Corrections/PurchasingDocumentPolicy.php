<?php

namespace App\Modules\Purchasing\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\CorrectionChain;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B2 (20 Oct 2026): what the four supplier money
 * documents (bill, payment, purchase return, credit note) share in the
 * corrections process, so each type only says what is its own.
 *
 *   permission   accounting.correct (Owner, Finance: FINAL-PLAN 4.5, "Correct
 *                or reverse ... supplier payment or bill in an open month:
 *                Accountant"; the existing record permissions keep their old
 *                Reverse routes)
 *   stage        always Done: each posts the moment it is recorded
 *   blockers     already reversed or corrected; a correction already waiting;
 *                the type's own refusals; every dependency check of the type;
 *                a closed month for anybody but the Owner (requestable)
 *   approval     the Tk 20,000 second person on Reverse (Correct is judged on
 *                max(original, corrected) by the service once the form exists)
 *
 * ⚠ Edit and Delete are never offered: a posted document is corrected, never
 * changed in place (FINAL-PLAN section 2).
 */
abstract class PurchasingDocumentPolicy implements DocumentActionPolicy
{
    public const PERMISSION = 'accounting.correct';

    public function __construct(
        protected readonly CorrectionDatePolicy $dates,
        protected readonly SecondPersonRule $secondPerson,
    ) {}

    /** The document's business date (what the reversal lands on by default). */
    abstract public function documentDate(Model $document): Carbon;

    /** The document's own total in paisa (what the Tk 20,000 rule reads). */
    abstract public function totalMinor(Model $document): int;

    /** @return list<DocumentAction> the actions this type offers, in display order */
    abstract protected function offered(): array;

    /**
     * This type's own refusals for one action, before the dependency checks.
     *
     * @return list<Blocker>
     */
    protected function ownBlockers(Model $document, DocumentAction $action): array
    {
        return [];
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('purchasing.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = [];

        // ⚠ Somebody without the permission sees no correction buttons at all.
        if ($user->can(self::PERMISSION)) {
            foreach ($this->offered() as $action) {
                $actions[] = $this->availability($document, $user, $action);
            }
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        $offered = in_array($action, $this->offered(), true);
        $blockers = [];
        $number = $this->numberOf($document);

        if (! $offered) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on {$number}.");
        }

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        $reversed = (bool) $document->isReversed();

        if ($reversed) {
            $blockers[] = $this->alreadyDone($document);
        }

        if (CorrectionRequest::query()->where('pending_key', $document->getMorphClass().':'.$document->getKey())->exists()) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$number} is already waiting for approval.");
        }

        if (! $reversed && $offered) {
            foreach ($this->ownBlockers($document, $action) as $blocker) {
                $blockers[] = $blocker;
            }

            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        $dates = $this->dates->resolve($this->documentDate($document), $action, ReverseDateChoice::OriginalDate, null, $user);

        if ($dates->blocker !== null && $dates->blocker->code === 'period_closed_not_owner') {
            $blockers[] = $dates->blocker;
        }

        // One sentence per reason.
        $byCode = [];

        foreach ($blockers as $blocker) {
            $byCode[$blocker->code] ??= $blocker;
        }

        $blockers = array_values($byCode);
        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        $above = $action === DocumentAction::Reverse && $this->secondPerson->applies((int) $document->business_id, $this->totalMinor($document));

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: $offered,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Correct ? 'correction' : 'reversal',
            approval: $this->secondPerson->route($closedNotOwner || $dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    private function alreadyDone(Model $document): Blocker
    {
        $document->loadMissing('reversedBy:id,name');
        $number = $this->numberOf($document);
        $at = $document->getAttribute('reversed_at');
        $on = $at instanceof \DateTimeInterface ? $at->format('j M') : 'an earlier day';
        $by = $document->reversedBy?->name ?? 'somebody';
        $successor = CorrectionChain::linkAsOriginal($document)?->successor_number;

        return $successor === null
            ? new Blocker('already_reversed', "{$number} was already reversed on {$on} by {$by}.")
            : new Blocker('already_corrected', "{$number} was already corrected on {$on} by {$by}. Correct {$successor} instead.");
    }
}
