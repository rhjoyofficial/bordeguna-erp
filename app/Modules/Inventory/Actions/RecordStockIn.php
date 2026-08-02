<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordStockIn
{
    public function handle(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $unitCost,
        User $performedBy,
        ?string $batchNumber = null,
        ?string $expiryDate = null,
        ?string $note = null,
        MovementReason $reason = MovementReason::Receipt,
        ?int $reasonId = null,
        mixed $occurredAt = null,
    ): StockMovement {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        // When the goods actually arrived, not when somebody typed them in.
        $receivedAt = EffectiveDate::resolve($occurredAt, 'received_at');

        return DB::transaction(function () use ($product, $warehouse, $quantity, $unitCost, $performedBy, $batchNumber, $expiryDate, $note, $reason, $reasonId, $receivedAt) {
            $batch = StockBatch::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'unit_cost' => $unitCost,
                'initial_quantity' => $quantity,
                'current_quantity' => $quantity,
                'status' => StockBatchStatus::Active,
                'received_at' => $receivedAt,
            ]);

            return StockMovement::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'batch_id' => $batch->id,
                'direction' => MovementDirection::In,
                'reason' => $reason,
                'reason_id' => $reasonId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'performed_by' => $performedBy->id,
                'note' => $note,
                'occurred_at' => $receivedAt,
            ]);
        });
    }
}
