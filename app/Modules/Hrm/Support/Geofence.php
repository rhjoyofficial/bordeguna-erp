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
 *
 * ⚠⚠ THE DISTANCE IS MEASURED EVEN WHEN NOBODY IS FLAGGED. Tracking and
 * policing are two different questions, and conflating them is what left Joy
 * unable to answer "where did this punch come from" - the evaluator used to
 * return on the first line for an unenforced office, so a location the browser
 * had already supplied was thrown away. It is now always computed and always
 * returned; `outside` only decides whether a human is asked about it.
 *
 * ⚠⚠ THIS KNOWS NOTHING ABOUT WHO MAY USE THE WEB CLOCK. Permission to clock in
 * from a browser is a separate question, settled by HR on the employee page and
 * enforced at the clock endpoint (Joy, 27 Aug 2026). It briefly lived in here as
 * a flag-suppressing argument and that was wrong: whether a punch happened where
 * it should have is not the same question as whether the person was allowed to
 * punch at all, and merging them meant switching one on silently switched the
 * other off.
 */
class Geofence
{
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Whether this punch should be flagged, why, and how far away it was.
     * Never returns "reject".
     *
     * @return array{outside: bool, reason: string|null, distance: float|null}
     */
    public static function evaluate(?Office $office, ?float $latitude, ?float $longitude): array
    {
        $distance = self::distanceFrom($office, $latitude, $longitude);

        if ($office === null || ! $office->geofence_enforced) {
            return ['outside' => false, 'reason' => null, 'distance' => $distance];
        }

        if ($office->geofence_latitude === null || $office->geofence_longitude === null || $office->geofence_radius_meters === null) {
            // Enforcement switched on but never configured — flag it rather than
            // silently passing everyone, so the misconfiguration surfaces.
            return [
                'outside' => false,
                'reason' => 'Geofence enforced but not configured for this office.',
                'distance' => $distance,
            ];
        }

        if ($latitude === null || $longitude === null) {
            return [
                'outside' => true,
                'reason' => 'No location provided while the office geofence is enforced.',
                'distance' => null,
            ];
        }

        if ($distance !== null && $distance <= $office->geofence_radius_meters) {
            return ['outside' => false, 'reason' => null, 'distance' => $distance];
        }

        return [
            'outside' => true,
            'reason' => sprintf(
                'Clocked in %s m from %s (allowed %s m).',
                number_format((float) $distance),
                $office->name,
                number_format($office->geofence_radius_meters),
            ),
            'distance' => $distance,
        ];
    }

    /**
     * How far this punch was from its office, or null if either end is unknown.
     *
     * ⚠ Deliberately independent of whether enforcement is switched on. Joy's
     * question was "where did they clock in from", and that has an answer even
     * when nobody is policing it.
     */
    public static function distanceFrom(?Office $office, ?float $latitude, ?float $longitude): ?float
    {
        if ($office === null || $latitude === null || $longitude === null) {
            return null;
        }

        if ($office->geofence_latitude === null || $office->geofence_longitude === null) {
            return null;
        }

        return self::distanceInMeters(
            $latitude,
            $longitude,
            (float) $office->geofence_latitude,
            (float) $office->geofence_longitude,
        );
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
