<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\ScheduleAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a schedule to a scope using close-old-open-new (SCH-08: temporary
 * schedules are new effective-dated records, never edits to history).
 *
 * A plain assignment closes whatever that scope had and opens the new one. A
 * temporary assignment (one with an end date, e.g. Ramadan hours) additionally
 * opens a follow-on record restoring what was in place before, the day after it
 * ends — so HR sets Ramadan once instead of remembering to undo it, and the
 * three records together still read as an unedited history.
 */
class AssignSchedule
{
    /**
     * @param  array<string, mixed>  $data  schedule_type/shift_id/holiday_calendar_id/effective_from/effective_to/note
     *
     * @throws ValidationException
     */
    public function handle(ScheduleScope $scope, ?int $scopeId, array $data): ScheduleAssignment
    {
        $from = Carbon::parse($data['effective_from'])->startOfDay();
        $until = isset($data['effective_to']) && $data['effective_to'] !== null
            ? Carbon::parse($data['effective_to'])->startOfDay()
            : null;

        if ($until !== null && $until->lt($from)) {
            throw ValidationException::withMessages([
                'effective_to' => 'The end date must be on or after the start date.',
            ]);
        }

        $this->guardLaterAssignment($scope, $scopeId, $from);

        return DB::transaction(function () use ($scope, $scopeId, $data, $from, $until) {
            $previous = $this->currentFor($scope, $scopeId, $from);

            // Only an open-ended predecessor is worth restoring — one that was
            // already scheduled to end has its own successor or none by design.
            $restorable = $previous !== null && $previous->effective_to === null ? $previous : null;

            $previous?->update(['effective_to' => $from->copy()->subDay()]);

            $assignment = ScheduleAssignment::create([
                'scope_type' => $scope,
                'scope_id' => $scope->needsTarget() ? $scopeId : null,
                'schedule_type' => $data['schedule_type'],
                'shift_id' => $data['shift_id'] ?? null,
                'holiday_calendar_id' => $data['holiday_calendar_id'] ?? null,
                'effective_from' => $from,
                'effective_to' => $until,
                'is_temporary' => $until !== null,
                'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ]);

            if ($until !== null && $restorable !== null) {
                $this->restoreAfter($assignment, $restorable, $until);
            }

            return $assignment;
        });
    }

    /**
     * Ends an assignment as of a date, without opening a replacement.
     */
    public function end(ScheduleAssignment $assignment, string $date): ScheduleAssignment
    {
        $on = Carbon::parse($date)->startOfDay();

        if ($on->lt(Carbon::parse($assignment->effective_from))) {
            throw ValidationException::withMessages([
                'effective_to' => 'A schedule cannot end before it starts.',
            ]);
        }

        $assignment->update(['effective_to' => $on]);

        return $assignment;
    }

    /**
     * Re-opens what the scope had before a temporary schedule took over.
     */
    private function restoreAfter(ScheduleAssignment $temporary, ScheduleAssignment $previous, Carbon $until): void
    {
        ScheduleAssignment::create([
            'scope_type' => $temporary->scope_type,
            'scope_id' => $temporary->scope_id,
            'schedule_type' => $previous->schedule_type,
            'shift_id' => $previous->shift_id,
            'holiday_calendar_id' => $previous->holiday_calendar_id,
            'effective_from' => $until->copy()->addDay(),
            'effective_to' => null,
            'restores_assignment_id' => $temporary->id,
            'note' => 'Restored after '.($temporary->note ?: 'temporary schedule'),
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * The row this scope is running on as of the new start date.
     */
    private function currentFor(ScheduleScope $scope, ?int $scopeId, Carbon $from): ?ScheduleAssignment
    {
        return ScheduleAssignment::forScope($scope, $scopeId)
            ->activeOn($from)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Closing the current row would silently leave an already-planned future
     * schedule stranded in front of the new one, so refuse instead of guessing.
     */
    private function guardLaterAssignment(ScheduleScope $scope, ?int $scopeId, Carbon $from): void
    {
        $later = ScheduleAssignment::forScope($scope, $scopeId)
            ->whereDate('effective_from', '>', $from->toDateString())
            ->orderBy('effective_from')
            ->first();

        if ($later !== null) {
            throw ValidationException::withMessages([
                'effective_from' => 'This scope already has a schedule starting '
                    .Carbon::parse($later->effective_from)->toDateString()
                    .'. Remove it first, or start this one after that date.',
            ]);
        }
    }
}
