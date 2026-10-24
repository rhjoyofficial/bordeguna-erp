<?php

namespace App\Modules\Assets\Corrections;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Assets\Models\FixedAsset;
use App\Modules\Assets\Models\FixedAssetDepreciation;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\BankMatchBlocker;
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
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026): a fixed asset on the books
 * (FINAL-PLAN 3.5, "Fixed asset (on the books)").
 *
 *   Reverse   entered by mistake: its purchase entry is mirrored on its own
 *             date and every depreciation charge on ITS own date, through the
 *             corrections process (Tk 20,000 second person, Owner-only closed
 *             month); the asset stays, marked Reversed, and is never
 *             depreciated again
 *   Dispose   it left the business (sold, scrapped, donated, lost): one
 *             journal takes its cost and accumulated depreciation off the
 *             books, with the money received and the gain or loss
 *             (DisposeFixedAsset, the module's own action)
 *
 * Who: the plan's "Accountant prepares, Owner approves". `assets.correct` is
 * the Owner's, and Dispose also asks for the Owner by person (Owners::is), so a
 * tick in Settings > Roles alone is not enough to take an asset off the books.
 *
 * ⚠ Reverse needs a purchase entry of its own. An opening asset's cost sits in
 * the opening balances (FEA-003): reversing "it" would leave that balance in
 * place, so the button says to dispose of it or correct the opening balance.
 *
 * ⚠ The closed-month pass covers ONE journal source, the asset. A depreciation
 * charge is its own source, so a charge in a closed month cannot ride on the
 * pass: the button says the Owner unlocks that month first.
 *
 * `effectProfile()` = Ledger + BankMatch, each covered by its check (v2, C14).
 */
class FixedAssetActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'assets.fixed_asset';

    public const PERMISSION = 'assets.correct';

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
        return FixedAsset::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('assets.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var FixedAsset $document */
        return $document->isDisposed() ? DocumentStage::LeftCompany : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::BankMatch],
            [LedgerEntryCheck::class, BankMatchBlocker::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = [];

        // ⚠ Somebody without the permission sees no buttons at all.
        if ($user->can(self::PERMISSION)) {
            $actions[] = $this->availability($document, $user, DocumentAction::Dispose);
            $actions[] = $this->availability($document, $user, DocumentAction::Reverse);
        }

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var FixedAsset $document */
        return match ($action) {
            DocumentAction::Reverse => $this->reverse($document, $user),
            DocumentAction::Dispose => $this->dispose($document, $user),
            default => new ActionAvailability($action, false, [new Blocker('not_offered', "{$action->label()} is not offered on a fixed asset.")], self::PERMISSION, false, true, null),
        };
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->asset_code;
    }

    public function urlOf(Model $document): ?string
    {
        return route('assets.index');
    }

    private function reverse(FixedAsset $asset, User $user): ActionAvailability
    {
        $action = DocumentAction::Reverse;
        $blockers = $this->common($asset, $user, $action);
        $byAcquisition = $asset->cost_on_books === FixedAsset::COST_BY_ACQUISITION;

        if ($asset->isOnTheRegister() && ! $byAcquisition) {
            $blockers[] = $asset->cost_on_books === FixedAsset::COST_IN_OPENING_BALANCES
                ? new Blocker('opening_asset', "{$asset->asset_code}'s cost sits in the opening balances, not in an entry of its own, so dispose of it or ask the accountant to correct the opening balance.")
                : new Blocker('not_on_books', "{$asset->asset_code} was never put on the books, so there is no entry to reverse.");
        }

        if ($asset->isOnTheRegister() && $byAcquisition) {
            foreach ($this->effectProfile()->checks() as $check) {
                if (($blocker = $check->blocker($asset, $action)) !== null) {
                    $blockers[] = $blocker;
                }
            }

            if (($closed = $this->closedCharge($asset)) !== null) {
                $blockers[] = $closed;
            }
        }

        $dates = $this->dates->resolve($asset->acquired_on->copy(), $action, ReverseDateChoice::OriginalDate, null, $user);

        if ($dates->blocker !== null && $dates->blocker->code === 'period_closed_not_owner') {
            $blockers[] = $dates->blocker;
        }

        $closedNotOwner = $dates->needsClosedPeriodPass && ! Owners::mayCorrectClosedMonth($user);
        $above = $this->secondPerson->applies((int) $asset->business_id, Money::ofTaka((string) $asset->cost)->minor);

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: true,
            requiresReason: true,
            reasonContext: 'reversal',
            approval: $this->secondPerson->route($dates->needsClosedPeriodPass, ! $closedNotOwner, $above),
            date: $dates->summary(),
        );
    }

    private function dispose(FixedAsset $asset, User $user): ActionAvailability
    {
        $action = DocumentAction::Dispose;
        $blockers = $this->common($asset, $user, $action);

        if ($asset->isOnTheRegister() && ! $asset->costIsOnTheBooks()) {
            $blockers[] = new Blocker('not_on_books', "{$asset->asset_code} was never put on the books, so there is nothing to take off them.");
        }

        if ($user->can(self::PERMISSION) && ! Owners::is($user)) {
            $blockers[] = new Blocker('owner_only', 'Only the Owner can dispose of a fixed asset.', resolvableBy: 'owner');
        }

        return new ActionAvailability(
            action: $action,
            allowed: $blockers === [],
            blockers: $blockers,
            permission: self::PERMISSION,
            primary: true,
            requiresReason: true,
            reasonContext: 'disposal',
            approval: ApprovalRoute::None,
        );
    }

    /**
     * What both actions refuse alike: the permission, a missing safety check,
     * an asset already reversed or disposed, a correction already waiting.
     *
     * @return list<Blocker>
     */
    private function common(FixedAsset $asset, User $user, DocumentAction $action): array
    {
        $blockers = [];

        if (! $user->can(self::PERMISSION)) {
            $blockers[] = Blocker::noPermission($action);
        }

        if ($this->effectProfile()->uncoveredEffects() !== []) {
            $blockers[] = Blocker::missingCheck();
        }

        if ($asset->isReversed()) {
            $asset->loadMissing('reversedBy:id,name');
            $blockers[] = new Blocker('already_reversed', "{$asset->asset_code} was already reversed on ".($asset->reversed_at?->format('j M') ?? 'an earlier day').' by '.($asset->reversedBy?->name ?? 'somebody').'.');
        }

        if ($asset->isDisposed()) {
            $blockers[] = new Blocker('already_disposed', "{$asset->asset_code} left the business on ".Tk::date($asset->disposed_on).'.');
        }

        if (CorrectionRequest::query()->where('pending_key', CorrectionLink::liveKeyFor($asset))->exists()) {
            $blockers[] = new Blocker('request_waiting', "A correction of {$asset->asset_code} is already waiting for approval.");
        }

        return $blockers;
    }

    /** A live depreciation charge dated in a closed month (the pass cannot carry it). */
    private function closedCharge(FixedAsset $asset): ?Blocker
    {
        $charge = FixedAssetDepreciation::query()
            ->where('fixed_asset_id', $asset->id)
            ->orderBy('charged_on')
            ->get()
            ->first(fn (FixedAssetDepreciation $row) => AccountingPeriod::isClosedOn($row->charged_on->copy())
                && self::liveJournalOf($row) !== null);

        if ($charge === null) {
            return null;
        }

        $month = $charge->charged_on->format('F Y');

        return new Blocker('closed_depreciation', "Depreciation for {$month} sits in a closed month, so the Owner unlocks {$month} first and closes it again after the reversal.", resolvableBy: 'owner');
    }

    /** The charge's own journal, while it is not reversed. */
    public static function liveJournalOf(FixedAssetDepreciation $charge): ?Journal
    {
        return Journal::query()
            ->where('source_type', $charge->getMorphClass())
            ->where('source_id', $charge->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->first();
    }
}
