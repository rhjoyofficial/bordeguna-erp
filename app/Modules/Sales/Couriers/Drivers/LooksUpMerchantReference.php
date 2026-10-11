<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Booking\ParcelDetails;
use App\Modules\Sales\Couriers\Models\CourierAccount;

/**
 * A courier that can be asked "did you take a parcel under OUR reference?".
 * CarryBee accepts merchant_order_id on GET details; Pathao has no such lookup
 * (ruling 13: do not assume it), so a person decides there.
 */
interface LooksUpMerchantReference
{
    public function lookupMerchantReference(CourierAccount $account, string $merchantOrderId): ParcelDetails;
}
