<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;

/**
 * ⭐ Initial plan B20 (Joy, 24 Oct 2026): what the return form shows beside
 * each line when it is opened from an invoice or a challan, so the person sees
 * the exact batches the sale took and the price the customer was billed.
 *
 * ⚠ Display only. RecordSalesReturn decides the real credit (uninvoiced part
 * first, the invoice's discount share clawed back, remainder discipline); the
 * "about" figure here is that same arithmetic for one unit, never a second
 * truth the server would honour.
 */
final class ReturnFormFacts
{
    /**
     * @return array{batches: list<array{batch_number: string, expiry_date: ?string, quantity: string}>, unit_price: ?string, credit_per_unit: ?string}
     */
    public static function forLine(DeliveryLine $line, ?SalesInvoice $invoice): array
    {
        $invoiceLine = SalesInvoiceLine::query()
            ->where('delivery_line_id', $line->id)
            ->when($invoice !== null, fn ($q) => $q->where('sales_invoice_id', $invoice->id))
            ->whereHas('invoice', fn ($q) => $q->whereNull('reversed_at'))
            ->with('invoice')
            ->orderByDesc('id')
            ->first();

        return [
            'batches' => DeliveredBatches::forLines([$line->id])[$line->id] ?? [],
            'unit_price' => $invoiceLine === null ? null : Money::ofTaka((string) $invoiceLine->unit_price)->toTaka(),
            'credit_per_unit' => $invoiceLine === null ? null : self::creditPerUnit($invoiceLine),
        ];
    }

    /**
     * @return array{id: int, invoice_number: string, total: string, outstanding: string, deliveries: list<array{id: int, delivery_number: string, delivered_at: ?string}>}
     */
    public static function invoice(SalesInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'total' => Money::ofTaka((string) $invoice->total)->toTaka(),
            'outstanding' => $invoice->outstanding()->toTaka(),
            'deliveries' => $invoice->lines
                ->pluck('deliveryLine.delivery')
                ->filter()
                ->unique('id')
                ->map(fn ($delivery) => [
                    'id' => $delivery->id,
                    'delivery_number' => $delivery->delivery_number,
                    'delivered_at' => $delivery->delivered_at?->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * ⭐ B20 (Joy: "Step 2: Create a brand new Sales Invoice for the
     * replacement"): the order form's seed for the replacement of an exchange,
     * in the shape of a Copy (SalesOrderActions::form), so the SAME order form
     * opens, prefilled with the customer and the items that came back. The
     * quantities stay editable; the challan of the new order takes fresh
     * batches FEFO like any sale, so the new bottle carries its own batch.
     *
     * @return array<string, mixed>|null null when the return already has its replacement order
     */
    public static function exchangeSeed(SalesReturn $return): ?array
    {
        $return->loadMissing(['delivery', 'lines.deliveryLine.orderLine.order', 'exchangeOrders:id,exchange_for_sales_return_id']);

        if ($return->exchangeOrders->isNotEmpty()) {
            return null;
        }

        $order = $return->lines->map(fn ($line) => $line->deliveryLine?->orderLine?->order)->filter()->first();

        return [
            'id' => null,
            'order_number' => $return->return_number,
            'mode' => 'copy',
            'customer_id' => $return->customer_id,
            'brand_id' => $order?->brand_id,
            'warehouse_id' => $return->delivery?->warehouse_id,
            'ordered_at' => null,
            'expected_date' => null,
            'note' => "Exchange for {$return->return_number}",
            'special_instructions' => null,
            'delivery_charge' => '',
            'weight_grams' => null,
            'address' => ['name' => '', 'phone' => '', 'district' => '', 'thana' => '', 'area' => '', 'address_line' => '', 'landmark' => ''],
            'lines' => $return->lines->map(fn ($line) => [
                'id' => null,
                'product_id' => $line->deliveryLine->product_id,
                'quantity' => rtrim(rtrim(number_format((float) $line->quantity, 4, '.', ''), '0'), '.'),
                'unit_price' => $line->deliveryLine->orderLine === null ? '' : rtrim(rtrim(number_format((float) $line->deliveryLine->orderLine->unit_price, 4, '.', ''), '0'), '.'),
                'description' => '',
                'delivered' => 0,
            ])->values()->all(),
            'exchange_for' => ['id' => $return->id, 'return_number' => $return->return_number],
        ];
    }

    /** The net the customer paid for one unit: line net less its share of the bill discount. */
    private static function creditPerUnit(SalesInvoiceLine $line): ?string
    {
        $quantity = (float) $line->quantity;

        if ($quantity <= 0) {
            return null;
        }

        $net = Money::ofTaka((string) $line->line_net)->minor;
        $subtotal = $line->invoice->goodsSubtotal()->minor;
        $discount = Money::ofTaka((string) $line->invoice->invoice_discount)->minor;
        $share = $subtotal > 0 ? (int) round($discount * $net / $subtotal) : 0;
        $tax = $line->prices_include_tax ? 0 : Money::ofTaka((string) $line->tax_amount)->minor;

        return Money::ofMinor((int) round(($net - $share + $tax) / $quantity))->toTaka();
    }
}
