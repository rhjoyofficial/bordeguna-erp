<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\ResolveReceiptConversion;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\ReceivedBatchDates;
use Illuminate\Support\Carbon;
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
        private readonly ResolveReceiptConversion $conversion,
    ) {}

    /**
     * @param  array<int, array{line: PurchaseOrderLine, quantity: string, batch_number?: ?string, manufactured_date?: ?string, expiry_date?: ?string}>  $lineReceipts
     * @param  string|null  $supplierChallanNumber  the number on the supplier's own delivery note
     */
    public function handle(
        PurchaseOrder $order,
        array $lineReceipts,
        User $receivedBy,
        ?string $note = null,
        ?string $supplierChallanNumber = null,
    ): GoodsReceipt {
        if (! $order->isReceivable()) {
            throw new RuntimeException("Purchase order #{$order->id} is not open for receiving.");
        }

        $lineReceipts = array_filter($lineReceipts, fn (array $entry) => (float) $entry['quantity'] > 0);

        if ($lineReceipts === []) {
            throw new InvalidArgumentException('At least one line must have a received quantity greater than zero.');
        }

        return DB::transaction(function () use ($order, $lineReceipts, $receivedBy, $note, $supplierChallanNumber) {
            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'received_by' => $receivedBy->id,
                'received_at' => now(),
                'supplier_challan_number' => $supplierChallanNumber,
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
                    // ⚠ Named by PRODUCT, not by line id. "line #7" meant nothing to
                    // the storekeeper reading it; the product is what he typed against.
                    throw new InvalidArgumentException("Cannot receive {$quantity} of {$line->product->name} ({$line->product->sku}); only {$remaining} remains on the order.");
                }

                $movement = null;

                if ($line->product->type->isStocked()) {
                    // ⚠⚠ Checked HERE as well as on the form, because this is the
                    // chokepoint every receipt passes and the form is only one way
                    // in. Receiving goods that are already out of date would put
                    // them straight into the "expired" list and the nightly
                    // write-off proposals the same evening: that is a refusal at
                    // the door, not a receipt. The controller asks the same class
                    // first so the sentence lands under the right box.
                    $manufacturedDate = $this->dateOrNull($entry['manufactured_date'] ?? null);
                    $expiryDate = $this->dateOrNull($entry['expiry_date'] ?? null);

                    foreach (ReceivedBatchDates::problems($manufacturedDate, $expiryDate) as $problem) {
                        throw new InvalidArgumentException("{$line->product->name} ({$line->product->sku}): {$problem}");
                    }

                    // ⚠⚠ THE ONE POINT WHERE THE PURCHASE UNIT BECOMES THE STOCK
                    // UNIT. Everything upstream of here - order, receipt line,
                    // supplier bill, purchase return - stays in the unit the
                    // supplier invoices. Everything downstream is stock units.
                    // For a product bought in the unit it is stocked in this
                    // returns its inputs unchanged. C-UOM-5, C-UOM-6.
                    $converted = $this->conversion->handle(
                        product: $line->product,
                        purchaseQuantity: $quantity,
                        purchaseUnitCost: (string) $line->unit_cost,
                        on: $receipt->received_at ?? now(),
                    );

                    $movement = $this->recordStockIn->handle(
                        product: $line->product,
                        warehouse: $order->warehouse,
                        quantity: $converted['quantity'],
                        unitCost: $converted['unitCost'],
                        performedBy: $receivedBy,
                        // ⚠⚠ The label data reaches the BATCH, which is the only
                        // place FEFO, the Expiry screen and the nightly expired-
                        // stock proposals look. Until 21 Sep 2026 a receipt passed
                        // none of it, so every bought batch had a null expiry:
                        // FEFO queued it behind every dated batch and could not
                        // tell which of two deliveries goes off first, and nothing
                        // bought ever reached the Expiry screen. Only opening
                        // stock and production output did.
                        batchNumber: $this->textOrNull($entry['batch_number'] ?? null),
                        expiryDate: $expiryDate,
                        note: "Received against {$order->po_number}".($note ? ": {$note}" : ''),
                        purchaseQuantity: $converted['purchaseQuantity'],
                        purchaseUnitId: $converted['purchaseUnitId'],
                        conversionFactor: $converted['factor'],
                        manufacturedDate: $manufacturedDate,
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

    private function textOrNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function dateOrNull(?string $value): ?string
    {
        $value = $this->textOrNull($value);

        // ⚠ Stored as a plain Y-m-d. The batch casts it to a `date`, and a value
        // carrying a time of day is how a Dhaka midnight becomes the day before.
        return $value === null ? null : Carbon::parse($value)->toDateString();
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
