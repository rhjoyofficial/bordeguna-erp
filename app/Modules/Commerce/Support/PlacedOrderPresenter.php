<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Foundation\Support\DocumentMoney;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;

/**
 * An order a customer has just placed, ready for a theme to draw.
 *
 * ⭐⭐ EVERY FIGURE COMES FROM THE STORED AGREEMENT, NOT FROM THE PRICE LIST.
 * This is the one page in the shop that must NOT resolve prices at read time:
 * the basket asks what things cost now, an order says what was agreed then, and
 * a confirmation page that re-priced itself would show a customer a different
 * total next week than the one they accepted.
 *
 * ⚠ It reports no payment state, because there is none to report. Cash on
 * delivery means the money arrives with the parcel; a page that showed "paid"
 * or even "payment pending" would be describing a gateway this order never
 * touched.
 */
final class PlacedOrderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(SalesOrder $order): array
    {
        $lines = [];

        foreach ($order->lines as $line) {
            /** @var SalesOrderLine $line */
            $lines[] = [
                'title' => (string) $line->product?->name,
                // ⚠ The bundle this line was sold in, if any, so the customer
                // recognises the thing they chose rather than a list of jars.
                'bundle' => $line->bundle_label,
                // ⚠ Trimmed of its trailing zeroes for a customer's eyes only.
                // The stored quantity keeps all four places; this is a label.
                'quantity' => rtrim(rtrim((string) $line->quantity_ordered, '0'), '.'),
                'unit_price' => DocumentMoney::amount((string) $line->unit_price),
                'discount' => $line->discount()->isZero() ? null : DocumentMoney::amount($line->discount()->toTaka()),
                // ⚠ NET, from the model, so this column and the goods total
                // under it are added the same way and cannot disagree.
                'line_total' => DocumentMoney::amount($line->netTotal()->toTaka()),
            ];
        }

        $address = $order->address;

        return [
            // ⚠ The SHOP's reference, which is what the customer has. The ERP's
            // own order number is deliberately not shown: it is sequential
            // across the business and tells a reader how much was sold between
            // any two orders.
            'reference' => (string) $order->shop_reference,
            'placed_on' => $order->ordered_at?->format('j F Y'),
            'lines' => $lines,
            // ⚠ Both totals come from the ORDER, adding each line the same way
            // this page prints it, so the column and the total under it cannot
            // disagree by a paisa. A presenter with its own arithmetic is a
            // second opinion about what the customer owes.
            'goods_total' => DocumentMoney::amount($order->goodsTotal()),
            'delivery_charge' => $order->delivery_charge === null
                ? null
                : DocumentMoney::amount((string) $order->delivery_charge),
            // One place adds goods to delivery, and it is the model that
            // holds the agreement. A presenter with its own arithmetic is a
            // second opinion about what the customer owes.
            'total' => DocumentMoney::amount($order->agreedTotal()),
            'payment_method' => $order->payment_method,
            'recipient' => $address === null ? null : [
                'name' => $address->recipient_name,
                'phone' => $address->recipient_phone,
                'address' => $address->oneLine(),
            ],
        ];
    }
}
