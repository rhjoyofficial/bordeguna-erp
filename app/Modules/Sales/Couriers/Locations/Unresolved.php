<?php

namespace App\Modules\Sales\Couriers\Locations;

/**
 * Why a place cannot be sent to a courier yet, in a code a booking screen can
 * branch on and a sentence a person can act on. Booking stops here; it never
 * falls back to letting the courier read the address text.
 */
final class Unresolved
{
    /** The district or thana is not one BdGeo knows. */
    public const UNKNOWN_PLACE = 'unknown_place';

    /** No map for this thana at this courier. */
    public const NOT_MAPPED = 'not_mapped';

    /** A suggested map exists and waits for a person. */
    public const AWAITING_REVIEW = 'awaiting_review';

    /** The map points at a city, zone or area the courier no longer lists. */
    public const LOCATION_REMOVED = 'location_removed';

    /** The mapped area says the courier does not deliver to homes there. */
    public const NO_HOME_DELIVERY = 'no_home_delivery';

    public function __construct(
        public readonly string $provider,
        public readonly string $reason,
        public readonly string $message,
        public readonly ?int $mapId = null,
    ) {}

    public function resolved(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this) + ['resolved' => false];
    }
}
