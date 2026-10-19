<?php

namespace App\Modules\Sales\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ The shape every sales document's policy shares (corrections wave 1, 20 Oct
 * 2026): the same steps IncomeVoucherActionPolicy takes, in the same order, so
 * the four sales types cannot drift from the slice that was proven.
 *
 *   1. offered at all on this document?   (a type says which, in which order)
 *   2. the person's permission            (a type may name it per document:
 *                                          a cashier's own sale while her shift
 *                                          is open is `sales.sell`)
 *   3. a missing safety check fails closed (v2, C14)
 *   4. already reversed / corrected
 *   5. a correction already waiting
 *   6. the type's own blockers, then its dependency checks
 *   7. the date rule (a closed month is requestable to the Owner)
 *   8. the approval route (second person above the limit, the Owner)
 *
 * Every sales document posts the moment it is recorded, so the stage is always
 * Done and Edit or Delete is never offered here (an order is not a posted
 * document and has its own rules: SalesOrderActions).
 */
abstract class SalesDocumentPolicy implements DocumentActionPolicy
{
    public function __construct(
        protected readonly CorrectionDatePolicy $dates,
        protected readonly SecondPersonRule $secondPerson,
    ) {}

    /** @return list<DocumentAction> the buttons this document may show, in order */
    abstract protected function offered(Model $document): array;

    abstract protected function permissionFor(Model $document, User $user, DocumentAction $action): string;

    abstract public function documentDate(Model $document): Carbon;

    abstract public function totalMinor(Model $document): int;

    /** @return list<Blocker> refusals that are this type's own (not a dependency) */
    protected function ownBlockers(Model $document, User $user, DocumentAction $action): array
    {
        return [];
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = [];

        foreach ($this->offered($document) as $action) {
            // ⚠ Somebody without the permission sees no correction button at all.
            if ($user->can($this->permissionFor($document, $user, $action))) {
                $actions[] = $this->availability($document, $user, $action);
            }
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        $offered = in_array($action, $this->offered($document), true);
        $permission = $this->permissionFor($document, $user, $action);
        $number = $this->numberOf($document);
        $blockers = [];

        if (! $offered) {
            $blockers[] = new Blocker('not_offered', "{$action->label()} is not offered on {$number}.");
        }

        if (! $user->can($permission)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        $reversed = method_exists($document, 'isReversed') && $document->isReversed();

        if ($reversed) {
            $blockers[] = $this->alreadyDone($document);
        }

        $waiting = CorrectionRequest::query()
            ->where('pending_key', CorrectionLink::liveKeyFor($document))
            ->exists();

        if ($waiting) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$number} is already waiting for approval.");
        }

        if (! $reversed) {
            foreach ($this->ownBlockers($document, $user, $action) as $blocker) {
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

        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        // Correct is judged on max(original, corrected) once the form is known;
        // a Reverse moves exactly the original.
        $above = $action === DocumentAction::Reverse && $this->secondPerson->applies((int) $document->business_id, $this->totalMinor($document));

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $permission,
            primary: $offered,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Correct ? 'correction' : 'reversal',
            approval: $this->secondPerson->route($closedNotOwner || $dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    /** "INV-2026-27-0012 was already credited on 14 Oct by Shuborna." */
    protected function alreadyDone(Model $document): Blocker
    {
        $document->loadMissing('reversedBy:id,name');
        $number = $this->numberOf($document);
        $on = $document->reversed_at?->format('j M') ?? 'an earlier day';
        $by = $document->reversedBy?->name ?? 'somebody';
        $successor = CorrectionLink::query()
            ->where('original_type', $document->getMorphClass())
            ->where('original_id', $document->getKey())
            ->value('successor_number');

        return $successor === null
            ? new Blocker('already_reversed', "{$number} was already {$this->doneWord()} on {$on} by {$by}.")
            : new Blocker('already_corrected', "{$number} was already corrected on {$on} by {$by}. Correct {$successor} instead.");
    }

    /** The word for a reversal of this type: "reversed", "credited", "voided". */
    protected function doneWord(): string
    {
        return 'reversed';
    }
}
