<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventoryPeriodLock;
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
        InventoryPeriodLock::assertOpenFor($adjustment->effective_date);

        return DB::transaction(function () use ($adjustment, $approvedBy) {
            $note = trim("Adjustment #{$adjustment->id} ({$adjustment->type->label()})".($adjustment->note ? ": {$adjustment->note}" : ''));

            $movements = $this->post($adjustment, $note);

            // ⚠ Every movement, not the first. The flow this replaces recorded
            // `$movements->first()?->id`, so a decrease across three cost layers
            // wrote three movements and remembered one.
            StockMovement::whereIn('id', $movements->pluck('id'))->update(['adjustment_id' => $adjustment->id]);

            $adjustment->approvalRequest->approve($approvedBy);

            return $adjustment->fresh(['approvalRequest', 'movements']);
        });
    }

    /**
     * @return Collection<int, StockMovement>
     */
    private function post(InventoryAdjustment $adjustment, string $note): Collection
    {
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
