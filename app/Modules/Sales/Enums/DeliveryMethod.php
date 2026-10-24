<?php

namespace App\Modules\Sales\Enums;

/**
 * ⭐ B18 (Joy's initial plan R93, V19, 25 Oct 2026): how the goods reach the
 * customer, in Joy's words "assign the order to any courier service, or instant
 * delivery using courier or human charge customizable, also hand delivery ...
 * but courier charge a fixed other methods are not."
 *
 * ⚠ The charge rule is the whole point of the enum:
 * - Courier: the zone charge, fixed. Only a Manager or the Owner changes it.
 * - Rider (instant delivery by a courier rider): typed, any figure.
 * - Own staff (our own person carries it): typed, or zero.
 * - Hand (picked up, or handed over): no charge, no address.
 *
 * ⚠ Null on an order typed before this slice: decided at the challan.
 */
enum DeliveryMethod: string
{
    case Courier = 'courier';
    case Rider = 'rider';
    case OwnStaff = 'own_staff';
    case Hand = 'hand';

    public function label(): string
    {
        return match ($this) {
            self::Courier => 'Courier',
            self::Rider => 'Instant delivery (rider)',
            self::OwnStaff => 'Our own person',
            self::Hand => 'Hand delivery / pick-up',
        };
    }

    /** May anybody type the charge? Courier: no, it is the zone's. Hand: there is none. */
    public function chargeIsTyped(): bool
    {
        return $this === self::Rider || $this === self::OwnStaff;
    }

    /** Does a parcel travel to an address? */
    public function needsAddress(): bool
    {
        return $this !== self::Hand;
    }
}
