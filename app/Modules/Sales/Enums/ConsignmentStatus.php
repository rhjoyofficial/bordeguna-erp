<?php

namespace App\Modules\Sales\Enums;

/**
 * Where a parcel handed to a courier stands.
 *
 * ⚠ BOOKED IS THE ONLY OPEN STATE. The others are the courier's answer and
 * each is final: a parcel sent again after a return is a new challan.
 *
 * ⭐ Courier hub slice 8: a PAID RETURN (refused, but the customer paid the
 * delivery charge: goods back, the money is delivery income), and a parcel the
 * courier LOST or DAMAGED, closed by a loss claim rather than the outcome form.
 */
enum ConsignmentStatus: string
{
    case Booked = 'booked';
    case Delivered = 'delivered';
    case PartiallyDelivered = 'partially_delivered';
    case Returned = 'returned';
    case PaidReturn = 'paid_return';
    case Lost = 'lost';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'With the courier',
            self::Delivered => 'Delivered',
            self::PartiallyDelivered => 'Partly delivered',
            self::Returned => 'Returned',
            self::PaidReturn => 'Paid return',
            self::Lost => 'Lost by the courier',
            self::Damaged => 'Damaged by the courier',
        };
    }

    /**
     * The answers the outcome form records. A lost or damaged parcel is closed
     * by its loss claim instead.
     *
     * @return list<self>
     */
    public static function outcomes(): array
    {
        return [self::Delivered, self::PartiallyDelivered, self::Returned, self::PaidReturn];
    }

    /** The goods came back (or are written off) and nothing was invoiced. */
    public function bringsEverythingBack(): bool
    {
        return in_array($this, [self::Returned, self::PaidReturn, self::Lost, self::Damaged], true);
    }

    /** What the courier charges for it is a return fee, not a delivery fee. */
    public function isReturn(): bool
    {
        return in_array($this, [self::Returned, self::PaidReturn], true);
    }
}
