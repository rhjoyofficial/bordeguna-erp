<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single entry point for every inventory quantity or value correction.
 *
 * ⚠⚠ There is no `match` on `$type` in this class or in
 * `ApproveInventoryAdjustment`, and a structural test enforces it. The type says
 * why; direction, quantity, unit cost and effective date say what happens. That
 * is what makes Joy's requirement true - approval, audit and movement generation
 * identical regardless of type - and what lets a seventh type arrive without
 * touching the engine.
 */
class CreateInventoryAdjustment
{
    public function __construct(
        private readonly ApproveInventoryAdjustment $approve,
    ) {}

    public function handle(
        InventoryAdjustmentType $type,
        AdjustmentDirection $direction,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        Reason $reason,
        User $createdBy,
        ?string $unitCost = null,
        ?string $note = null,
        mixed $effectiveDate = null,
        ?Model $source = null,
        ?StockBatch $batch = null,
        ?string $newUnitCost = null,
    ): InventoryAdjustment {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        // ⚠ A1. An adjustment moves EITHER units or money. A zero quantity is
        // only meaningful when it carries a new cost; otherwise it is a request
        // to do nothing, and the original guard still refuses it.
        $revaluing = $newUnitCost !== null && (float) $quantity === 0.0;

        if (! $revaluing && (float) $quantity <= 0) {
            throw new InvalidArgumentException('An adjustment quantity must be greater than zero.');
        }

        // ⚠ Value lives per COST LAYER. Revaluing "the product" would mean
        // inventing a spread across layers, which is a valuation rule nobody has
        // asked for and exactly the kind this project refuses to guess.
        if ($revaluing && $batch === null) {
            throw new InvalidArgumentException('A revaluation must name the stock batch whose value is changing.');
        }

        // ⚠ An increase creates inventory value out of nothing, so the cost is
        // never invented. C-SC-1: the UI suggests the latest purchase cost, a
        // human accepts or overrides it, and it arrives here already decided.
        if (! $revaluing && $direction === AdjustmentDirection::Increase && $unitCost === null) {
            throw new InvalidArgumentException('unitCost is required when an adjustment increases stock.');
        }

        // Future check and period lock, inherited from slices 1 and 2 rather
        // than restated here.
        $appliesAt = EffectiveDate::resolve($effectiveDate, 'effective_date');

        return DB::transaction(function () use ($type, $direction, $product, $warehouse, $quantity, $reason, $createdBy, $unitCost, $note, $appliesAt, $source, $batch, $newUnitCost, $revaluing) {
            $adjustment = InventoryAdjustment::create([
                'type' => $type,
                'direction' => $direction,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'stock_batch_id' => $batch?->id,
                'quantity' => $quantity,
                'reason_id' => $reason->id,
                'unit_cost' => $unitCost,
                'new_unit_cost' => $newUnitCost,
                'effective_date' => $appliesAt,
                'estimated_value' => $revaluing
                    ? $this->estimateRevaluation($batch, $newUnitCost)
                    : $this->estimateValue($direction, $product, $warehouse, $quantity, $unitCost, $batch),
                'note' => $note,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'created_by' => $createdBy->id,
            ]);

            $adjustment->submitForApproval($createdBy);

            // Below the configured threshold and held by somebody who may adjust
            // stock, it approves itself - the same shape as a Manager's purchase
            // order. ⚠ It still creates the record, the approval and the audit
            // trail. "Auto-approved" means nobody else had to look, not that
            // nothing was written down.
            if ($this->maySelfApprove($createdBy, $adjustment)) {
                $this->approve->handle($adjustment, $createdBy);
            }

            return $adjustment->fresh(['approvalRequest', 'movements']);
        });
    }

    /**
     * What a revaluation is worth: the units on hand times the change in cost.
     *
     * ⚠ Computed once, here, and stored. The quantity on hand changes over time,
     * so recomputing this later would produce a different number for the same
     * event.
     */
    private function estimateRevaluation(StockBatch $batch, string $newUnitCost): string
    {
        $delta = ((float) $newUnitCost - (float) $batch->unit_cost) * (float) $batch->current_quantity;

        return number_format(abs($delta), 4, '.', '');
    }

    private function maySelfApprove(User $actor, InventoryAdjustment $adjustment): bool
    {
        return $actor->can('inventory.stock.adjust')
            && InventorySetting::forCurrentBusiness()->allowsSelfApprovalOf($adjustment->estimated_value);
    }

    /**
     * What this correction is worth, for routing only.
     *
     * ⚠ An increase is exact: it carries its own cost. A DECREASE is an
     * estimate, because which cost layers FIFO consumes is only settled at
     * approval. It decides who approves, which is a routing decision, and
     * routing on a good estimate is correct - the posted value is recorded
     * exactly on the movements themselves.
     */
    private function estimateValue(
        AdjustmentDirection $direction,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        ?string $unitCost,
        ?StockBatch $batch = null,
    ): string {
        if ($direction === AdjustmentDirection::Increase) {
            return number_format((float) $quantity * (float) $unitCost, 4, '.', '');
        }

        // ⚠ A decrease that names its batch leaves at that batch's cost, so the
        // estimate is exact. Walking FIFO would value an expired batch at the
        // good stock FIFO would have taken instead.
        if ($batch !== null) {
            return number_format((float) $quantity * (float) $batch->unit_cost, 4, '.', '');
        }

        $remaining = (float) $quantity;
        $value = 0.0;

        // Walks the same order consumption will, so the estimate is as close as
        // it can be without taking locks on a record nobody has approved yet.
        foreach (StockBatch::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->consumable()->get() as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (float) $batch->current_quantity);
            $value += $take * (float) $batch->unit_cost;
            $remaining -= $take;
        }

        return number_format($value, 4, '.', '');
    }
}
