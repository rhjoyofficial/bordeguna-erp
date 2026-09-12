<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Commerce\Models\Store;
use App\Modules\Inventory\Models\Product;

/**
 * Can this shop sell this product right now?
 *
 * ⭐⭐ ONE STOCK NUMBER, A POLICY PER CHANNEL (ADR-004). This class is the only
 * place that reads the policy and the only place that decides. The storefront
 * page, the add-to-cart action and, later, the checkout all ask it the same
 * question through the same method, for the reason `CataloguePublishing` gives
 * about price: written twice, the page and the button come to disagree, and the
 * shape of that disagreement is a product a customer can see, be told is
 * available, and then be refused at checkout.
 *
 * ⛔ It never writes anything and it never reserves anything. Under
 * `status_based` a sale at zero records a COMMITMENT when the order is placed,
 * and a commitment is an order concept. Reserving here would make the warehouse
 * look emptier than it is for every abandoned basket.
 */
final class Availability
{
    /**
     * The policy actually in force: the product's own override if it has one,
     * otherwise the channel's.
     *
     * ⚠ The override only ever NARROWS. It exists so a product that genuinely
     * cannot be made to order - imported goods, seasonal fruit - refuses at zero
     * even on a shop whose default is to keep selling. A product cannot use it
     * to sell at zero on the counter, where the customer is standing there.
     */
    public static function policyFor(Store $store, Product $product): AvailabilityPolicy
    {
        $channel = $store->availability_policy ?? AvailabilityPolicy::StatusBased;
        $override = $product->availability_override;

        if (! $override instanceof AvailabilityPolicy) {
            return $channel;
        }

        // ⛔ Enforced here as well as at the product screen, because a value can
        // arrive by import or by hand in the table. Whichever of the two is the
        // stricter wins, so a stored `status_based` override on a strict
        // channel changes nothing.
        return $override->allowsSaleAtZero() ? $channel : $override;
    }

    public static function decide(Store $store, Product $product): AvailabilityDecision
    {
        // ⭐ An inactive product is OUT OF SEASON, not gone. Today a switched-off
        // product 404s, which throws away that URL's accumulated search
        // authority every year and earns it back from scratch every season.
        // ⚠ It is still not sellable: this changes what the page says, never
        // what the button does.
        if (! $product->is_active) {
            return new AvailabilityDecision(
                sellable: false,
                reason: 'Out of season. Tell us and we will let you know when it is back.',
            );
        }

        $policy = self::policyFor($store, $product);
        $hasStock = (float) $product->currentStockQuantity() > 0;

        if (! $hasStock && ! $policy->allowsSaleAtZero()) {
            return new AvailabilityDecision(
                sellable: false,
                reason: 'Out of stock.',
            );
        }

        return new AvailabilityDecision(
            sellable: true,
            // ⚠ Made to order means "we do not hold this", which is a fact about
            // stock, not about the policy. A status_based shop that HAS the
            // goods should not tell a buyer to wait.
            madeToOrder: ! $hasStock,
            leadTimeDays: $hasStock ? null : $product->lead_time_days,
        );
    }
}
