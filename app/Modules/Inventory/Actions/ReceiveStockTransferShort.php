<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\AdjustmentCause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a transfer that arrived short
 * (FINAL-PLAN 3.3, "Receive part, with the shortage recorded and a reason").
 *
 * ⚠⚠ Why receive it all and then record the loss, rather than receive less.
 * The units on the road are still on the books (Stock on Hand, and the
 * reconciliation's in-transit add-back). Receiving only what arrived would
 * drop the rest out of every batch while the ledger kept them: a value with
 * no goods behind it, and no journal saying where it went. The shortage is a
 * LOSS, so it goes the way every loss goes: a stock correction (damaged, lost
 * or stolen) on the batches it landed in, whose source is this transfer, with
 * the person's reason, through the approval limit. Approved, it posts the
 * write-off like any other; waiting, the destination shows the units until
 * somebody decides, exactly as a damage record does.
 *
 * Taken from the last batches first, so the oldest dated goods that did
 * arrive stay on the shelf in FEFO order.
 */
class ReceiveStockTransferShort
{
    public function __construct(
        private readonly ReceiveStockTransfer $receive,
        private readonly CreateInventoryAdjustment $adjust,
    ) {}

    /**
     * @return Collection<int, InventoryAdjustment> the shortage corrections
     */
    public function handle(StockTransfer $transfer, string $received, Reason $lossReason, User $by, ActionReason $why): Collection
    {
        $scaledTotal = $this->scaled((string) $transfer->quantity);
        $scaledReceived = $this->scaled($received);

        if ($scaledReceived < 0 || $scaledReceived >= $scaledTotal) {
            throw new InvalidArgumentException('Received short means less than was sent and not below zero. If all of it arrived, use Receive.');
        }

        if ($lossReason->context !== 'inventory.stock_adjustment' || ! AdjustmentCause::onlyReduces(AdjustmentCause::typeFor($lossReason))) {
            throw new InvalidArgumentException('Choose what happened to the missing stock: damaged, lost or stolen.');
        }

        return DB::transaction(function () use ($transfer, $received, $scaledTotal, $scaledReceived, $lossReason, $by, $why) {
            $arrived = $this->receive->handle($transfer, $by);
            $short = $scaledTotal - $scaledReceived;
            $remaining = $short;
            $adjustments = collect();

            foreach ($arrived->sortByDesc('id') as $movement) {
                /** @var StockMovement $movement */
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $this->scaled((string) $movement->quantity));
                $adjustments->push($this->adjust->handle(
                    type: AdjustmentCause::typeFor($lossReason),
                    direction: AdjustmentDirection::Decrease,
                    product: $transfer->product,
                    warehouse: $transfer->targetWarehouse,
                    quantity: $this->unscaled($take),
                    reason: $lossReason,
                    createdBy: $by,
                    note: "Short on transfer #{$transfer->id}: {$why->display()}",
                    effectiveDate: $movement->occurred_at,
                    source: $transfer,
                    batch: StockBatch::query()->findOrFail($movement->batch_id),
                ));
                $remaining -= $take;
            }

            $transfer->refresh()->update([
                'received_quantity' => $received,
                'shortage_quantity' => $this->unscaled($short),
                'shortage_reason' => $why->display(),
            ]);

            CorrectionLog::event('received_short', $by, $transfer, [
                'received' => $received,
                'short' => $this->unscaled($short),
                'reason' => $why->display(),
                'adjustments' => $adjustments->pluck('id')->all(),
            ]);

            return $adjustments;
        });
    }

    private function scaled(string $quantity): int
    {
        return (int) round((float) $quantity * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
