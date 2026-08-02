<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
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
        ?string $unitCost = null,
        ?string $note = null,
    ): StockRequest {
        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        // ⚠ A stock request is only ever a transfer now. Corrections go through
        // InventoryAdjustment, which carries the type, effective date, approval
        // status and movement links this table never had.
        if ($targetWarehouse === null) {
            throw new InvalidArgumentException('targetWarehouse is required for a transfer request.');
        }

        $request = DB::transaction(function () use ($type, $product, $warehouse, $quantity, $reason, $requestedBy, $targetWarehouse, $direction, $unitCost, $note) {
            $request = StockRequest::create([
                'type' => $type,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'target_warehouse_id' => $targetWarehouse?->id,
                'direction' => $direction,
                'quantity' => $quantity,
                'reason_id' => $reason->id,
                'unit_cost' => $unitCost,
                'note' => $note,
            ]);

            $request->submitForApproval($requestedBy);

            return $request;
        });

        // A stock request always lands pending — tell whoever can approve here.
        app(NotifyApprovers::class)->handle(
            businessId: $request->business_id,
            permission: 'inventory.stock.approve',
            summary: "Stock {$type->value} of {$quantity} × {$product->name} at {$warehouse->name}",
            excludeUserId: $requestedBy->id,
        );

        return $request;
    }
}
