<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\EffectiveDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts an approved correction. The only place inventory adjustments become
 * stock movements, for every type.
 *
 * ⚠⚠ No `match` on the adjustment type appears here, and a structural test
 * enforces it. Expired stock, a count variance and a manual correction take
 * literally the same path; only what is recorded about them differs.
 */
class ApproveInventoryAdjustment
{
    public function __construct(
        private readonly RecordStockIn $recordStockIn,
        private readonly RecordStockOut $recordStockOut,
        private readonly PostInventoryAdjustmentJournal $posting,
        private readonly RevalueStockBatch $revalueBatch,
    ) {}

    public function handle(InventoryAdjustment $adjustment, User $approvedBy, ?string $note = null): InventoryAdjustment
    {
        if (! $adjustment->approvalRequest->isPending()) {
            throw new RuntimeException("Inventory adjustment #{$adjustment->id} has already been reviewed.");
        }

        // ⚠⚠ Re-checked HERE, not only at creation. A month can close while an
        // adjustment sits in the queue, and posting into a period that has since
        // been closed is exactly what the lock exists to prevent. Checking only
        // on the way in leaves a hole as wide as the queue is slow.
        AccountingPeriod::assertOpenFor($adjustment->effective_date);

        return DB::transaction(function () use ($adjustment, $approvedBy, $note) {
            // ⚠ Bug hunt 16 Oct 2026: the decision first, and stock moves only
            // when it was the LAST stage. approve() returns false when it only
            // advanced a stage; posting regardless would post a correction
            // that is still waiting for its next approver (and again on the
            // next stage). A refusal here (a self-approval with no reason)
            // writes nothing. All of it is one transaction either way.
            if (! $adjustment->approvalRequest->approve($approvedBy, $note)) {
                return $adjustment->fresh(['approvalRequest', 'movements']);
            }

            $note = trim("Adjustment #{$adjustment->id} ({$adjustment->type->label()})".($adjustment->note ? ": {$adjustment->note}" : ''));

            $movements = $this->post($adjustment, $note);

            // ⚠ Every movement, not the first. The flow this replaces recorded
            // `$movements->first()?->id`, so a decrease across three cost layers
            // wrote three movements and remembered one.
            StockMovement::whereIn('id', $movements->pluck('id'))->update(['adjustment_id' => $adjustment->id]);

            // ⚠⚠ The value has left (or arrived), so the ledger says so -
            // automatically, from the business event, never by hand. In the same
            // transaction as the movements themselves: stock that moved without
            // a journal, or a journal without the movements, is a state nobody
            // could reconcile.
            $this->posting->handle($adjustment->fresh(['movements']), $approvedBy);

            return $adjustment->fresh(['approvalRequest', 'movements']);
        });
    }

    /**
     * A movement that changes what stock is worth and not how much there is.
     *
     * ⚠ `quantity = 0` is doing real work: StockLedger derives quantities by
     * summing movements, so a zero adds nothing and slice 4's derivation and all
     * three verification levels keep working untouched.
     *
     * ⚠ The batch's own `unit_cost` follows, so LATER withdrawals leave at the
     * revalued cost while movements already written keep the cost they went out
     * at. Historical cost of goods sold does not move, which is what accounting
     * requires.
     *
     * ⚠ 17 Oct 2026: the movement itself is written by RevalueStockBatch, the
     * one primitive landed cost shares, so the value_delta / unit_cost pair is
     * built in exactly one place. Same rows as before, byte for byte.
     */
    private function revalue(InventoryAdjustment $adjustment, string $note): StockMovement
    {
        return $this->revalueBatch->handle(
            batch: $adjustment->batch,
            direction: $adjustment->direction === AdjustmentDirection::Increase
                ? MovementDirection::In
                : MovementDirection::Out,
            newUnitCost: (string) $adjustment->new_unit_cost,
            // ⚠ STORED, not recomputed. The quantity on hand changes over time,
            // so deriving this later would give a different number for the same
            // event - the lesson from four decimal incidents, applied early.
            valueDelta: (string) $adjustment->estimated_value,
            performedBy: (int) $adjustment->created_by,
            note: $note,
            occurredAt: $adjustment->effective_date,
            reasonId: $adjustment->reason_id,
        );
    }

    /**
     * Takes the adjustment's quantity out of the one batch it names, at that
     * batch's cost.
     *
     * ⚠ Locked and re-read here, at approval. A proposal can sit in the queue
     * while a return or a count changes the batch, and writing off more than it
     * now holds is refused rather than quietly reaching into another batch.
     */
    private function decreaseNamedBatch(InventoryAdjustment $adjustment, int $batchId, string $note): StockMovement
    {
        $batch = StockBatch::whereKey($batchId)->lockForUpdate()->first();

        if ($batch === null
            || (int) $batch->product_id !== (int) $adjustment->product_id
            || (int) $batch->warehouse_id !== (int) $adjustment->warehouse_id) {
            throw new RuntimeException("Adjustment #{$adjustment->id} names batch #{$batchId}, which is not this product in this warehouse.");
        }

        // ⚠ Integer-scaled, as everywhere else that compares quantities (I2).
        $wanted = (int) round((float) $adjustment->quantity * 10000);
        $available = (int) round((float) $batch->current_quantity * 10000);

        if ($available < $wanted) {
            throw InsufficientStockException::forProductInWarehouse(
                (int) $adjustment->product_id,
                (int) $adjustment->warehouse_id,
                (float) $adjustment->quantity,
                (float) $batch->current_quantity,
            );
        }

        return $this->outOfBatch($adjustment, $batch, (string) $adjustment->quantity, $note);
    }

