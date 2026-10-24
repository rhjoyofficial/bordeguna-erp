<?php

namespace App\Modules\Sales\Support;

use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\DeliveryQuote;
use App\Modules\Commerce\Support\StandardDeliveryBands;
use App\Support\BdGeo;

/**
 * ⭐ B18 (Joy's initial plan R91, R93, 25 Oct 2026): "delivery charge depend on
 * the zones ... courier charge a fixed". What a courier delivery to a district
 * costs the customer, from the shop's own zone rates.
 *
 * ⚠ The SAME table the shop's checkout prices from (Commerce shipping zones,
 * per store), so a phone order and a website order to Bagerhat pay the same.
 * The store is the one selling under the order's brand, else any store of the
 * business. A business with no store, or a store with no rate for the
 * district, falls back to the group's standard bands Joy confirmed on 13 Sep
 * (Inside Dhaka 60, the five suburbs 90, everywhere else 120), never to zero:
 * free delivery is a decision somebody makes, not a fallback.
 */
final readonly class CourierZoneCharge
{
    public function __construct(
        public string $charge,
        public string $zone,
        public string $district,
    ) {}

    public static function for(?int $brandId, ?string $district): ?self
    {
        $district = BdGeo::districtNamed($district);

        if ($district === null) {
            return null;
        }

        $store = Store::query()
            ->when($brandId !== null, fn ($q) => $q->orderByRaw('CASE WHEN brand_id = ? THEN 0 ELSE 1 END', [$brandId]))
            ->whereHas('shippingZones')
            ->orderBy('id')
            ->first();

        if ($store !== null) {
            $quote = DeliveryQuote::for($store, $district);

            if ($quote->zone !== null) {
                return new self((string) $quote->charge(), (string) $quote->zoneName(), $district);
            }
        }

        return match (true) {
            in_array($district, StandardDeliveryBands::INSIDE_DHAKA, true) => new self('60.00', 'Inside Dhaka', $district),
            in_array($district, StandardDeliveryBands::SUBURBS, true) => new self('90.00', 'Dhaka suburbs', $district),
            default => new self('120.00', 'Outside Dhaka', $district),
        };
    }
}
