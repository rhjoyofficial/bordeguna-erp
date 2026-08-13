<?php

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Carbon;

/**
 * One cost layer, as at the valuation date.
 *
 * ⚠ `unitCost()` is DERIVED from value over quantity, never read from the
 * batch. The batch's `unit_cost` is mutated in place by a revaluation, so
 * reading it would report today's cost against a historical quantity - a figure
 * belonging to no date at all. Dividing the as-at value by the as-at quantity
 * is the only expression that is true on the date being asked about.
 */
final readonly class ValuationRow
{
    public function __construct(
        public int $productId,
        public string $productSku,
        public string $productName,
        public ?string $brand,
        public int $warehouseId,
        public string $warehouseName,
        public ?int $batchId,
        public ?string $batchNumber,
        public ?Carbon $expiryDate,
        public string $quantity,
        public Money $value,
    ) {}

    /**
     * Nothing left and nothing owed: a fully consumed layer. Dropped from the
     * report as noise.
     *
     * ⚠ BOTH must be zero. A layer with no quantity but a residual value is
     * exactly the kind of stranded cost this report exists to surface.
     */
    public function isEmpty(): bool
    {
        return $this->quantityAsFloat() === 0.0 && $this->value->isZero();
    }

    /**
     * C-IVL-3. What a reader must not be allowed to scroll past.
     *
     * Negative stock means something went out that no receipt explains. Value
     * without quantity means cost stranded on an empty layer. Quantity without
     * value means goods on the shelf the books think are free - and it is the
     * one of the three that a naive `SUM(quantity * unit_cost)` would report as
     * a perfectly ordinary zero.
     */
    public function isAnomalous(): bool
    {
        $quantity = $this->quantityAsFloat();

        return $quantity < 0
            || $this->value->minor < 0
            || ($quantity === 0.0 && ! $this->value->isZero())
            || ($quantity > 0 && $this->value->isZero());
    }

    public function anomaly(): ?string
    {
        $quantity = $this->quantityAsFloat();

        return match (true) {
            $quantity < 0 => 'Negative quantity: stock left that no receipt explains.',
            $this->value->minor < 0 => 'Negative value: more cost has been released than was ever received.',
            $quantity === 0.0 && ! $this->value->isZero() => 'Value with no quantity: cost stranded on an empty layer.',
            $quantity > 0 && $this->value->isZero() => 'Quantity with no value: goods on the shelf the books treat as free.',
            default => null,
        };
    }

    /**
     * ⚠ Null rather than zero when there is nothing to divide by. A unit cost of
     * "0.00" on an empty layer reads as a real, known cost of nothing.
     */
    public function unitCost(): ?string
    {
        $quantity = $this->quantityAsFloat();

        if ($quantity === 0.0) {
            return null;
        }

        return number_format($this->value->minor / 100 / $quantity, 4, '.', '');
    }

    public function quantityAsFloat(): float
    {
        return (float) $this->quantity;
    }
}
