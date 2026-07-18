<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\ConsumesFifoStock;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RecordStockOut
{
    use ConsumesFifoStock;

    /**
     * @return Collection<int, StockMovement> one movement per batch touched
     */
    public function handle(
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        User $performedBy,
        ?string $note = null,
        MovementReason $reason = MovementReason::Usage,
        ?int $reasonId = null,
    ): Collection {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        return DB::transaction(function () use ($product, $warehouse, $quantity, $performedBy, $note, $reason, $reasonId) {
            $consumed = $this->consumeFifo($product, $warehouse, $quantity);

            return $consumed->map(fn ($portion) => StockMovement::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'batch_id' => $portion['batch']->id,
                'direction' => MovementDirection::Out,
                'reason' => $reason,
                'reason_id' => $reasonId,
                'quantity' => $portion['quantity'],
                'unit_cost' => $portion['batch']->unit_cost,
                'performed_by' => $performedBy->id,
                'note' => $note,
            ]));
        });
    }
}
