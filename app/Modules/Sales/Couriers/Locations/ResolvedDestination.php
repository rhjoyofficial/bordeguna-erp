<?php

namespace App\Modules\Sales\Couriers\Locations;

/**
 * A place the courier can be told about by its own ids, from a map a person
 * approved or that matched by equal name. Booking sends exactly these.
 */
final class ResolvedDestination
{
    public function __construct(
        public readonly string $provider,
        public readonly string $district,
        public readonly string $thana,
        public readonly ?string $area,
        public readonly string $cityId,
        public readonly string $cityName,
        public readonly string $zoneId,
        public readonly string $zoneName,
        public readonly ?string $areaId,
        public readonly ?string $areaName,
        public readonly ?string $bandCode,
        public readonly ?string $bandName,
        public readonly int $mapId,
        public readonly string $source,
    ) {}

    public function resolved(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this) + ['resolved' => true];
    }
}
