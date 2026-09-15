<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Enums\ActivityType;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that something happened.
 *
 * ⚠ Every rule about WHAT may be recorded lives here rather than in the
 * controller, because there is already a second caller in prospect (task
 * completion, and later a Meta ingestion) and a rule that lives in one screen is
 * a rule the next caller does not have.
 */
class LogCrmActivity
{
    /** Phone photos, re-encoded smaller by StoreAttachment before they are kept. */
    public const MAX_PHOTOS = 6;

    public function __construct(private readonly StoreAttachment $store) {}

    /**
     * @param  array{latitude: mixed, longitude: mixed, accuracy: mixed}|null  $location  where the phone said the officer was (a visit only)
     * @param  list<UploadedFile>  $photos  what he saw (a visit only)
     *
     * @throws ValidationException
     */
    public function handle(
        Model $subject,
        ActivityType $type,
        string $summary,
        User $recordedBy,
        mixed $occurredAt = null,
        ?ActivityDirection $direction = null,
        ?string $body = null,
        ?string $outcome = null,
        ?int $durationSeconds = null,
        ?array $location = null,
        array $photos = [],
    ): CrmActivity {
        $businessId = app(BusinessContext::class)->id();

        // ⚠⚠ Whitelist, not `morphTo` trusting whatever arrives. Without this a
        // request could attach an "activity" to a Journal or a Payslip and the
        // polymorphic column would happily store it.
        if (! in_array($subject::class, CrmActivity::SUBJECTS, true)) {
            throw ValidationException::withMessages([
                'subject' => 'An activity can only be recorded against a lead or a customer.',
            ]);
        }

        // ⚠ The subject must belong to the business doing the recording. The
        // global scope already hides other tenants' rows, so a mismatch here
        // means something bypassed it - fail loudly rather than write a row
        // pointing across the boundary.
        if ($subject->business_id !== $businessId) {
            throw ValidationException::withMessages([
                'subject' => 'That record belongs to a different business.',
            ]);
        }

        $occurred = $occurredAt === null ? now() : Carbon::parse($occurredAt);

        // ⚠⚠ C-CRMB-3. A thing that has not happened yet is a TASK, not an
        // activity. Without this, "call them next Tuesday" gets logged as a call
        // that already took place, and every contact report is wrong.
        if ($occurred->isFuture()) {
            throw ValidationException::withMessages([
                'occurred_at' => 'That is in the future. Something that has not happened yet is a follow-up, not an activity.',
            ]);
        }

        if (trim($summary) === '') {
            throw ValidationException::withMessages([
                'summary' => 'Say in one line what happened.',
            ]);
        }

        // ⚠ A note has no direction and a call is the only thing measured in
        // seconds. Silently storing either would produce reports that count
        // "inbound notes".
        if (! $type->hasDirection()) {
            $direction = null;
        }

        if (! $type->hasDuration()) {
            $durationSeconds = null;
        }

        if ($durationSeconds !== null && $durationSeconds < 0) {
            throw ValidationException::withMessages([
                'duration_seconds' => 'A call cannot last less than no time.',
            ]);
        }

        // ⭐ CRM slice 2d, Joy's answer 2: a location and photos are OPTIONAL
        // proof of a VISIT. ⚠ A location on any other kind is dropped, exactly
        // like a direction on a note: a stale form value must not put a phone
        // call on the map.
        if ($type !== ActivityType::Visit) {
            $location = null;
        }

        // ⚠ ...but photos on a call are REFUSED, not dropped. Somebody chose
        // those files, and throwing them away without a word loses their work.
        if ($photos !== [] && $type !== ActivityType::Visit) {
            throw ValidationException::withMessages([
                'photos' => 'Photos are recorded with a visit.',
            ]);
        }

        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'Up to '.self::MAX_PHOTOS.' photos on one visit.',
            ]);
        }

        $location = $location === null ? null : $this->checkedLocation($location);

        return DB::transaction(function () use (
            $businessId, $subject, $type, $direction, $occurred, $summary, $body,
            $outcome, $durationSeconds, $location, $recordedBy, $photos,
        ) {
            $activity = CrmActivity::create([
                'business_id' => $businessId,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'type' => $type,
                'direction' => $direction,
                'occurred_at' => $occurred,
                'summary' => trim($summary),
                'body' => $body,
                'outcome' => $outcome,
                'duration_seconds' => $durationSeconds,
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
                'gps_accuracy_meters' => $location['accuracy'] ?? null,
                'recorded_by' => $recordedBy->id,
            ]);

            foreach ($photos as $photo) {
                $this->store->handle($activity, $photo, CrmActivity::PHOTOS, $recordedBy);
            }

            return $activity;
        });
    }

    /**
     * ⚠ All three or none. A position without its accuracy cannot be judged (a
     * laptop's guess from its network can be kilometres out), and a latitude
     * without a longitude is not a place.
     *
     * @param  array<string, mixed>  $location
     * @return array{latitude: float, longitude: float, accuracy: float}
     *
     * @throws ValidationException
     */
    private function checkedLocation(array $location): array
    {
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;
        $accuracy = $location['accuracy'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng) || ! is_numeric($accuracy)) {
            throw ValidationException::withMessages([
                'latitude' => 'The location came through incomplete. Add it again, or record the visit without it.',
            ]);
        }

        if (abs((float) $lat) > 90 || abs((float) $lng) > 180 || (float) $accuracy < 0) {
            throw ValidationException::withMessages([
                'latitude' => 'That is not a place on the map. Add the location again.',
            ]);
        }

        return ['latitude' => (float) $lat, 'longitude' => (float) $lng, 'accuracy' => (float) $accuracy];
    }
}
