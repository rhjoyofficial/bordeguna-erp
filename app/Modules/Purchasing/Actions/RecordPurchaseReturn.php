<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Returns previously-received goods against a PO — the counterpart to
 * RecordGoodsReceipt. For stocked products this calls Inventory's own
 * RecordStockOut — Purchasing never touches stock_batches/stock_movements
 * directly, per the cross-module boundary rule (see ERP-PLAN risk #4).
 * Service-type lines have nothing to reverse.
 */
class RecordPurchaseReturn
{
    public function __construct(
        private readonly RecordStockOut $recordStockOut,
    ) {}

    /**
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string}>  $lineReturns
     */
    public function handle(PurchaseOrder $order, array $lineReturns, User $returnedBy, ?string $note = null): PurchaseReturn
    {
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            throw new RuntimeException("Purchase order #{$order->id} is cancelled; nothing to return.");
        }

        $lineReturns = array_filter($lineReturns, fn (array $entry) => (float) $entry['quantity'] > 0);

        if ($lineReturns === []) {
            throw new InvalidArgumentException('At least one line must have a return quantity greater than zero.');
        }

        return DB::transaction(function () use ($order, $lineReturns, $returnedBy, $note) {
            $return = PurchaseReturn::create([
                'purchase_order_id' => $order->id,
                'returned_by' => $returnedBy->id,
                'returned_at' => now(),
                'note' => $note,
            ]);

            foreach ($lineReturns as $entry) {
                $line = $entry['line'];
                $quantity = $entry['quantity'];
                $returnable = (float) $line->quantityReturnable();

                if ((float) $quantity > $returnable) {
                    throw new InvalidArgumentException("Cannot return {$quantity} of line #{$line->id}; only {$returnable} is returnable.");
                }

                if ($line->product->type->isStocked()) {
                    $this->recordStockOut->handle(
                        product: $line->product,
                        warehouse: $order->warehouse,
                        quantity: $quantity,
                        performedBy: $returnedBy,
                        note: "Returned against {$order->po_number}".($note ? ": {$note}" : ''),
                        reason: MovementReason::PurchaseReturn,
                    );
                }

                $return->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'quantity_returned' => $quantity,
                ]);

                $line->update(['quantity_returned' => (float) $line->quantity_returned + (float) $quantity]);
            }

            return $return->fresh('lines');
        });
    }
}
