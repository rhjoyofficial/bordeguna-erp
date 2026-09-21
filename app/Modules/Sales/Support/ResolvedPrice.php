<?php

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\ProductPrice;

/**
 * A price, and the rule that produced it.
 *
 * ⚠ The RULE travels with the number on purpose. "Why is it this price?" is the
 * first question anybody asks at a counter, and answering it from a bare decimal
 * means guessing at the resolution afterwards - which is a second implementation
 * of the resolver, written under pressure, in a screen.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public string $unitPrice,
        public ProductPrice $rule,
    ) {}

    /**
     * Which tier answered: `customer`, `price_group` or `default`.
     */
    /**
     * The product's MRP standing in for a price nobody set, for a group that
     * falls back to it (21 Sep 2026). ⚠ The rule is built in memory and never
     * saved: there is no price row, and pretending otherwise would put a price
     * in the table that the owner never entered.
     */
    public static function fromMrp(Product $product, ?int $priceGroupId): self
    {
        $rule = new ProductPrice([
            'business_id' => $product->business_id,
            'product_id' => $product->id,
            'price_group_id' => $priceGroupId,
            'min_quantity' => '0',
            'unit_price' => (string) $product->mrp,
            'price_method' => 'mrp',
            'effective_from' => now()->toDateString(),
        ]);

        return new self((string) $product->mrp, $rule);
    }

    public function isMrp(): bool
    {
        return ! $this->rule->exists && $this->rule->price_method === 'mrp';
    }

    public function tier(): string
    {
        if ($this->isMrp()) {
            return 'mrp';
        }

        return match ($this->rule->specificity()) {
            3 => 'customer',
            2 => 'price_group',
            default => 'default',
        };
    }

    /**
     * A short human explanation, for a tooltip or an audit line.
     */
    public function explain(): string
    {
        if ($this->isMrp()) {
            return 'MRP (no price set for this group)';
        }

        return match ($this->tier()) {
            'customer' => 'Agreed price for this customer',
            'price_group' => "Price for group [{$this->rule->priceGroup?->code}]",
            default => 'Standard selling price',
        }.((float) $this->rule->min_quantity > 0
            ? " from {$this->rule->min_quantity} units"
            : '');
    }
}
