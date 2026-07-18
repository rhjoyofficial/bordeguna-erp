<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubmitStockRequest
{
    public function handle(
        StockRequestType $type,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        Reason $reason,
        User $requestedBy,
        ?Warehouse $targetWarehouse = null,
        ?AdjustmentDirection $direction = null,
        ?string $note = null,
    ): StockRequest {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        if ($type === StockRequestType::Transfer && $targetWarehouse === null) {
            throw new InvalidArgumentException('targetWarehouse is required for a transfer request.');
        }

        if ($type === StockRequestType::Adjustment && $direction === null) {
            throw new InvalidArgumentException('direction is required for an adjustment request.');
        }

        return DB::transaction(function () use ($type, $product, $warehouse, $quantity, $reason, $requestedBy, $targetWarehouse, $direction, $note) {
            $request = StockRequest::create([
                'type' => $type,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'target_warehouse_id' => $targetWarehouse?->id,
                'direction' => $direction,
                'quantity' => $quantity,
                'reason_id' => $reason->id,
                'note' => $note,
            ]);

            $request->submitForApproval($requestedBy);

            return $request;
        });
    }
}
