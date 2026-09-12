<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\DistrictShippingZone;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Support\BdGeo;

/**
 * What this shop charges to deliver to this district.
 *
 * ⭐⭐ THE ONLY THING A CUSTOMER STATES IS WHERE THEY LIVE. No band, no zone, no
 * charge - none of those is an input anywhere, which is why there is no
 * disagreeing pair to refuse. LaLaDia asked for the district AND a "Delivery
 * Zone" the customer picked, compared them nowhere, and let somebody in Bagerhat
 * pay the Dhaka rate for months.
 *
 * ⚠ Resolved at READ TIME, never stored on the cart. The cart records the
 * district the customer chose; the price comes from the rate table every time it
 * is shown. Change a rate in the ERP and every open basket follows on its next
 * read - the same rule the product price already follows.
 */
final class DeliveryQuote
{
    public function __construct(
        public readonly ?string $district,
        public readonly ?ShippingZone $zone,
        public readonly bool $isFallback,
    ) {}

    /**
     * ⚠ Three genuinely different answers, and a caller must tell them apart:
     *
     *  - no district chosen yet   → `district` null, `zone` null. Ask.
     *  - district priced          → a zone, `isFallback` false.
     *  - district not priced      → the shop's catch-all, `isFallback` true.
     *
     * ⛔ And a fourth: a shop with NO catch-all answers a null zone even though a
     * district was chosen. That shop cannot quote and must not pretend to. See
     * `cannotQuote()`.
     */
    public static function for(Store $store, ?string $district): self
    {
        $resolved = BdGeo::districtNamed($district);

        if ($resolved === null) {
            return new self(null, null, false);
        }

        $mapped = DistrictShippingZone::query()
            ->where('store_id', $store->getKey())
            ->where('district', $resolved)
            ->with('zone')
            ->first();

        if ($mapped?->zone !== null) {
            return new self($resolved, $mapped->zone, false);
        }

        // ⚠ The catch-all, and it is a REAL rate somebody set rather than zero.
        // Falling back to free would be the shop paying for delivery it never
        // agreed to, silently, for every district nobody got round to pricing.
        $fallback = ShippingZone::query()
            ->where('store_id', $store->getKey())
            ->where('is_default', true)
            ->first();

        return new self($resolved, $fallback, true);
    }

    /** A district was chosen and the shop has no rate that covers it. */
    public function cannotQuote(): bool
    {
        return $this->district !== null && $this->zone === null;
    }

    public function charge(): ?string
    {
        return $this->zone?->charge;
    }

    public function zoneName(): ?string
    {
        return $this->zone?->name;
    }
}
