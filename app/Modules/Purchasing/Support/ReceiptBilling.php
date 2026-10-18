<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBillLine;
use Illuminate\Support\Collection;

/**
 * What is still billable on each delivery (goods receipt line), ruling P5,
 * 19 Oct 2026: one supplier bill may cover several challans and several
 * orders of one supplier, matched line by line to what arrived.
 *
 * ⚠⚠ THE ORDER LINE STAYS THE OUTER LIMIT. `quantity_invoiced` on the order
 * line (received minus returned minus invoiced) is checked exactly as before,
 * so nothing here can bill more than the order received. This adds the inner
 * limit: a receipt line is never billed beyond what arrived on it.
 *
 * Per order line, the receipt lines are taken oldest first. What a receipt
 * line has had billed AGAINST IT (bill lines naming it) comes off that line.
 * What was billed without naming a receipt (every bill before 19 Oct 2026)
 * and what went back to the supplier (returns name the order line, not the
 * receipt) are a pool taken from the oldest receipt lines first, first in,
 * first out, the way the goods themselves are used. Derived every time from
 * the documents, never stored: no second counter to drift.
 */
final class ReceiptBilling
{
    /**
     * Billable quantity per receipt line of one order line, as 4-place strings,
     * keyed by goods_receipt_line_id.
     *
     * @return array<int, string>
     */
    public static function billableByReceiptLine(PurchaseOrderLine $orderLine): array
    {
        $receiptLines = GoodsReceiptLine::query()
            ->where('purchase_order_line_id', $orderLine->id)
            ->orderBy('goods_receipt_id')
            ->orderBy('id')
            ->get(['id', 'quantity_received']);

        if ($receiptLines->isEmpty()) {
            return [];
        }

        $linked = self::billedPerReceiptLine($receiptLines->pluck('id')->all());
        $linkedTotal = array_sum($linked);

        // Billed on the order line without naming a receipt, plus returned.
        $pool = max(0, PurchaseLineEntry::scaled((string) $orderLine->quantity_invoiced) - $linkedTotal)
            + PurchaseLineEntry::scaled((string) $orderLine->quantity_returned);

        $result = [];

        foreach ($receiptLines as $line) {
            $open = max(0, PurchaseLineEntry::scaled((string) $line->quantity_received) - ($linked[$line->id] ?? 0));
            $taken = min($open, $pool);
            $pool -= $taken;

            $result[(int) $line->id] = self::unscaled($open - $taken);
        }

        return $result;
    }

    /** One receipt line's billable quantity, as a 4-place string. */
    public static function billableOn(GoodsReceiptLine $receiptLine, PurchaseOrderLine $orderLine): string
    {
        return self::billableByReceiptLine($orderLine)[$receiptLine->id] ?? '0.0000';
    }

    /**
     * The supplier's receipts with something still to bill, newest first,
     * each line with what is billable on it and the agreed figures the bill
     * screen opens on.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function openReceiptsOf(Supplier $supplier, int $limit = 60): Collection
    {
        $receipts = GoodsReceipt::query()
            ->whereHas('purchaseOrder', fn ($order) => $order->where('supplier_id', $supplier->id))
            ->whereHas('lines.purchaseOrderLine', fn ($line) => $line->stillBillable())
            ->with(['purchaseOrder:id,po_number,supplier_id,supplier_reference', 'lines.purchaseOrderLine.product:id,name,sku'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $byOrderLine = [];

        return $receipts->map(function (GoodsReceipt $receipt) use (&$byOrderLine) {
            $lines = $receipt->lines->map(function (GoodsReceiptLine $line) use (&$byOrderLine, $receipt) {
                $orderLine = $line->purchaseOrderLine;

                if ($orderLine === null) {
                    return null;
                }

                $byOrderLine[$orderLine->id] ??= self::billableByReceiptLine($orderLine);
                $billable = $byOrderLine[$orderLine->id][$line->id] ?? '0.0000';

                if (PurchaseLineEntry::scaled($billable) <= 0) {
                    return null;
                }

                return [
                    'goods_receipt_line_id' => $line->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'product' => $orderLine->product?->name,
                    'sku' => $orderLine->product?->sku,
                    'quantity_received' => (string) $line->quantity_received,
                    'billable' => $billable,
                    'quantity_ordered' => (string) $orderLine->quantity_ordered,
                    'order_unit_cost' => (string) $orderLine->unit_cost,
                    'order_entry_mode' => $orderLine->entry_mode?->value ?? 'rate',
                    'order_line_total' => $orderLine->lineTotal()->toTaka(),
                    'order_value_for_billable' => $orderLine->valueOf($billable)->toTaka(),
                    // ⭐ Purchase redesign PR1: the order's discount for this
                    // quantity (its own plus its share of the order discount)
                    // and its VAT rate, so the bill opens with them.
                    'order_discount_for_billable' => $orderLine->discountFor($billable)->toTaka(),
                    'order_tax_rate' => (string) $orderLine->tax_rate,
                    'order_reference' => $receipt->purchaseOrder?->supplier_reference,
                ];
            })->filter()->values();

            return [
                'id' => $receipt->id,
                'received_on' => $receipt->received_at?->toDateString(),
                'challan' => $receipt->supplier_challan_number,
                'purchase_order_id' => $receipt->purchase_order_id,
                'po_number' => $receipt->purchaseOrder?->po_number,
                'lines' => $lines->all(),
            ];
        })->filter(fn (array $receipt) => $receipt['lines'] !== [])->values();
    }

    /**
     * Live bill quantity per receipt line, scaled by 10,000.
     *
     * @param  list<int>  $receiptLineIds
     * @return array<int, int>
     */
    private static function billedPerReceiptLine(array $receiptLineIds): array
    {
        return SupplierBillLine::query()
            ->whereIn('goods_receipt_line_id', $receiptLineIds)
            ->whereHas('bill', fn ($bill) => $bill->whereNull('reversed_at'))
            ->get(['goods_receipt_line_id', 'quantity'])
            ->groupBy('goods_receipt_line_id')
            ->map(fn ($lines) => $lines->sum(fn ($line) => PurchaseLineEntry::scaled((string) $line->quantity)))
            ->all();
    }

    private static function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
