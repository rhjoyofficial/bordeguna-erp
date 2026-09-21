<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Models\GoodsReceiptLine;

/**
 * What each product cost the last time it actually arrived.
 *
 * ⚠ "Last RECEIVED", not "last ordered". An order that was never delivered is
 * an offer nobody honoured; the price goods came in at is the one the stock was
 * valued at, which is what somebody typing a new order price wants to compare
 * against. It is the order line's agreed unit cost, in the PURCHASE unit, the
 * same unit the order form asks for.
 *
 * ⚠⚠ Withheld when the product's purchase unit has changed since. The batch
 * remembers the unit it was bought in (C-UOM-4); if that is not the unit the
 * product is bought in today, "last received at 180" would be 180 per the old
 * unit shown beside the new one, the ~9% trap every purchase screen already
 * warns about. No figure is better than a figure in the wrong unit.
 *
 * Two queries whatever the number of products: one to find the newest receipt
 * line per product, one to load those lines.
 */
final class LastReceivedCost
{
    /**
     * @param  array<int, int>|null  $productIds  null for every product
     * @param  int|null  $excludingOrderId  leave this order's own receipts out (its page already shows them)
     * @return array<int, array{unit_cost: string, po_number: string|null, received_on: string|null}> keyed by product id
     */
    public static function forProducts(?array $productIds = null, ?int $excludingOrderId = null): array
    {
        if ($productIds === []) {
            return [];
        }

        // ⚠ Newest by receipt-line id, which is the order receipts were
        // recorded in. The global business scope qualifies itself
        // (`goods_receipt_lines.business_id`), so the join cannot make it
        // ambiguous.
        $newest = GoodsReceiptLine::query()
            ->join('purchase_order_lines', 'purchase_order_lines.id', '=', 'goods_receipt_lines.purchase_order_line_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('purchase_order_lines.product_id', $productIds))
            ->when($excludingOrderId !== null, fn ($q) => $q->where('purchase_order_lines.purchase_order_id', '!=', $excludingOrderId))
            ->where('goods_receipt_lines.quantity_received', '>', 0)
            ->groupBy('purchase_order_lines.product_id')
            ->selectRaw('max(goods_receipt_lines.id)');

        return GoodsReceiptLine::query()
            ->whereIn('id', $newest)
            ->with([
                'goodsReceipt:id,received_at',
                'purchaseOrderLine:id,purchase_order_id,product_id,unit_cost',
                'purchaseOrderLine.purchaseOrder:id,po_number',
                'purchaseOrderLine.product:id,unit_of_measure_id,purchase_unit_id',
                'stockMovement:id,batch_id',
                'stockMovement.batch:id,purchase_unit_id',
            ])
            ->get()
            ->filter(function (GoodsReceiptLine $line) {
                $product = $line->purchaseOrderLine?->product;
                $batch = $line->stockMovement?->batch;

                if ($product === null) {
                    return false;
                }

                // A service line moved no stock, so there is no batch to ask.
                if ($batch === null) {
                    return true;
                }

                $boughtInToday = $product->isBoughtInAnotherUnit() ? $product->purchase_unit_id : null;

                // ⚠ Compared as integers (0 for "the stock unit"): a driver that
                // hands ids back as strings would otherwise withhold every figure.
                return (int) ($batch->purchase_unit_id ?? 0) === (int) ($boughtInToday ?? 0);
            })
            ->mapWithKeys(fn (GoodsReceiptLine $line) => [
                $line->purchaseOrderLine->product_id => [
                    'unit_cost' => self::exact((string) $line->purchaseOrderLine->unit_cost),
                    'po_number' => $line->purchaseOrderLine->purchaseOrder?->po_number,
                    // ⚠ toDateString(), never the datetime cast: the cast
                    // serialises in UTC and a Dhaka morning becomes yesterday.
                    'received_on' => $line->goodsReceipt?->received_at?->toDateString(),
                ],
            ])
            ->all();
    }

    /**
     * The stored cost, exactly, without the column's trailing zeros.
     *
     * ⚠ Not Money::toTaka(). A unit cost carries FOUR places (a gram of saffron
     * costs 0.4567), and rounding it to paisa for display would show a figure
     * nobody paid. String work only, no float, the house rule for money.
     */
    private static function exact(string $decimal): string
    {
        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');

        return $whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
