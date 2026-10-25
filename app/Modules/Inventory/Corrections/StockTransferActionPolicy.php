<?php

namespace App\Modules\Inventory\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionAvailability;
use App\Modules\Foundation\Corrections\ActionSet;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DocumentActionPolicy;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\DocumentStage;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Foundation\Corrections\EffectProfile;
use App\Modules\Inventory\Actions\ReverseStockTransfer;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a stock transfer (FINAL-PLAN 3.3).
 *
 *   On the road   Cancel (stock back onto the batches it left) and Receive
 *                 (in full, as before) or Receive part (the shortage becomes
 *                 a stock correction with a reason); Store, a reason for each
 *   Received      Reverse (⭐ initial plan B24, Joy's Q5 = A, 25 Oct 2026):
 *                 the goods leave the destination batches and go back onto
 *                 the source batches they left, at the same cost, through
 *                 the engine (preview, reason, original date, closed month
 *                 for the Owner, Tk 20,000 second person on the goods' value);
 *                 refused while any of them were used at the destination
 *                 (TransferArrivedCheck). StockTransferCorrector.
 *   Cancelled, reversed   nothing
 *
 * Cancel and Receive part are actions the module runs itself
 * (CancelStockTransfer, ReceiveStockTransferShort), not engine corrections.
 * No journal either way: a transfer posts none.
 *
 * `effectProfile()` = Stock (TransferOnTheRoadCheck, TransferArrivedCheck).
 */
class StockTransferActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'inventory.stock_transfer';

    public const PERMISSION = 'inventory.stock.adjust';

    public function __construct(private readonly StockPolicySupport $support) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function modelClass(): string
    {
        return StockTransfer::class;
    }

    public function canView(Model $document, User $user): bool
    {
        return $user->can('inventory.reports.view') || $user->can('inventory.stock.adjust');
    }

    public function stageOf(Model $document): DocumentStage
    {
        /** @var StockTransfer $document */
        // On the road: the stock has left, nothing has arrived yet.
        return $document->isInTransit() ? DocumentStage::ApprovedNoEffect : DocumentStage::Done;
    }

    public function effectProfile(): EffectProfile
    {
        return new EffectProfile([EffectKind::Stock], [TransferOnTheRoadCheck::class, TransferArrivedCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var StockTransfer $document */
        $actions = match (true) {
            ! $user->can(self::PERMISSION) => [],
            $document->isInTransit() => [$this->availability($document, $user, DocumentAction::Cancel)],
            $document->isReceived() => [$this->availability($document, $user, DocumentAction::Reverse)],
            default => [],
        };

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var StockTransfer $document */
        $blockers = [];

        if ($action === DocumentAction::Reverse) {
            return $this->reverseAvailability($document, $user);
        }

        if ($action !== DocumentAction::Cancel) {
            $blockers[] = StockPolicySupport::notOffered($action, 'a stock transfer');
        } elseif (! $document->isInTransit()) {
            $blockers[] = new Blocker('not_in_transit', $document->status === TransferStatus::Cancelled
                ? "Transfer #{$document->id} was already cancelled."
                : "Transfer #{$document->id} has arrived, so it cannot be cancelled. Send the stock back with a new transfer.");
        } elseif (($broken = app(TransferOnTheRoadCheck::class)->blocker($document, $action)) !== null) {
            $blockers[] = $broken;
        }

        return StockPolicySupport::plain($action, $user, self::PERMISSION, $blockers, true, 'cancellation', primary: $action === DocumentAction::Cancel);
    }

    /** ⭐ B24: Reverse a received transfer, with every shared correction rule. */
    private function reverseAvailability(StockTransfer $document, User $user): ActionAvailability
    {
        $blockers = [];

        if ($document->isReversed()) {
            $blockers[] = new Blocker('already_reversed', "Transfer #{$document->id} was already reversed.");
        } elseif (! $document->isReceived()) {
            $blockers[] = new Blocker('not_received', "Transfer #{$document->id} has not arrived, so there is nothing to reverse.");
        }

        return $this->support->correction(
            document: $document,
            user: $user,
            action: DocumentAction::Reverse,
            blockers: $blockers,
            profile: $this->effectProfile(),
            originalDate: $this->documentDate($document),
            basisMinor: $this->totalMinor($document),
            permission: self::PERMISSION,
            number: $this->numberOf($document),
            runChecks: $blockers === [],
        );
    }

    /** The day the goods arrived (the arriving legs' own day). */
    public function documentDate(StockTransfer $document): Carbon
    {
        [$in] = ReverseStockTransfer::legs($document);
        $at = $in->first()?->occurred_at ?? $document->received_at ?? $document->dispatched_at;

        return Carbon::parse($at)->startOfDay();
    }

    /** The value of what arrived, in paisa (the Tk 20,000 rule reads it). */
    public function totalMinor(StockTransfer $document): int
    {
        [$in] = ReverseStockTransfer::legs($document);

        return $in->sum(fn (StockMovement $m) => StockValue::of($m)->absolute()->minor);
    }

    /** Receive part: the same rule as Cancel (on the road, Store). */
    public function mayReceivePart(StockTransfer $transfer, User $user): ?Blocker
    {
        if (! $user->can(self::PERMISSION)) {
            return new Blocker('no_permission', 'You are not allowed to receive stock.');
        }

        return $transfer->isInTransit() ? null : new Blocker('not_in_transit', "Transfer #{$transfer->id} is no longer on its way.");
    }

    public function numberOf(Model $document): string
    {
        return "Transfer #{$document->getKey()}";
    }

    public function urlOf(Model $document): ?string
    {
        return route('inventory.stock.transfers.index');
    }
}
