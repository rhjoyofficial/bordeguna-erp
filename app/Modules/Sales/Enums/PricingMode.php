<?php

namespace App\Modules\Sales\Enums;

/**
 * How a product's selling price is decided (Phase 2, 17 Oct 2026, ChatGPT Q4).
 *
 * ⚠⚠ THE MODE DECIDES, NOT THE PRESENCE OF AN MRP. A fish with `products.mrp`
 * left null and a packed jar whose MRP was never typed look the same in the
 * column; only this says which one is priced from the market each morning.
 * A market product is never priced through the MRP fallback, even when an
 * MRP was typed on it by mistake.
 */
enum PricingMode: string
{
    /** The printed MRP and the price list, as the engine has always worked. */
    case FixedMrp = 'fixed_mrp';

    /**
     * Today's price: proposed from the latest received batch cost and the
     * markup rules, confirmed by the receiver, published as one live RETAIL
     * row with an immutable history entry behind it.
     */
    case Market = 'market';

    public function label(): string
    {
        return match ($this) {
            self::FixedMrp => 'Fixed MRP',
            self::Market => 'Market price',
        };
    }
}
