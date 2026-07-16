<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\StockRequestStatus;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\Warehouse;
use DomainException;
use InvalidArgumentException;

class SubmitStockRequest
{
    public function handle(
        StockRequestType $type,
        Product $product,
        Warehouse $warehouse,
        string $quantity,
        string $reasonNote,
        User $requestedBy,
        ?Warehouse $targetWarehouse = null,
        ?AdjustmentDirection $direction = null,
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

        return StockRequest::create([
            'type' => $type,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'target_warehouse_id' => $targetWarehouse?->id,
            'direction' => $direction,
            'quantity' => $quantity,
            'reason_note' => $reasonNote,
            'status' => StockRequestStatus::Pending,
            'requested_by' => $requestedBy->id,
        ]);
    }
}
