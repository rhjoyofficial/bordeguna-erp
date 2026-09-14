<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Actions\PlaceWebsiteOrder;
use App\Modules\Commerce\Enums\GatewayPaymentStatus;
use App\Modules\Commerce\Models\GatewayPayment;
use App\Modules\Foundation\Support\DocumentMoney;
use App\Modules\Sales\Enums\SalesOrderStatus;
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
 * ⚠ For cash on delivery it reports no payment state, because there is none:
 * the money arrives with the parcel. For an order paid online (SSLCommerz slice
 * P2c) it reports exactly what the GATEWAY has proven and nothing more - never
 * "paid" on the strength of the customer's browser coming back, which is how
 * LaLaDia fired a full-value Purchase for an order nobody had paid for.
 */
final class PlacedOrderPresenter
{
    /**
     * How long an online order waits for its payment before it is cancelled
     * (Joy, 13 September).
     */
    public const PAYMENT_WINDOW_HOURS = 24;

    /**
     * Where an online payment stands, from the gateway's proven attempts only.
     *
     * @return array{state: string, amount_paid: ?string, can_pay: bool, pay_by: ?string, last_attempt: ?string}|null
     */
    private static function payment(SalesOrder $order): ?array
    {
        if ($order->payment_method !== PlaceWebsiteOrder::ONLINE) {
            return null;
        }

        $attempts = GatewayPayment::query()->where('sales_order_id', $order->id)->orderByDesc('id')->get();
        $took = $attempts->filter(fn (GatewayPayment $attempt) => $attempt->status->tookMoney());
        $cancelled = $order->status === SalesOrderStatus::Cancelled;

        $state = match (true) {
            // ⚠ Paid, then cancelled: the money is the customer's to have back.
            $cancelled && $took->isNotEmpty() => 'refund_due',
            $took->contains(fn (GatewayPayment $attempt) => $attempt->status === GatewayPaymentStatus::Paid) => 'paid',
            $took->isNotEmpty() => 'confirming',
            $cancelled => 'cancelled',
            default => 'awaiting',
        };

        $paid = $took->reduce(fn (Money $carry, GatewayPayment $attempt) => $carry->plus($attempt->amountPaidMoney() ?? Money::zero()), Money::zero());
        $latest = $attempts->first();

        return [
            'state' => $state,
            'amount_paid' => $paid->isZero() ? null : DocumentMoney::amount($paid->toTaka()),
            'can_pay' => $state === 'awaiting' && $order->store?->takesOnlinePayment() === true,
            'pay_by' => $state === 'awaiting'
                ? $order->created_at?->copy()->addHours(self::PAYMENT_WINDOW_HOURS)->timezone(config('app.timezone'))->format('j F Y, g:i a')
                : null,
            // What happened last time, in words, when it did not go through.
            'last_attempt' => $state === 'awaiting' && $latest !== null && in_array($latest->status, [GatewayPaymentStatus::Failed, GatewayPaymentStatus::Cancelled], true)
                ? $latest->status->label()
                : null,
        ];
    }

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
            // Slice 2.5b: the coupon's discount on the whole order, if any, and
            // its code. Includes the value of a gift given with the code.
            'order_discount' => $order->orderDiscount()->isZero()
                ? null
                : DocumentMoney::amount($order->orderDiscount()->toTaka()),
            'coupon_code' => $order->coupon_code,
            // D24: the promise this order was given, and the date it became at
            // confirmation (null until somebody confirms it).
            'promise' => $order->deliveryPromise(),
            'expected_by' => $order->expected_date?->format('j F Y'),
            'delivery_charge' => $order->delivery_charge === null
                ? null
                : DocumentMoney::amount((string) $order->delivery_charge),
            // One place adds goods to delivery, and it is the model that
            // holds the agreement. A presenter with its own arithmetic is a
            // second opinion about what the customer owes.
            'total' => DocumentMoney::amount($order->agreedTotal()),
            'payment_method' => $order->payment_method,
            'payment' => self::payment($order),
            'recipient' => $address === null ? null : [
                'name' => $address->recipient_name,
                'phone' => $address->recipient_phone,
                'address' => $address->oneLine(),
            ],
        ];
    }
}
