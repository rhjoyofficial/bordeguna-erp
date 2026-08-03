<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
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
    ) {}

    public function handle(InventoryAdjustment $adjustment, User $approvedBy): InventoryAdjustment
    {
        if (! $adjustment->approvalRequest->isPending()) {
            throw new RuntimeException("Inventory adjustment #{$adjustment->id} has already been reviewed.");
        }

        // ⚠⚠ Re-checked HERE, not only at creation. A month can close while an
        // adjustment sits in the queue, and posting into a period that has since
        // been closed is exactly what the lock exists to prevent. Checking only
        // on the way in leaves a hole as wide as the queue is slow.
        AccountingPeriod::assertOpenFor($adjustment->effective_date);

        return DB::transaction(function () use ($adjustment, $approvedBy) {
            $note = trim("Adjustment #{$adjustment->id} ({$adjustment->type->label()})".($adjustment->note ? ": {$adjustment->note}" : ''));

            $movements = $this->post($adjustment, $note);

            // ⚠ Every movement, not the first. The flow this replaces recorded
            // `$movements->first()?->id`, so a decrease across three cost layers
            // wrote three movements and remembered one.
            StockMovement::whereIn('id', $movements->pluck('id'))->update(['adjustment_id' => $adjustment->id]);

            $adjustment->approvalRequest->approve($approvedBy);

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
     */
    private function revalue(InventoryAdjustment $adjustment, string $note): StockMovement
    {
        $batch = $adjustment->batch;

        $movement = StockMovement::create([
            'product_id' => $adjustment->product_id,
            'warehouse_id' => $adjustment->warehouse_id,
            'batch_id' => $batch->id,
            // `in` raises value, `out` lowers it - the same meaning direction has
            // always had, so nothing that branches on it changes.
            'direction' => $adjustment->direction === AdjustmentDirection::Increase
                ? MovementDirection::In
                : MovementDirection::Out,
            'reason' => MovementReason::Adjustment,
            'reason_id' => $adjustment->reason_id,
            'quantity' => '0',
            'unit_cost' => $adjustment->new_unit_cost,
            // ⚠ STORED, not recomputed. The quantity on hand changes over time,
            // so deriving this later would give a different number for the same
            // event - the lesson from four decimal incidents, applied early.
            'value_delta' => $adjustment->estimated_value,
            'performed_by' => $adjustment->created_by,
            'note' => $note,
            'occurred_at' => $adjustment->effective_date,
        ]);

        StockBatch::withoutGlobalScopes()->whereKey($batch->id)->update([
            'unit_cost' => $adjustment->new_unit_cost,
        ]);

        return $movement;
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
