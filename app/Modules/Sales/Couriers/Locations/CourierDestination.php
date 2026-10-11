<?php

namespace App\Modules\Sales\Couriers\Locations;

use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Modules\Sales\Couriers\Models\CourierZoneBand;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use App\Support\BdGeo;

/**
 * The courier's own ids for a place in Bangladesh, or the reason there are
 * none. Booking (a later slice) calls this and nothing else.
 *
 * ⚠⚠ NEVER A GUESS. Only an `applied` map is read; a suggestion, a missing
 * map, or a map pointing at something the courier stopped listing answers
 * Unresolved, and the booking stops. Pathao, left to read the address text,
 * misrouted 6 of 11 real orders.
 *
 * ⚠ An area map (district, thana, area) wins over the whole-thana map when the
 * order names an area and one exists; otherwise the thana map decides.
 */
class CourierDestination
{
    public static function resolve(string $provider, ?string $district, ?string $thana, ?string $area = null): ResolvedDestination|Unresolved
    {
        $courier = app(CourierProviderRegistry::class)->has($provider) ? app(CourierProviderRegistry::class)->get($provider)->name() : $provider;
        $districtName = BdGeo::districtNamed($district);
        $thanaName = $districtName === null ? null : BdGeo::thanaNamed($districtName, $thana);

        if ($thanaName === null) {
            return new Unresolved($provider, Unresolved::UNKNOWN_PLACE, sprintf(
                '"%s, %s" is not a district and thana on the ERP list. Correct the address first.',
                (string) $thana,
                (string) $district,
            ));
        }

        $areaName = filled($area) ? (BdGeo::areaNamed($districtName, $thanaName, $area) ?? trim((string) $area)) : null;

        $maps = CourierLocationMap::query()
            ->where('provider', $provider)->where('district', $districtName)->where('thana', $thanaName)
            ->whereIn('area', array_values(array_unique([CourierLocationMap::WHOLE_THANA, (string) $areaName])))
            ->get();

        $areaMap = $areaName === null ? null : $maps->first(fn (CourierLocationMap $m) => $m->area === $areaName && $m->isApplied());
        $map = $areaMap ?? $maps->first(fn (CourierLocationMap $m) => $m->area === CourierLocationMap::WHOLE_THANA);

        if ($map === null) {
            return new Unresolved($provider, Unresolved::NOT_MAPPED, "{$thanaName}, {$districtName} has no {$courier} zone yet. Map it in Settings > Couriers > Locations.");
        }

        if (! $map->isApplied() || $map->provider_city_id === null || $map->provider_zone_id === null) {
            return new Unresolved($provider, Unresolved::AWAITING_REVIEW, "The {$courier} zone for {$thanaName}, {$districtName} is a suggestion nobody has approved yet.", $map->id);
        }

        $city = CourierLocation::lookup($provider, CourierLocation::CITY, $map->provider_city_id);
        $zone = CourierLocation::lookup($provider, CourierLocation::ZONE, $map->provider_zone_id);
        $areaRow = CourierLocation::lookup($provider, CourierLocation::AREA, $map->provider_area_id);

        $gone = match (true) {
            $city === null || $city->removed_at !== null => 'city',
            $zone === null || $zone->removed_at !== null || $zone->parent_provider_id !== $city->provider_location_id => 'zone',
            $map->provider_area_id !== null && ($areaRow === null || $areaRow->removed_at !== null || $areaRow->parent_provider_id !== $zone->provider_location_id) => 'area',
            default => null,
        };

        if ($gone !== null) {
            return new Unresolved($provider, Unresolved::LOCATION_REMOVED, "The {$gone} mapped for {$thanaName}, {$districtName} is no longer on the {$courier} list. Sync and map it again.", $map->id);
        }

        if ($areaRow !== null && ($areaRow->extra['home_delivery_available'] ?? true) === false) {
            return new Unresolved($provider, Unresolved::NO_HOME_DELIVERY, "{$courier} does not deliver to homes in {$areaRow->name}.", $map->id);
        }

        $band = CourierZoneBand::for($provider, $districtName, $thanaName);

        return new ResolvedDestination(
            provider: $provider,
            district: $districtName,
            thana: $thanaName,
            area: $areaName,
            cityId: (string) $city->provider_location_id,
            cityName: (string) $city->name,
            zoneId: (string) $zone->provider_location_id,
            zoneName: (string) $zone->name,
            areaId: $areaRow?->provider_location_id,
            areaName: $areaRow?->name,
            bandCode: $band?->code,
            bandName: $band?->name,
            mapId: $map->id,
            source: $map->source,
        );
    }
}
