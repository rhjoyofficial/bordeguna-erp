<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Actions\Concerns\ConsumesFifoStock;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Dispatches stock out of the source warehouse and puts it "in transit" —
 * it does not yet exist as receivable stock at the destination. A source
 * batch's cost/expiry is preserved on the eventual destination batch, but
 * that batch isn't created until ReceiveStockTransfer confirms arrival.
 */
class DispatchStockTransfer
{
    use ConsumesFifoStock;

    public function handle(
        Product $product,
        Warehouse $source,
        Warehouse $target,
        string $quantity,
        Reason $reason,
        User $performedBy,
        ?string $note = null,
        mixed $occurredAt = null,
    ): StockTransfer {
        // ⭐ Initial plan B7 (Joy, 24 Oct 2026): stock leaves a Not for sale
        // warehouse only by Move back to stock (MoveStockBackToSale: a named
        // batch, a reason), a write-off or a purchase return. A plain
        // transfer would carry damaged goods to a selling shelf unseen.
        $source->assertTakesStockFor('transfer');

        if ($source->id === $target->id) {
            throw new InvalidArgumentException('Cannot transfer a product to the same warehouse.');
        }

        if (! $product->type->isStocked()) {
            throw new DomainException("Product #{$product->id} is a service and cannot hold stock.");
        }

        $movedAt = EffectiveDate::resolve($occurredAt);

        return DB::transaction(function () use ($product, $source, $target, $quantity, $reason, $performedBy, $note, $movedAt) {
            $transferGroupId = (string) Str::uuid();
            $consumed = $this->consumeFifo($product, $source, $quantity, $movedAt);

            foreach ($consumed as $portion) {
                StockMovement::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $source->id,
                    'batch_id' => $portion['batch']->id,
                    'direction' => MovementDirection::Out,
                    'reason' => MovementReason::Transfer,
                    'reason_id' => $reason->id,
                    'quantity' => $portion['quantity'],
                    'unit_cost' => $portion['batch']->unit_cost,
                    'occurred_at' => $movedAt,
                    'transfer_group_id' => $transferGroupId,
                    'performed_by' => $performedBy->id,
                    'note' => $note,
                ]);
            }

            return StockTransfer::create([
                'transfer_group_id' => $transferGroupId,
                'product_id' => $product->id,
                'source_warehouse_id' => $source->id,
                'target_warehouse_id' => $target->id,
                'quantity' => $quantity,
                'reason_id' => $reason->id,
                'note' => $note,
                'status' => TransferStatus::InTransit,
                'dispatched_by' => $performedBy->id,
                'dispatched_at' => now(),
            ]);
        });
    }
}
