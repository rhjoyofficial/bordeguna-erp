<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;

/**
 * What a challan bills when nobody types anything: the invoice lines a courier's
 * "delivered" raises by itself, and the amount the courier is told to collect.
 *
 * ⚠⚠ THE SAME DEFAULTS THE INVOICE SCREEN SENDS (C-SO-4): the order's agreed
 * price and agreed discount for a line that traces to an order, the resolver at
 * the invoice date for one that does not. RecordSalesInvoice then adds the
 * delivery charge and the order discount itself, so the preview below mirrors
 * those two rules, and a test holds the preview to the invoice it predicts.
 */
final class DeliveryBilling
{
    /**
     * Invoice lines for the given quantity of each delivery line.
     *
     * @param  array<int, string>  $quantities  keyed by delivery line id; missing or zero is not billed
     * @return array<int, array{deliveryLine: DeliveryLine, product: mixed, description: null, quantity: string, unitPrice: string, discount: string}>
     *
     * @throws NoSellingPriceException
     */
    public static function lines(Delivery $delivery, array $quantities, ResolveSellingPrice $resolver, Carbon $on): array
    {
        $delivery->loadMissing('lines.product', 'lines.orderLine', 'customer');

        $lines = [];

        foreach ($delivery->lines as $line) {
            $quantity = $quantities[$line->id] ?? '0';

            if ((float) $quantity <= 0) {
                continue;
            }

            $orderLine = $line->orderLine;

            $lines[] = [
                'deliveryLine' => $line,
                'product' => $line->product,
                'description' => null,
                'quantity' => $quantity,
                'unitPrice' => $orderLine !== null
                    ? (string) $orderLine->unit_price
                    : $resolver->resolve($line->product, $delivery->customer, $quantity, $on)->unitPrice,
                'discount' => $orderLine?->discountToBill($quantity)->toTaka() ?? '0',
            ];
        }

        return $lines;
    }

    /**
     * Every uninvoiced quantity on the challan, the quantities a full delivery bills.
     *
     * @return array<int, string>
     */
    public static function everythingUninvoiced(Delivery $delivery): array
    {
        $delivery->loadMissing('lines');

        return $delivery->lines
            ->mapWithKeys(fn (DeliveryLine $line) => [$line->id => $line->quantityUninvoiced()])
            ->all();
    }

    /**
     * What an invoice billing everything still uninvoiced on this challan would
     * total today, or null when a line has no price to bill at.
     */
    public static function previewTotal(Delivery $delivery, ResolveSellingPrice $resolver): ?Money
    {
        try {
            $lines = self::lines($delivery, self::everythingUninvoiced($delivery), $resolver, Carbon::today());
        } catch (NoSellingPriceException) {
            return null;
        }

        $total = 0;
        $netByOrder = [];
        $billingByOrderLine = [];

        foreach ($lines as $entry) {
            $net = Money::ofProduct($entry['quantity'], $entry['unitPrice'])->minor - Money::ofTaka($entry['discount'])->minor;
            $total += $net;

            $orderLine = $entry['deliveryLine']->orderLine;

            if ($orderLine !== null) {
                $netByOrder[$orderLine->sales_order_id] = ($netByOrder[$orderLine->sales_order_id] ?? 0) + $net;
                $billingByOrderLine[$orderLine->id] = ($billingByOrderLine[$orderLine->id] ?? 0) + (float) $entry['quantity'];
            }
        }

        foreach ($netByOrder as $orderId => $netMinor) {
            $order = SalesOrder::query()->with('lines.deliveryLines')->find($orderId);

            if ($order === null) {
                continue;
            }

            // The first invoice for the order carries its whole delivery charge.
            if ($order->deliveryCharge()->minor > 0 && ! $order->deliveryChargeBilled()) {
                $total += $order->deliveryCharge()->minor;
            }

            // The invoice that finishes the order takes the remainder of its
            // discount - finished as RecordSalesInvoice sees it, after billing.
            $finishes = $order->lines->isNotEmpty() && $order->lines->every(
                fn ($orderLine) => (float) $orderLine->deliveryLines->sum('quantity_invoiced')
                    + ($billingByOrderLine[$orderLine->id] ?? 0) + 0.00005 >= (float) $orderLine->quantity_ordered
            );

            $total -= $order->orderDiscountToBill(Money::ofMinor($netMinor), $finishes)->minor;
        }

        return Money::ofMinor($total);
    }

    /**
     * What the courier should collect for this whole challan today: the invoice
     * it would raise, less money the customer already paid for its orders
     * (SSLCommerz slice P1), which that invoice would take as it was raised.
     * Null when a line has no price to bill at.
     */
    public static function previewDue(Delivery $delivery, ResolveSellingPrice $resolver): ?Money
    {
        $total = self::previewTotal($delivery, $resolver);

        if ($total === null) {
            return null;
        }

        $delivery->loadMissing('lines.orderLine');

        $held = $delivery->lines
            ->pluck('orderLine.sales_order_id')
            ->filter()
            ->unique()
            ->reduce(function (int $carry, int $orderId) {
                $order = SalesOrder::query()->find($orderId);

                return $carry + max(0, $order?->advanceHeld()->minor ?? 0);
            }, 0);

        return Money::ofMinor(max(0, $total->minor - $held));
    }
}
