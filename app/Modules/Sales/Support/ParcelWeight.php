<?php

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;

/**
 * THE parcel weight, worked out in one place (courier hub slice 3, Joy 11 Oct
 * 2026): Σ quantity x the product's weight, for an order (what was ordered) or
 * a challan (what that challan carries, so a part shipment weighs only its
 * part).
 *
 * ⚠⚠ A MISSING WEIGHT IS NEVER ZERO (ruling 10). The known part is added up so
 * a screen can say "at least 1,200 g, 1 item without weight", but the AUTO
 * weight a record keeps is null until every line has a weight - a parcel
 * under-declared by a forgotten jar is exactly the estimate a fee analysis
 * must not trust.
 */
final class ParcelWeight
{
    /**
     * @param  list<array{product_id:int, sku:string, name:string, quantity:string}>  $missing
     */
    private function __construct(
        public readonly int $knownGrams,
        public readonly array $missing,
        public readonly int $lines,
    ) {}

    /**
     * @param  iterable<array{0: Product|null, 1: string|int|float}>  $lines  product and quantity
     */
    public static function ofLines(iterable $lines): self
    {
        $known = 0.0;
        $missing = [];
        $count = 0;

        foreach ($lines as [$product, $quantity]) {
            if ($product === null || (float) $quantity <= 0) {
                continue;
            }

            $count++;

            if ($product->weight_grams === null) {
                $missing[] = [
                    'product_id' => $product->id,
                    'sku' => (string) $product->sku,
                    'name' => (string) $product->name,
                    'quantity' => (string) $quantity,
                ];

                continue;
            }

            $known += (float) $quantity * $product->weight_grams;
        }

        return new self((int) round($known), $missing, $count);
    }

    /** What the order's lines weigh, at their ordered quantities. */
    public static function forOrder(SalesOrder $order): self
    {
        $order->loadMissing('lines.product');

        return self::ofLines($order->lines->map(fn (SalesOrderLine $line) => [$line->product, $line->quantity_ordered]));
    }

    /** What one challan weighs, at the quantities it carries. */
    public static function forDelivery(Delivery $delivery): self
    {
        $delivery->loadMissing('lines.product');

        return self::ofLines($delivery->lines->map(fn (DeliveryLine $line) => [$line->product, $line->quantity]));
    }

    /**
     * The weight to offer when a challan is handed to a courier.
     *
     * The order's typed weight when this challan carries the whole order (the
     * person who took the order already said what it weighs); otherwise the
     * challan's own auto weight, null when a line has no weight.
     */
    public static function suggestedForDelivery(Delivery $delivery): ?int
    {
        $delivery->loadMissing('lines.product', 'lines.orderLine.order.lines');

        $orders = $delivery->lines->map(fn (DeliveryLine $line) => $line->orderLine?->order)->filter()->unique('id');

        if ($orders->count() === 1 && $delivery->lines->every(fn (DeliveryLine $line) => $line->orderLine !== null)) {
            /** @var SalesOrder $order */
            $order = $orders->first();

            $carried = $delivery->lines->groupBy('sales_order_line_id')
                ->map(fn ($lines) => $lines->sum(fn (DeliveryLine $line) => (float) $line->quantity));

            $whole = $order->lines->every(
                fn (SalesOrderLine $line) => abs(($carried[$line->id] ?? 0) - (float) $line->quantity_ordered) < 0.00005
            );

            if ($whole && $order->weight_grams !== null) {
                return (int) $order->weight_grams;
            }
        }

        return self::forDelivery($delivery)->autoGrams();
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /** The auto weight a record keeps: null unless every line has a weight. */
    public function autoGrams(): ?int
    {
        return $this->lines > 0 && $this->isComplete() ? $this->knownGrams : null;
    }

    /**
     * @return array{known_grams:int, auto_grams:int|null, missing_count:int, missing:list<array{product_id:int, sku:string, name:string, quantity:string}>}
     */
    public function toArray(): array
    {
        return [
            'known_grams' => $this->knownGrams,
            'auto_grams' => $this->autoGrams(),
            'missing_count' => count($this->missing),
            'missing' => $this->missing,
        ];
    }
}
