<?php

namespace App\Modules\Crm\Actions;

use App\Models\User;
use App\Modules\Crm\Corrections\CrmActivityActionPolicy;
use App\Modules\Crm\Enums\ActivityDirection;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Exceptions\StaleCorrectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ The author fixes a call, visit, meeting, message or note (corrections
 * wave 3, 24 Oct 2026; FINAL-PLAN 3.2):
 *
 *   edit()     within 24 hours of typing it: changed in place, the before and
 *              after kept in History, an "Edited" mark on the entry
 *   correct()  after that: a NEW entry with the right account, the old one
 *              kept, struck through, with the reason
 *   delete()   within 24 hours: to the Recycle bin, with the reason
 *
 * ⚠ What may change is the account of what happened: when, which way, the
 * one line, the notes, the outcome and a call's length. The KIND of entry, a
 * visit's location and its photos are proof and stay as recorded; a wrong
 * kind is deleted (within the day) or corrected by a new entry of the right
 * kind, recorded the usual way.
 *
 * ⚠ The same rules as recording one (LogCrmActivity): nothing in the future,
 * one line always, no direction on a note, a length only on a call.
 *
 * ⚠ Every path asks CrmActivityActionPolicy again under the row lock, so a
 * double click finds the entry already corrected or binned and is refused,
 * and `seen` (the updated_at the screen showed) refuses a stale form.
 */
class ChangeCrmActivity
{
    public const FIELDS = ['occurred_at', 'direction', 'summary', 'body', 'outcome', 'duration_seconds'];

    public function __construct(private readonly CrmActivityActionPolicy $policy) {}

    /**
     * @param  array<string, mixed>  $input  the FIELDS, as validated by the controller
     *
     * @throws ValidationException
     */
    public function edit(CrmActivity $activity, array $input, User $by, ?string $seen = null): CrmActivity
    {
        return DB::transaction(function () use ($activity, $input, $by, $seen) {
            $current = $this->lock($activity, $by, DocumentAction::Edit, $seen);
            $fields = $this->checked($current, $input);

            $before = $current->only(array_keys($fields));
            $current->forceFill($fields + ['edited_at' => now(), 'edited_by' => $by->id]);
            $changed = array_keys(array_diff_key($current->getDirty(), array_flip(['edited_at', 'edited_by'])));

            if ($changed === []) {
                return $current;
            }

            CrmActivity::unlocked(fn () => $current->save());

            CorrectionLog::event('updated', $by, $current, [
                'old' => $this->plain(array_intersect_key($before, array_flip($changed))),
                'attributes' => $this->plain($current->only($changed)),
            ]);

            return $current;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function correct(CrmActivity $activity, array $input, User $by, ActionReason $reason, ?string $seen = null): CrmActivity
    {
        return DB::transaction(function () use ($activity, $input, $by, $reason, $seen) {
            $current = $this->lock($activity, $by, DocumentAction::Correct, $seen);
            $fields = $this->checked($current, $input);

            // The right account, as a new entry about the same lead or
            // customer, with the same kind and the same proof of place.
            $successor = new CrmActivity;
            $successor->forceFill([
                'business_id' => $current->business_id,
                'subject_type' => $current->subject_type,
                'subject_id' => $current->subject_id,
                'type' => $current->type,
                'latitude' => $current->latitude,
                'longitude' => $current->longitude,
                'gps_accuracy_meters' => $current->gps_accuracy_meters,
                'recorded_by' => $by->id,
                'correction_of_id' => $current->id,
            ] + $fields)->save();

            CrmActivity::unlocked(fn () => $current->forceFill([
                'corrected_at' => now(),
                'corrected_by' => $by->id,
                'correction_reason' => $reason->display(),
            ])->save());

            $properties = ['reason' => $reason->display(), 'successor_id' => $successor->id];
            // ⚠ Not 'corrected' and 'correction_of': History reads those from
            // the money correction links and skips the log lines of the same name.
            CorrectionLog::event('replaced_by_a_correction', $by, $current, $properties);
            CorrectionLog::event('corrects_an_earlier_entry', $by, $successor, ['reason' => $reason->display(), 'original_id' => $current->id]);

            return $successor;
        });
    }

    /**
     * @throws ValidationException
     */
    public function delete(CrmActivity $activity, User $by, ActionReason $reason): void
    {
        DB::transaction(function () use ($activity, $by, $reason) {
            $current = $this->lock($activity, $by, DocumentAction::Delete, null);

            CrmActivity::unlocked(fn () => $current->recycle($by, $reason));
        });
    }

    /**
     * @throws ValidationException
     */
    private function lock(CrmActivity $activity, User $by, DocumentAction $action, ?string $seen): CrmActivity
    {
        $current = CrmActivity::query()->whereKey($activity->id)->lockForUpdate()->first();

        if ($current === null) {
            throw ValidationException::withMessages(['activity' => 'This entry is no longer there. It may have been deleted a moment ago.']);
        }

        $availability = $this->policy->availability($current->loadMissing('recordedBy:id,name'), $by, $action);

        if (! $availability->allowed) {
            throw ValidationException::withMessages(['activity' => $availability->blockers[0]->message]);
        }

        if ($seen !== null && $seen !== '' && $seen !== $current->updated_at?->toDateTimeString()) {
            throw ValidationException::withMessages(['stale' => StaleCorrectionException::MESSAGE]);
        }

        return $current;
    }

    /**
     * LogCrmActivity's rules, for the fields that may change.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checked(CrmActivity $activity, array $input): array
    {
        $type = $activity->type;
        $occurred = filled($input['occurred_at'] ?? null) ? Carbon::parse($input['occurred_at']) : $activity->occurred_at;

        if ($occurred !== null && $occurred->isFuture()) {
            throw ValidationException::withMessages([
                'occurred_at' => 'That is in the future. Something that has not happened yet is a follow-up, not an activity.',
            ]);
        }

        $summary = trim((string) ($input['summary'] ?? ''));

        if ($summary === '') {
            throw ValidationException::withMessages(['summary' => 'Say in one line what happened.']);
        }

        $duration = $type->hasDuration() && filled($input['duration_seconds'] ?? null) ? (int) $input['duration_seconds'] : null;

        if ($duration !== null && $duration < 0) {
            throw ValidationException::withMessages(['duration_seconds' => 'A call cannot last less than no time.']);
        }

        $direction = $type->hasDirection() && filled($input['direction'] ?? null)
            ? ActivityDirection::from((string) $input['direction'])
            : null;

        return [
            'occurred_at' => $occurred,
            'direction' => $direction,
            'summary' => $summary,
            'body' => filled($input['body'] ?? null) ? (string) $input['body'] : null,
            'outcome' => filled($input['outcome'] ?? null) ? (string) $input['outcome'] : null,
            'duration_seconds' => $duration,
        ];
    }

    /**
     * For History: dates and enums as the words a person reads.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plain(array $values): array
    {
        return array_map(fn ($value) => match (true) {
            $value instanceof \DateTimeInterface => $value->format('d/m/Y H:i'),
            $value instanceof \BackedEnum => $value->value,
            default => $value,
        }, $values);
    }
}
