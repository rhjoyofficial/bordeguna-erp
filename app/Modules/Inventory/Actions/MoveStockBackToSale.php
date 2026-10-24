<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B7 (Joy, 24 Oct 2026): "anything reusable can be moved back
 * to sellable stock afterwards". From a Not for sale (quarantine) warehouse to
 * a selling one, the same batch at the same cost, with the reason typed by
 * the person (why it is fit to sell again). What is not reusable is written
 * off from the quarantine warehouse with the reason "Damaged returns".
 */
class MoveStockBackToSale
{
    public const REASON = 'Back to sellable stock';

    public function __construct(private readonly MoveBatchToWarehouse $move) {}

    public function handle(StockBatch $batch, Warehouse $target, string $quantity, string $why, User $by, mixed $occurredAt = null): StockTransfer
    {
        $batch->loadMissing('warehouse');

        if (! $batch->warehouse?->not_for_sale) {
            throw ValidationException::withMessages([
                'stock_batch_id' => "Batch {$batch->batch_number} is not in a Not for sale warehouse. Move stock between selling warehouses with Move stock.",
            ]);
        }

        if ($target->not_for_sale) {
            throw ValidationException::withMessages([
                'target_warehouse_id' => "{$target->name} is a Not for sale warehouse too. Choose a warehouse that sells.",
            ]);
        }

        $why = trim($why);

        if (mb_strlen($why) < 5) {
            throw ValidationException::withMessages(['reason_text' => 'Say why it can be sold again (at least 5 characters).']);
        }

        return $this->move->handle(
            batch: $batch,
            target: $target,
            quantity: $quantity,
            reason: MoveBatchToWarehouse::reasonNamed(self::REASON),
            performedBy: $by,
            note: "Back to sellable stock: {$why}",
            occurredAt: $occurredAt,
        );
    }
}
