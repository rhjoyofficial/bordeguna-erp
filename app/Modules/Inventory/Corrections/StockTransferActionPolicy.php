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
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a stock transfer (FINAL-PLAN 3.3).
 *
 *   On the road   Cancel (stock back onto the batches it left) and Receive
 *                 (in full, as before) or Receive part (the shortage becomes
 *                 a stock correction with a reason); Store, a reason for each
 *   Received, cancelled   nothing: a wrong received transfer is put right by
 *                 a transfer back, which is the same physical act
 *
 * Both are actions the module runs itself (CancelStockTransfer,
 * ReceiveStockTransferShort), not engine corrections: a transfer posts no
 * journal, so there is no date exception and no Tk 20,000 rule to apply. The
 * policy is registered so the buttons and their reasons come from one place.
 *
 * `effectProfile()` = Stock (TransferOnTheRoadCheck).
 */
class StockTransferActionPolicy implements DocumentActionPolicy
{
    public const TYPE = 'inventory.stock_transfer';

    public const PERMISSION = 'inventory.stock.adjust';

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
        return new EffectProfile([EffectKind::Stock], [TransferOnTheRoadCheck::class]);
    }

    public function actionsFor(Model $document, User $user): ActionSet
    {
        /** @var StockTransfer $document */
        $actions = $document->isInTransit() && $user->can(self::PERMISSION)
            ? [$this->availability($document, $user, DocumentAction::Cancel)]
            : [];

        return new ActionSet($this->stageOf($document), $actions);
    }

    public function availability(Model $document, User $user, DocumentAction $action): ActionAvailability
    {
        /** @var StockTransfer $document */
        $blockers = [];

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
