<?php

namespace App\Modules\Sales\Couriers\Booking;

/**
 * Everything a courier is told when a parcel is booked, already checked and
 * in the ERP's own units: grams, whole taka, an 11-digit phone, the courier's
 * own location ids from a reviewed map. Each driver turns it into its own
 * field names and units, and nothing else.
 */
final class ParcelBooking
{
    public function __construct(
        public readonly string $merchantOrderId,
        public readonly string $storeId,
        public readonly string $recipientName,
        public readonly string $recipientPhone,
        public readonly string $recipientAddress,
        public readonly string $cityId,
        public readonly string $zoneId,
        public readonly ?string $areaId,
        public readonly int $weightGrams,
        public readonly int $quantity,
        public readonly int $codTaka,
        public readonly int $deliveryType,
        public readonly int $itemType,
        public readonly string $description,
        public readonly ?string $specialInstruction,
    ) {}
}
