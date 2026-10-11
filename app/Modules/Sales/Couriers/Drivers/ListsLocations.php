<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Models\CourierAccount;

/**
 * A courier whose coverage list (city, zone, area) can be read through its
 * API. Read-only: nothing here changes anything at the courier.
 */
interface ListsLocations
{
    public function cities(CourierAccount $account): LocationList;

    public function zones(CourierAccount $account, string $cityId): LocationList;

    /** CarryBee nests areas under the city as well, so both ids are given. */
    public function areas(CourierAccount $account, string $cityId, string $zoneId): LocationList;
}
