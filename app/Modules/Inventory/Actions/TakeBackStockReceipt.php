<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\BatchHistory;
use App\Modules\Inventory\Support\SoldStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ Corrections wave 1 (19 Oct 2026): a goods receipt that should not have
 * been (entered twice, the wrong order, the wrong quantity or lot) takes its
 * goods back out of the batch it made, whole, at the movement's own cost.
 *
 * ⚠ Not ReverseStockReceipt, the purchase-return tool: that one sends PART of
 * a delivery back to the supplier through whichever receipts still hold it,
 * in the supplier's unit. This undoes ONE receipt movement exactly: the same
 * stock quantity, the same unit cost, `reverses_movement_id` naming it, and
 * only while the batch still holds every unit of it (the caller's stock check
 * says so in a sentence first; this refuses again under the lock).
 *
 * The `$pass` is the Owner's one-document closed-month exception, when the
 * corrections process hands one down. Null otherwise.
 *
 * ⭐ 24 Oct 2026 (the Tk 10,000 sold-stock rule): `$remainder` takes back only
 * what is LEFT of the receipt after part of it was sold or used. The caller
 * has checked SoldStock::share() first; under the lock this checks again that
 * the batch never ran below that remainder from the receipt onward, so the
 * batch history can never turn negative.
 */
class TakeBackStockReceipt
{
    public function handle(StockMovement $receipt, User $by, string $note, Carbon $on, ?ClosedPeriodPass $pass = null, ?string $remainder = null): StockMovement
    {
        if ($receipt->direction !== MovementDirection::In || $receipt->batch_id === null) {
            throw new LogicException("Movement #{$receipt->id} is not a receipt into a batch.");
        }

        if ($on->isFuture()) {
            throw new InvalidArgumentException('Stock cannot move on a day that has not come yet.');
        }

        AccountingPeriod::assertOpenFor($on, $pass);

        return DB::transaction(function () use ($receipt, $by, $note, $on, $remainder) {
            $batch = StockBatch::query()->whereKey($receipt->batch_id)->lockForUpdate()->firstOrFail();
            $name = $batch->batch_number ?? "#{$batch->id}";

            if ($remainder !== null) {
                $wanted = SoldStock::scaled($remainder);

                if ($wanted <= 0 || $wanted > SoldStock::scaled((string) $batch->current_quantity) || $wanted > SoldStock::scaled((string) $receipt->quantity)
                    || BatchHistory::lowestScaledFrom($receipt) < $wanted) {
                    throw new InvalidArgumentException("Batch {$name} no longer holds what is left of this receipt, so it cannot be taken back.");
                }

                return StockMovement::create([
                    'product_id' => $receipt->product_id,
                    'warehouse_id' => $receipt->warehouse_id,
                    'batch_id' => $batch->id,
                    'direction' => MovementDirection::Out,
                    'reason' => MovementReason::ReceiptCorrection,
                    'quantity' => SoldStock::unscaled($wanted),
                    'unit_cost' => $batch->unit_cost,
                    'reverses_movement_id' => $receipt->id,
                    'performed_by' => $by->id,
                    'note' => $note,
                    'occurred_at' => $on,
                ]);
            }

            $moved = StockMovement::query()
                ->where('batch_id', $batch->id)
                ->whereKeyNot($receipt->id)
                ->where('quantity', '!=', 0)
                ->exists();

            if ($moved || bccomp((string) $batch->current_quantity, (string) $receipt->quantity, 4) !== 0) {
                throw new InvalidArgumentException("Goods from batch {$name} have been used, sold, moved or reserved since, so they cannot be taken back.");
            }

            return StockMovement::create([
                'product_id' => $receipt->product_id,
                'warehouse_id' => $receipt->warehouse_id,
                'batch_id' => $batch->id,
                'direction' => MovementDirection::Out,
                'reason' => MovementReason::ReceiptCorrection,
                'quantity' => $receipt->quantity,
                'unit_cost' => $receipt->unit_cost,
                'reverses_movement_id' => $receipt->id,
                'performed_by' => $by->id,
                'note' => $note,
                'occurred_at' => $on,
            ]);
        });
    }
}
