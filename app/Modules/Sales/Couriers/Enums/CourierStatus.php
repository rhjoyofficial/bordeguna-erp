<?php

namespace App\Modules\Sales\Couriers\Enums;

/**
 * Where the COURIER says a parcel is, in our own words (plan C.3). The
 * provider's raw word is always kept beside it.
 *
 * ⚠⚠ NOT MONEY. Only four of these (delivered, partially delivered, returned to
 * merchant, paid return) ever lead to money, and only through the existing
 * outcome action, confirmed by a person (ruling 1). A webhook is a notification,
 * not accounting evidence.
 */
enum CourierStatus: string
{
    case BookingRequested = 'booking_requested';
    case Booked = 'booked';
    case PickupAssigned = 'pickup_assigned';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case AtDeliveryHub = 'at_delivery_hub';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case PartiallyDelivered = 'partially_delivered';
    case DeliveryFailed = 'delivery_failed';
    case OnHold = 'on_hold';
    case Returning = 'returning';
    case ReturnedToMerchant = 'returned_to_merchant';
    case PaidReturn = 'paid_return';
    case Exchanged = 'exchanged';
    case Cancelled = 'cancelled';
    case Lost = 'lost';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::BookingRequested => 'Booking requested',
            self::Booked => 'Booked',
            self::PickupAssigned => 'Pickup assigned',
            self::PickedUp => 'Picked up',
            self::InTransit => 'In transit',
            self::AtDeliveryHub => 'At the delivery hub',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::PartiallyDelivered => 'Partly delivered',
            self::DeliveryFailed => 'Delivery failed',
            self::OnHold => 'On hold',
            self::Returning => 'Coming back',
            self::ReturnedToMerchant => 'Returned to us',
            self::PaidReturn => 'Paid return',
            self::Exchanged => 'Exchanged',
            self::Cancelled => 'Cancelled',
            self::Lost => 'Lost',
            self::Damaged => 'Damaged',
        };
    }

    /**
     * The courier is done with the parcel. A later word about it is recorded
     * on the timeline and moves nothing.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::Delivered, self::PartiallyDelivered, self::ReturnedToMerchant, self::PaidReturn,
            self::Exchanged, self::Cancelled, self::Lost, self::Damaged,
        ], true);
    }

    /**
     * Whether a word may still move a parcel that is already here. The one
     * exception to "final is final": the rest of a part delivery comes back.
     */
    public function accepts(self $next): bool
    {
        if (! $this->isFinal()) {
            return true;
        }

        return $this === self::PartiallyDelivered && in_array($next, [self::Returning, self::ReturnedToMerchant], true);
    }

    /**
     * The courier's answer a person should now confirm with the outcome form,
     * as that form's outcome value, or null when there is nothing to confirm.
     */
    public function outcomeToConfirm(): ?string
    {
        return match ($this) {
            self::Delivered => 'delivered',
            self::PartiallyDelivered => 'partially_delivered',
            self::ReturnedToMerchant, self::Cancelled => 'returned',
            self::PaidReturn => 'paid_return',
            default => null,
        };
    }

    /** Still before pickup: the courier can still cancel it. */
    public function cancellable(): bool
    {
        return in_array($this, [self::BookingRequested, self::Booked, self::PickupAssigned], true);
    }

    /**
     * @return list<string>
     */
    public static function finalValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isFinal())));
    }
}
