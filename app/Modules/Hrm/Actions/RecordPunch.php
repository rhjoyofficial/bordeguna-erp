<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Support\Geofence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * The single write path for a raw punch, whatever captured it (ATT-01).
 *
 * Everything that records attendance goes through here — web/mobile clock-in,
 * HR manual entry and the CSV importer — so ATT-08's idempotency rule and
 * Correction C2's proof capture exist in one place rather than three
 * divergent copies.
 *
 * Returns null when the punch already exists. That is not a failure: a
 * re-imported export and a double-tapped clock-in button are both "already
 * recorded", and the caller decides how to report it.
 */
class RecordPunch
{
    /**
     * @param  array<string, mixed>  $attributes  direction/device_identifier/enroll_id/
     *                                            latitude/longitude/gps_accuracy_meters/
     *                                            ip_address/user_agent/device_metadata/
     *                                            note/import_batch_id
     */
    public function handle(Employee $employee, Carbon|string $punchedAt, PunchSource $source, array $attributes = []): ?AttendancePunch
    {
        $punchedAt = Carbon::parse($punchedAt)->startOfSecond();
        $device = $attributes['device_identifier'] ?? null;

        $dedupKey = AttendancePunch::dedupKey($employee->id, $punchedAt, $source, $device);

        if (AttendancePunch::where('dedup_key', $dedupKey)->exists()) {
            return null;
        }

        $direction = $attributes['direction'] ?? null;

        [$outsideGeofence, $reviewReason] = $this->reviewFlags($employee, $source, $attributes);

        return AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => $punchedAt,
            'source' => $source,
            'direction' => $direction instanceof PunchDirection ? $direction : PunchDirection::tryFrom((string) $direction),
            'device_identifier' => $device,
            'enroll_id' => $attributes['enroll_id'] ?? null,
            'latitude' => $attributes['latitude'] ?? null,
            'longitude' => $attributes['longitude'] ?? null,
            'gps_accuracy_meters' => $attributes['gps_accuracy_meters'] ?? null,
            'ip_address' => $attributes['ip_address'] ?? null,
            'user_agent' => $attributes['user_agent'] ?? null,
            'device_metadata' => $attributes['device_metadata'] ?? null,
            'is_outside_geofence' => $outsideGeofence,
            'needs_review' => $outsideGeofence || $reviewReason !== null,
            'review_reason' => $reviewReason,
            'dedup_key' => $dedupKey,
            'note' => $attributes['note'] ?? null,
            'import_batch_id' => $attributes['import_batch_id'] ?? null,
            'recorded_by' => $attributes['recorded_by'] ?? Auth::id(),
        ]);
    }

    /**
     * Capture-time facts only — these are never cleared later, because clearing
     * one would be an edit to an immutable record (ATT-03). Resolving an
     * exception is a Phase-2c correction.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: bool, 1: string|null}
     */
    private function reviewFlags(Employee $employee, PunchSource $source, array $attributes): array
    {
        if (! $source->requiresProof()) {
            return [false, null];
        }

        $office = $employee->currentAssignment()?->office;

        $verdict = Geofence::evaluate(
            $office,
            isset($attributes['latitude']) ? (float) $attributes['latitude'] : null,
            isset($attributes['longitude']) ? (float) $attributes['longitude'] : null,
        );

        return [$verdict['outside'], $verdict['reason']];
    }
}
