<?php

namespace App\Modules\Sales\Enums;

/**
 * Where a parcel handed to a courier stands.
 *
 * ⚠ BOOKED IS THE ONLY OPEN STATE. The other three are the courier's answer and
 * each is final: a parcel sent again after a return is a new challan.
 */
enum ConsignmentStatus: string
{
    case Booked = 'booked';
    case Delivered = 'delivered';
    case PartiallyDelivered = 'partially_delivered';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'With the courier',
            self::Delivered => 'Delivered',
            self::PartiallyDelivered => 'Partly delivered',
            self::Returned => 'Returned',
        };
    }

    /**
     * The three answers a courier can give.
     *
     * @return list<self>
     */
    public static function outcomes(): array
    {
        return [self::Delivered, self::PartiallyDelivered, self::Returned];
    }
}
