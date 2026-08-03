<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Records one receiving event (a shipment may arrive in several) against an
 * approved PO. For stocked products this calls Inventory's own RecordStockIn
 * — Purchasing never touches stock_batches/stock_movements directly, per the
 * cross-module boundary rule (see ERP-PLAN risk #4). Service-type lines are
 * marked received with no stock effect.
 */
class RecordGoodsReceipt
{
    public function __construct(
        private readonly RecordStockIn $recordStockIn,
        private readonly PostPurchaseJournals $posting,
    ) {}

    /**
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string}>  $lineReceipts
     */
    public function handle(PurchaseOrder $order, array $lineReceipts, User $receivedBy, ?string $note = null): GoodsReceipt
    {
        if (! $order->isReceivable()) {
            throw new RuntimeException("Purchase order #{$order->id} is not open for receiving.");
        }

        $lineReceipts = array_filter($lineReceipts, fn (array $entry) => (float) $entry['quantity'] > 0);

        if ($lineReceipts === []) {
            throw new InvalidArgumentException('At least one line must have a received quantity greater than zero.');
        }

        return DB::transaction(function () use ($order, $lineReceipts, $receivedBy, $note) {
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'received_by' => $receivedBy->id,
                'received_at' => now(),
                'note' => $note,
            ]);

            foreach ($lineReceipts as $entry) {
                // ⚠⚠ I3. Re-read the line INSIDE the transaction and trust that,
                // never the caller's copy. The old code checked and incremented
                // using the instance it was handed, so two requests holding
                // copies loaded before either wrote would both pass the
                // remaining check and the second write would overwrite the
                // first: stock created twice, the order recording it once, and
                // nothing anywhere comparing the two.
                //
                // ⚠ The realistic trigger is one clerk double-clicking Receive,
                // not two clerks.
                //
                // ⚠ The re-read is what makes this correct, not the lock.
                // `lockForUpdate()` serialises concurrent transactions on MySQL
                // but is a NO-OP on SQLite, so a fix that only locked would be
                // green in the suite and broken in production. Both are here:
                // the lock for real concurrency, the re-read for correctness.
                $line = PurchaseOrderLine::whereKey($entry['line']->id)->lockForUpdate()->firstOrFail();
                $quantity = $entry['quantity'];
                $remaining = (float) $line->quantityRemaining();

                if ((float) $quantity > $remaining) {
                    throw new InvalidArgumentException("Cannot receive {$quantity} of line #{$line->id}; only {$remaining} remains on the order.");
                }

                $movement = null;

                if ($line->product->type->isStocked()) {
                    $movement = $this->recordStockIn->handle(
                        product: $line->product,
                        warehouse: $order->warehouse,
                        quantity: $quantity,
                        unitCost: (string) $line->unit_cost,
                        performedBy: $receivedBy,
                        note: "Received against {$order->po_number}".($note ? ": {$note}" : ''),
                    );
                }

                $receipt->lines()->create([
                    'purchase_order_line_id' => $line->id,
                    'quantity_received' => $quantity,
                    'stock_movement_id' => $movement?->id,
                ]);

                $line->update(['quantity_received' => (float) $line->quantity_received + (float) $quantity]);
            }

            $this->recomputeStatus($order);

            // ⚠⚠ Stock has arrived and the business owes for it, so the ledger
            // says so - automatically, in the same transaction. Dr Stock on
            // Hand, Cr Goods Received Not Invoiced: between the lorry and the
            // invoice, the liability has to exist or the balance sheet is short
            // by the value of everything in transit.
            $this->posting->forReceipt($receipt->fresh('lines'), $receivedBy);

            return $receipt->fresh('lines');
        });
    }

    private function recomputeStatus(PurchaseOrder $order): void
    {
        $lines = $order->lines()->get(['quantity_ordered', 'quantity_received']);

        $allReceived = $lines->every(fn (PurchaseOrderLine $line) => (float) $line->quantity_received >= (float) $line->quantity_ordered);
        $anyReceived = $lines->contains(fn (PurchaseOrderLine $line) => (float) $line->quantity_received > 0);

        $order->update([
            'status' => match (true) {
                $allReceived => PurchaseOrderStatus::Received,
                $anyReceived => PurchaseOrderStatus::PartiallyReceived,
                default => PurchaseOrderStatus::Open,
            },
        ]);
    }
}