    /**
     * A decrease that corrects the book (a count, a manual correction, an
     * unnamed expiry): expired batches first, oldest expiry first, each at its
     * own cost; whatever is left goes FIFO through RecordStockOut as before.
     *
     * ⚠⚠ 14 Oct 2026 (INV-001): the count is sized against a book that
     * includes expired stock, so the units it finds missing are taken from that
     * same book. Before this the expired batch was skipped (FIFO excludes it),
     * so good stock was written off at the good cost, or the count could not be
     * completed at all. See AdjustmentCause::takesExpiredFirst.
     *
     * @return Collection<int, StockMovement>
     */
    private function decreaseExpiredFirst(InventoryAdjustment $adjustment, string $note): Collection
    {
        $remaining = (int) round((float) $adjustment->quantity * 10000);
        $movements = collect();

        $expired = StockBatch::query()
            ->where('product_id', $adjustment->product_id)
            ->where('warehouse_id', $adjustment->warehouse_id)
            ->expired()
            ->where('current_quantity', '>', 0)
            ->orderBy('stock_batches.expiry_date')
            ->orderBy('stock_batches.id')
            ->lockForUpdate()
            ->get();

        foreach ($expired as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) round((float) $batch->current_quantity * 10000));
            $movements->push($this->outOfBatch($adjustment, $batch, number_format($take / 10000, 4, '.', ''), $note));
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $movements = $movements->merge($this->recordStockOut->handle(
                product: $adjustment->product,
                warehouse: $adjustment->warehouse,
                quantity: number_format($remaining / 10000, 4, '.', ''),
                performedBy: $adjustment->createdBy,
                note: $note,
                reason: MovementReason::Adjustment,
                reasonId: $adjustment->reason_id,
                occurredAt: $adjustment->effective_date,
            ));
        }

        return $movements->values();
    }

    /** One Out movement of `$quantity` from one batch, at that batch's cost. */
    private function outOfBatch(InventoryAdjustment $adjustment, StockBatch $batch, string $quantity, string $note): StockMovement
    {
        if ($batch->received_at !== null) {
            EffectiveDate::assertNotBefore($adjustment->effective_date, $batch->received_at, $batch->id);
        }

        // ⚠ Slice 4: the movement is written and the batch quantity follows
        // from it through StockMovementObserver. The journal values it from
        // this movement, so it is costed at the batch that actually left.
        return StockMovement::create([
            'product_id' => $adjustment->product_id,
            'warehouse_id' => $adjustment->warehouse_id,
            'batch_id' => $batch->id,
            'direction' => MovementDirection::Out,
            'reason' => MovementReason::Adjustment,
            'reason_id' => $adjustment->reason_id,
            'quantity' => $quantity,
            'unit_cost' => $batch->unit_cost,
            'performed_by' => $adjustment->created_by,
            'note' => $note,
            'occurred_at' => $adjustment->effective_date,
        ]);
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function post(InventoryAdjustment $adjustment, string $note): Collection
    {
        // ⚠⚠ A1. Asks whether this record moves MONEY or UNITS - a question
        // about the data it carries, never about its type. That is what keeps
        // slice 3's structural test true while a seventh type exists.
        if ($adjustment->changesValueOnly()) {
            return collect([$this->revalue($adjustment, $note)]);
        }

        // ⚠⚠ A decrease that NAMES its batch takes from that batch, never FIFO.
        // The expiry write-off names the expired batch, and FIFO skips expired
        // stock by design - so routing it through RecordStockOut wrote off GOOD
        // stock instead (or threw when only the expired stock was left), and the
        // expired batch was proposed again the next night.
        $namedBatchId = $adjustment->namedBatchId();

        if ($adjustment->direction === AdjustmentDirection::Decrease && $namedBatchId !== null) {
            return collect([$this->decreaseNamedBatch($adjustment, $namedBatchId, $note)]);
        }

        if ($adjustment->direction === AdjustmentDirection::Decrease && $adjustment->takesExpiredStockFirst()) {
            return $this->decreaseExpiredFirst($adjustment, $note);
        }

        // Direction, not type, decides the mechanism - and there are exactly two
        // directions, which is why this is an if and not a growing match.
        if ($adjustment->direction === AdjustmentDirection::Increase) {
            return collect([$this->recordStockIn->handle(
                product: $adjustment->product,
                warehouse: $adjustment->warehouse,
                quantity: (string) $adjustment->quantity,
                unitCost: (string) $adjustment->unit_cost,
                performedBy: $adjustment->createdBy,
                note: $note,
                reason: MovementReason::Adjustment,
                reasonId: $adjustment->reason_id,
                occurredAt: $adjustment->effective_date,
            )]);
        }

        return $this->recordStockOut->handle(
            product: $adjustment->product,
            warehouse: $adjustment->warehouse,
            quantity: (string) $adjustment->quantity,
            performedBy: $adjustment->createdBy,
            note: $note,
            reason: MovementReason::Adjustment,
            reasonId: $adjustment->reason_id,
            occurredAt: $adjustment->effective_date,
        );
    }
}
