<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use InvalidArgumentException;

/**
 * Direct adjustment — for users holding inventory.stock.adjust. Staff without
 * that permission go through SubmitStockRequest + ApproveStockRequest instead,
 * which calls this same action once approved.
 */
class ApplyStockAdjustment
{
    public function __construct(
        private readonly RecordStockIn $recordStockIn,
        private readonly RecordStockOut $recordStockOut,
    ) {}

    public function handle(
        Product $product,
        Warehouse $warehouse,
        AdjustmentDirection $direction,
        string $quantity,
        User $performedBy,
        string $note,
        ?string $unitCost = null,
    ): mixed {
        if ($direction === AdjustmentDirection::Increase) {
            if ($unitCost === null) {
                throw new InvalidArgumentException('unitCost is required when increasing stock via adjustment.');
            }

            return $this->recordStockIn->handle(
                product: $product,
                warehouse: $warehouse,
                quantity: $quantity,
                unitCost: $unitCost,
                performedBy: $performedBy,
                note: $note,
                reason: MovementReason::Adjustment,
            );
        }

        return $this->recordStockOut->handle(
            product: $product,
            warehouse: $warehouse,
            quantity: $quantity,
            performedBy: $performedBy,
            note: $note,
            reason: MovementReason::Adjustment,
        );
    }
}
