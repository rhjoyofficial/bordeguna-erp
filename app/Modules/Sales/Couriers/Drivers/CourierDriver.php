<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Models\CourierAccount;

/**
 * What the ERP asks of a courier's API. Slice 1 asks one read-only question:
 * do these credentials work, and which pickup stores and how many cities does
 * the account see. Booking, cancelling and status arrive in later slices.
 */
interface CourierDriver
{
    public function testConnection(CourierAccount $account): ConnectionResult;
}
