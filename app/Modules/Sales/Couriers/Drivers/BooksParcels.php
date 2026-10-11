<?php

namespace App\Modules\Sales\Couriers\Drivers;

use App\Modules\Sales\Couriers\Booking\BookingResult;
use App\Modules\Sales\Couriers\Booking\CancelResult;
use App\Modules\Sales\Couriers\Booking\ParcelBooking;
use App\Modules\Sales\Couriers\Booking\ParcelDetails;
use App\Modules\Sales\Couriers\Models\CourierAccount;

/**
 * A courier the ERP can book a parcel with, ask about one, and cancel one.
 *
 * ⚠⚠ book() IS CALLED ONCE PER INTENT ROW AND NEVER RETRIED INSIDE THE DRIVER.
 * A timeout is UNKNOWN, not a reason to send it again (ruling 13).
 */
interface BooksParcels
{
    public function book(CourierAccount $account, ParcelBooking $parcel): BookingResult;

    public function details(CourierAccount $account, string $consignmentId): ParcelDetails;

    public function cancel(CourierAccount $account, string $consignmentId, string $reason): CancelResult;
}
