<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\CorrectionDatePolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Foundation\Corrections\Owners;
use App\Modules\Foundation\Corrections\ReverseDateChoice;
use App\Modules\Foundation\Corrections\SecondPersonRule;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): what the four stock policies share,
 * so a stock correction asks the questions every money correction asks in the
 * same words (IncomeVoucherActionPolicy is the pattern).
 *
 *   - a correction of this record already waiting for approval;
 *   - every dependency check of the type's effect profile, and fail closed
 *     when an effect has no check (v2, C14);
 *   - the original's own date: an open month goes ahead, a closed month is the
 *     Owner's one-document exception, or a request to the Owner;
 *   - the Tk 20,000 second person, judged on the record's own value.
 *
 * Edit, Withdraw, Cancel, Receive part and Delete are not corrections the
 * engine runs: they come back with `plain()`, no date and no route.
 */
final class StockPolicySupport
{
    public function __construct(
        private readonly CorrectionDatePolicy $dates,
        private readonly SecondPersonRule $secondPerson,
    ) {}

    /**
     * A Reverse or Correct, with every shared rule applied.
     *
     * @param  list<Blocker>  $blockers  the type's own blockers so far
     */
    public function correction(
        Model $document,
        User $user,
        DocumentAction $action,
        array $blockers,
        EffectProfile $profile,
        Carbon $originalDate,
        int $basisMinor,
        string $permission,
        string $number,
        bool $runChecks,
        bool $primary = true,
    ): ActionAvailability {
        if (! $user->can($permission)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($profile->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        if (CorrectionRequest::query()->where('pending_key', CorrectionLink::liveKeyFor($document))->exists()) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$number} is already waiting for approval.");
        }

        if ($runChecks) {
            foreach ($profile->checks() as $check) {
                if (($blocker = $check->blocker($document, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }
        }

        $dates = $this->dates->resolve($originalDate->copy(), $action, ReverseDateChoice::OriginalDate, null, $user);

        if ($dates->blocker !== null && $dates->blocker->code === 'period_closed_not_owner') {
            $blockers[] = $dates->blocker;
        }

        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        $above = $this->secondPerson->applies((int) $document->business_id, $basisMinor);

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $permission,
            primary: $primary,
            requiresReason: true,
            reasonContext: $action === DocumentAction::Correct ? 'correction' : 'reversal',
            approval: $this->secondPerson->route($dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    /**
     * An action the module runs itself (Edit, Withdraw, Cancel, Receive part,
     * Delete): permission and the type's blockers only.
     *
     * @param  list<Blocker>  $blockers
     */
    public static function plain(DocumentAction $action, User $user, string $permission, array $blockers, bool $requiresReason, ?string $reasonContext, bool $primary = true): ActionAvailability
    {
        if (! $user->can($permission)) {
            $blockers[] = Blocker::noPermission($action);
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: $permission,
            primary: $primary,
            requiresReason: $requiresReason,
            reasonContext: $reasonContext,
            approval: ApprovalRoute::None,
        );
    }

    /** Whether the original's own date is inside the closed period right now. */
    public function closedOn(Carbon $date): bool
    {
        return AccountingPeriod::isClosedOn($date);
    }

    public static function notOffered(DocumentAction $action, string $what): Blocker
    {
        return new Blocker('not_offered', "{$action->label()} is not offered on {$what}.");
    }
}
