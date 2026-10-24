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
use App\Modules\Inventory\Models\CropWriteOff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): a crop failure write-off.
 *
 *   Waiting   the Owner approves or rejects it in Approvals; nothing here
 *             (Reverse shows, greyed, with the reason)
 *   Approved  Reverse: the mirror of its journal, on its own date, so the
 *             cost is back on the crop head (the crop was saved after all,
 *             or the wrong head or amount was chosen); raise it again if needed
 *   Rejected, Reversed   nothing more
 *
 * Correct is not offered: a write-off is one line of money, and reversing it
 * and raising it again goes back through the Owner, which a Correct would
 * have to do anyway.
 *
 * Who: `inventory.harvest.manage`, with the engine's Tk 20,000 second person.
 */
class CropWriteOffActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'inventory.crop_write_off';

    public const PERMISSION = 'inventory.harvest.manage';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return CropWriteOff::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can(self::PERMISSION) || $user->can('inventory.reports.view');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var CropWriteOff $document */
        return $document->isWaiting() ? DocumentStage::Waiting : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Ledger], [LedgerEntryCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        $actions = $user->can(self::PERMISSION) ? [$this->availability($document, $user, DocumentAction::Reverse)] : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var CropWriteOff $document */
        $number = $this->numberOf($document);

        if ($action !== DocumentAction::Reverse) {
            return StockPolicySupport::plain($action, $user, self::PERMISSION, [
                new Blocker('not_offered', "{$action->label()} is not offered on a crop write-off. Reverse it and raise it again."),
            ], true, null, primary: false);
        }

        $blockers = [];

        if ($document->isReversed()) {
            $document->loadMissing('reversedBy:id,name');
            $blockers[] = new Blocker('already_reversed', "{$number} was already reversed on ".($document->reversed_at?->format('j M') ?? 'an earlier day').' by '.($document->reversedBy?->name ?? 'somebody').'.');
        } elseif ($document->isWaiting()) {
            $blockers[] = new Blocker('waiting', "{$number} is waiting for the Owner's approval and has posted nothing yet.");
        } elseif (! $document->isApproved()) {
            $blockers[] = new Blocker('rejected', "{$number} was rejected and posted nothing.");
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
            runChecks: $document->isApproved() && ! $document->isReversed(),
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

    public function originalDate(CropWriteOff $writeOff): Carbon
    {
        return Carbon::parse($writeOff->written_off_on)->startOfDay();
    }
}
