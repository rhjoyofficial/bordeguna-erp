<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Models\CropHarvest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): a recorded harvest.
 *
 *   Posted    Reverse: every batch it made taken back out, the head credited
 *             back to the paisa, on the harvest's own date (or by the Owner
 *             as a one-document exception in a closed month); only while
 *             every batch is untouched (CropHarvestStockCheck)
 *   Reversed  nothing more
 *
 * ⚠ Correct is not offered: a wrong harvest is reversed and recorded again,
 * the same two steps a Correct would take, and the popup that records it is
 * the place its rules live. Edit and Delete never: it moved stock and money
 * the moment it was recorded.
 *
 * ⚠ Some of the harvest already sold: refused with a sentence, like a goods
 * receipt and a production run. The plan's Tk 10,000 cost-of-goods rule is the
 * accountant's stock correction, not something this screen guesses.
 *
 * Who: `inventory.harvest.manage` (Owner, Manager), with the second person of
 * the Tk 20,000 rule on the harvest's amount (the engine's rule).
 */
class CropHarvestActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'inventory.crop_harvest';

    public const PERMISSION = 'inventory.harvest.manage';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CropHarvest::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION) || $user->can('inventory.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        return DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile(
            [EffectKind::Ledger, EffectKind::Stock],
            [LedgerEntryCheck::class, CropHarvestStockCheck::class],
        );
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Reverse)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var CropHarvest $document */
        $number = $this->numberOf($document);

        if ($action !== DocumentAction::Reverse) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [
                new Blocker('not_offered', "{$action->label()} is not offered on a harvest. Reverse it and record it again."),
            ], true, null, primary: false);
        }

        $blockers = [];

        if ($document->isReversed()) {
            $document->loadMissing('reversedBy:id,name');
            $blockers[] = new Blocker('already_reversed', "{$number} was already reversed on ".($document->reversed_at?->format('j M') ?? 'an earlier day').' by '.($document->reversedBy?->name ?? 'somebody').'.');
        }

        return $this->support->correction(
            $document,
            $user,
            $action,
            $blockers,
            $this->effectProfile(),
            $this->originalDate($document),
            (int) $document->amount_paisa,
            self::PERMISSION,
            $number,
            runChecks: ! $document->isReversed(),
        );
    }

    public function numberOf(Model $document): string
    {
        return (string) $document->number;
    }

    public function urlOf(Model $document): ?string
    {
        return route('inventory.harvests.index');
    }

    public function originalDate(CropHarvest $harvest): Carbon
    {
        return Carbon::parse($harvest->harvested_on)->startOfDay();
    }
}
