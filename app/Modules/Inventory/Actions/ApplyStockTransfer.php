<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\ConsumesFifoStock;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Direct transfer — for users holding inventory.stock.adjust. Consumes FIFO
 * at the source warehouse and creates a mirrored batch (same cost/expiry) at
 * the destination per source batch touched, pairing the two legs of each
 * transfer via transfer_group_id so they can be reconciled later.
 */
class ApplyStockTransfer
{
    use ConsumesFifoStock;

    /**
     * @return Collection<int, StockMovement> both legs, out then in, per batch touched
     */
    public function handle(
        Product $product,
        Warehouse $source,
        Warehouse $target,
        string $quantity,
        User $performedBy,
        ?string $note = null,
    ): Collection {
        if ($source->id === $target->id) {
            throw new InvalidArgumentException('Cannot transfer a product to the same warehouse.');
        }

        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        return DB::transaction(function () use ($product, $source, $target, $quantity, $performedBy, $note) {
            $transferGroupId = (string) Str::uuid();
            $consumed = $this->consumeFifo($product, $source, $quantity);
            $movements = collect();

            foreach ($consumed as $portion) {
                /** @var StockBatch $sourceBatch */
                $sourceBatch = $portion['batch'];
                $movedQuantity = $portion['quantity'];

                $movements->push(StockMovement::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $source->id,
                    'batch_id' => $sourceBatch->id,
                    'direction' => MovementDirection::Out,
                    'reason' => MovementReason::Transfer,
                    'quantity' => $movedQuantity,
                    'unit_cost' => $sourceBatch->unit_cost,
                    'transfer_group_id' => $transferGroupId,
                    'performed_by' => $performedBy->id,
                    'note' => $note,
                ]));

                $targetBatch = StockBatch::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $target->id,
                    'batch_number' => $sourceBatch->batch_number,
                    'expiry_date' => $sourceBatch->expiry_date,
                    'unit_cost' => $sourceBatch->unit_cost,
                    'initial_quantity' => $movedQuantity,
                    'current_quantity' => $movedQuantity,
                    'status' => StockBatchStatus::Active,
                ]);

                $movements->push(StockMovement::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $target->id,
                    'batch_id' => $targetBatch->id,
                    'direction' => MovementDirection::In,
                    'reason' => MovementReason::Transfer,
                    'quantity' => $movedQuantity,
                    'unit_cost' => $sourceBatch->unit_cost,
                    'transfer_group_id' => $transferGroupId,
                    'performed_by' => $performedBy->id,
                    'note' => $note,
                ]));
            }

            return $movements;
        });
    }
}
