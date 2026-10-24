<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\ReverseStockReceipt;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\PurchaseReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Returns previously-received goods against a PO — the counterpart to
 * RecordGoodsReceipt. Purchasing never touches stock_batches/stock_movements
 * directly, per the cross-module boundary rule (see ERP-PLAN risk #4).
 * Service-type lines have nothing to reverse.
 *
 * ⚠ This used to call `RecordStockOut`, which consumes FIFO, so a return
 * relieved the OLDEST batch in the warehouse instead of the one the supplier
 * actually delivered (I1). It now calls `ReverseStockReceipt` against this
 * line's own receipt movements. A return is the reversal of a specific receipt,
 * not a withdrawal, and the two questions have different right answers.
 */
class RecordPurchaseReturn
{
    public function __construct(
        private readonly ReverseStockReceipt $reverseStockReceipt,
        private readonly PostPurchaseJournals $posting,
    ) {}

    /**
     * The stock movements this line's receipts created, oldest first.
     *
     * ⚠ Ordered by id, not by `created_at`. Two receipts recorded in the same
     * second are perfectly ordinary and a second-precision timestamp does not
     * separate them - the same total-ordering trap as I5 and as W2 before it.
     * Here it would decide which cost layer a return relieves.
     *
     * @return Collection<int, StockMovement>
     */
    private function receiptMovementsFor(PurchaseOrderLine $line, ?array $receiptLineIds = null): Collection
    {
        return GoodsReceiptLine::where('purchase_order_line_id', $line->id)
            ->whereNotNull('stock_movement_id')
            // ⭐ Initial plan B9 (R66, R67): started from one goods receipt or
            // one bill, only the batches THAT delivery made are touched.
            ->when($receiptLineIds !== null, fn ($query) => $query->whereIn('id', $receiptLineIds))
            ->with('stockMovement')
            ->orderBy('id')
            ->get()
            ->pluck('stockMovement')
            ->filter()
            ->values();
    }

    /**
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string}>  $lineReturns
     * @param  list<int>|null  $receiptLineIds  ⭐ B9: only these goods receipt lines' batches
     * @param  mixed  $returnedOn  ⭐ B9: the day the goods went back (null = now)
     */
    public function handle(PurchaseOrder $order, array $lineReturns, User $returnedBy, ?string $note = null, ?array $receiptLineIds = null, mixed $returnedOn = null): PurchaseReturn
    {
        // A day only (the date box): today is now, an earlier day is its end,
        // so the movement never sorts before a delivery made that same day.
        $returnedAt = match (true) {
            $returnedOn === null => now(),
            Carbon::parse($returnedOn)->isSameDay(Carbon::today()) => now(),
            default => Carbon::parse($returnedOn)->setTime(23, 59, 59),
        };

        if ($order->status === PurchaseOrderStatus::Cancelled) {
            throw new RuntimeException("Purchase order #{$order->id} is cancelled; nothing to return.");
        }

        $lineReturns = array_filter($lineReturns, fn (array $entry) => (float) $entry['quantity'] > 0);

        if ($lineReturns === []) {
            throw new InvalidArgumentException('At least one line must have a return quantity greater than zero.');
        }

        return DB::transaction(function () use ($order, $lineReturns, $returnedBy, $note, $receiptLineIds, $returnedAt) {
            $return = PurchaseReturn::create([
                'purchase_order_id' => $order->id,
                'returned_by' => $returnedBy->id,
                'returned_at' => $returnedAt,
                'note' => $note,
            ]);

            foreach ($lineReturns as $entry) {
                // I3, same shape as RecordGoodsReceipt: re-read inside the
                // transaction so two stale copies cannot each add to the value
                // the other started from. See that class for why the re-read
                // rather than the lock is what makes it correct.
                $line = PurchaseOrderLine::whereKey($entry['line']->id)->lockForUpdate()->firstOrFail();
                $quantity = $entry['quantity'];
                $returnable = (float) $line->quantityReturnable();

                if ((float) $quantity > $returnable) {
                    throw new InvalidArgumentException("Cannot return {$quantity} of line #{$line->id}; only {$returnable} is returnable.");
                }

                $reversals = collect();

                if ($line->product->type->isStocked()) {
                    // I1. Reverse THIS line's own receipts, oldest first, rather
                    // than withdrawing FIFO from the warehouse. The goods going
                    // back are the goods this supplier delivered, and the credit
                    // note is at their price. Purchasing still never touches
                    // stock tables: it knows which movements its receipts
                    // created, and Inventory decides how to undo them.
                    $reversals = $this->reverseStockReceipt->handle(
                        receipts: $this->receiptMovementsFor($line, $receiptLineIds),
                        quantity: $quantity,
                        performedBy: $returnedBy,
                        note: "Returned against {$order->po_number}".($note ? ": {$note}" : ''),
                        occurredAt: $returnedAt,
                    );
                    // ⚠ C-UOM-6. `$quantity` is in the unit the supplier
                    // invoiced and will credit - kilograms for mustard oil -
                    // because the whole purchase side stays in that unit.
                    // Inventory decides per batch whether that needs converting,
                    // from the factor the batch froze rather than from the
                    // product's current state.
                }

                // A2. ⚠ Receipts have always kept this link and returns never
                // did, so the movements sending goods BACK to a supplier could
                // not reach the return - and a credit-note journal could not
                // tell which supplier to credit except by inferring through
                // `reverses_movement_id`. A posting that depends on a two-hop
                // inference credits the wrong supplier the first time somebody
                // returns goods received on two different orders.
                //
                // ⚠⚠ ONE ROW PER BATCH TOUCHED, NOT ONE PER LINE. A return large
                // enough to span two deliveries empties two cost layers at two
                // different unit costs, and `stock_movement_id` holds ONE
                // movement. Writing a single row kept the first batch's movement
                // and dropped the rest, so `PostPurchaseJournals::forReturn`
                // credited Stock on Hand with the first batch's value alone while
                // both batches had physically emptied. The stock sub-ledger and
                // the general ledger then disagreed permanently, with nothing
                // comparing them and every journal balancing.
                //
                // ⚠ The quantities are the SHARE each batch took, in the unit the
                // supplier credits, so they still sum to the line's return
                // quantity - which is what `quantity_returned` on the order line
                // below depends on.
                if ($reversals->isEmpty()) {
                    $return->lines()->create([
                        'purchase_order_line_id' => $line->id,
                        'quantity_returned' => $quantity,
                        'stock_movement_id' => null,
                    ]);
                } else {
                    foreach ($reversals as $reversal) {
                        $return->lines()->create([
                            'purchase_order_line_id' => $line->id,
                            'quantity_returned' => $reversal->purchaseQuantity,
                            'stock_movement_id' => $reversal->movement->id,
                        ]);
                    }
                }

                $line->update(['quantity_returned' => (float) $line->quantity_returned + (float) $quantity]);
            }

            // ⚠⚠ Value has left stock and the supplier owes it back, so the
            // ledger says so - in the same transaction as the movements, like
            // every other integration in Phase 3.
            $this->posting->forReturn($return->fresh('lines'), $returnedBy);

            return $return->fresh('lines');
        });
    }
}
