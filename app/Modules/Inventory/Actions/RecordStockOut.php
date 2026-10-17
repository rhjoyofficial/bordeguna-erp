<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\ConsumesFifoStock;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Database\Eloquent\Model;
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
        mixed $occurredAt = null,
        // ⭐ Phase 2 (17 Oct 2026): the approved document whose reserved
        // batch slices this issue takes first (see ConsumesFifoStock).
        ?Model $reservedFor = null,
    ): Collection {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        $movedAt = EffectiveDate::resolve($occurredAt);

        return DB::transaction(function () use ($product, $warehouse, $quantity, $performedBy, $note, $reason, $reasonId, $movedAt, $reservedFor) {
            $consumed = $this->consumeFifo($product, $warehouse, $quantity, $movedAt, $reservedFor);

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
                'occurred_at' => $movedAt,
            ]));
        });
    }
}
