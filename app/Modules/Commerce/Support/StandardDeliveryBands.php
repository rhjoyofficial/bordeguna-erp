<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * The delivery bands a shop starts with.
 *
 * ⭐ JOY, 13 SEPTEMBER: a shop starts with LaLaDia's bands - the rates the group
 * already charges today - and changes them on the Shipping screen. Inside Dhaka
 * 60, the five Dhaka suburbs 90, everywhere else 120 as the catch-all.
 *
 * ⚠ The suburbs list is the one Joy confirmed for LaLaDia on 31 August:
 * Gazipur, Narayanganj, Narsingdi, Manikganj, Munshiganj.
 *
 * ⚠ Applied only to a store with NO zones. A store somebody has already priced
 * is never overwritten: a rate a person set is a decision, and replacing it with
 * a default would change what customers pay without anybody choosing to.
 */
final class StandardDeliveryBands
{
    public const INSIDE_DHAKA = ['Dhaka'];

    public const SUBURBS = ['Gazipur', 'Narayanganj', 'Narsingdi', 'Manikganj', 'Munshiganj'];

    /**
     * @return bool whether the bands were applied
     */
    public static function applyTo(Store $store): bool
    {
        if ($store->shippingZones()->exists()) {
            return false;
        }

        DB::transaction(function () use ($store) {
            $inside = $store->shippingZones()->create(['name' => 'Inside Dhaka', 'charge' => '60', 'sort_order' => 1]);
            $suburbs = $store->shippingZones()->create(['name' => 'Dhaka suburbs', 'charge' => '90', 'sort_order' => 2]);
            $outside = $store->shippingZones()->create(['name' => 'Outside Dhaka', 'charge' => '120', 'sort_order' => 3]);

            $outside->makeDefault();

            foreach (self::INSIDE_DHAKA as $district) {
                $store->districtShippingZones()->create(['district' => $district, 'shipping_zone_id' => $inside->id]);
            }

            foreach (self::SUBURBS as $district) {
                $store->districtShippingZones()->create(['district' => $district, 'shipping_zone_id' => $suburbs->id]);
            }
        });

        return true;
    }
}
