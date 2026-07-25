<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Foundation\Models\Office;

/**
 * Office geofencing for self-service clock-in (ATT-05 / Correction C2).
 *
 * The rule Joy approved is deliberately permissive: a punch outside the fence
 * is **accepted and flagged for review**, never rejected. Losing a real
 * attendance record because someone stood twenty metres too far away costs
 * more than reviewing an exception, and "geofence not used" is not a distinct
 * system state — it is simply an office with enforcement switched off.
 */
class Geofence
{
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Whether this punch should be flagged, and why. Never returns "reject".
     *
     * @return array{outside: bool, reason: string|null}
     */
    public static function evaluate(?Office $office, ?float $latitude, ?float $longitude): array
    {
        if ($office === null || ! $office->geofence_enforced) {
            return ['outside' => false, 'reason' => null];
        }

        if ($office->geofence_latitude === null || $office->geofence_longitude === null || $office->geofence_radius_meters === null) {
            // Enforcement switched on but never configured — flag it rather than
            // silently passing everyone, so the misconfiguration surfaces.
            return ['outside' => false, 'reason' => 'Geofence enforced but not configured for this office.'];
        }

        if ($latitude === null || $longitude === null) {
            return ['outside' => true, 'reason' => 'No location provided while the office geofence is enforced.'];
        }

        $distance = self::distanceInMeters(
            $latitude,
            $longitude,
            (float) $office->geofence_latitude,
            (float) $office->geofence_longitude,
        );

        if ($distance <= $office->geofence_radius_meters) {
            return ['outside' => false, 'reason' => null];
        }

        return [
            'outside' => true,
            'reason' => sprintf(
                'Clocked in %s m from %s (allowed %s m).',
                number_format($distance),
                $office->name,
                number_format($office->geofence_radius_meters),
            ),
        ];
    }

    /**
     * Great-circle distance. Haversine is accurate well past any sane office
     * radius and needs no external service.
     */
    public static function distanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
