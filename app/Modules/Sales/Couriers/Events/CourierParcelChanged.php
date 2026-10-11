<?php

namespace App\Modules\Sales\Couriers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A parcel was booked, cancelled, or the courier moved it. Type and ids only:
 * a listener (the dashboard slice) re-reads what it needs through its own
 * authorised query, so nothing about the customer rides on the event.
 */
class CourierParcelChanged
{
    use Dispatchable;

    public const BOOKED = 'booked';

    public const STATUS = 'status';

    public const CANCELLED = 'cancelled';

    public const BOOKING_UNKNOWN = 'booking_unknown';

    public const BOOKING_FAILED = 'booking_failed';

    public function __construct(
        public readonly string $type,
        public readonly int $businessId,
        public readonly int $deliveryId,
        public readonly ?int $consignmentId = null,
        public readonly ?string $courierStatus = null,
    ) {}
}
