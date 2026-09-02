<?php

namespace App\Support;

use App\Modules\Foundation\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * A record's own story, on its own page (Module 1, Slice 4; master plan §32).
 *
 * Assembled READ-ONLY from two things the system already keeps: the activity
 * log (every master-field change, with before/after) and the approval
 * requests + decisions on the record. The timeline writes nothing and invents
 * nothing - if an event is not on record, it is not in the story. That is
 * also why there is no "timeline table": a second copy of history is a second
 * copy that drifts.
 *
 * Lives at the app level beside ApprovalInbox for the same reason it does:
 * it must know the approval engine and stays agnostic of which module's
 * record it is telling the story of.
 *
 * @return array<int, array{when: string, when_human: string, who: ?string, what: string, note: ?string}>
 */
class RecordTimeline
{
    public static function for(Model $record, int $limit = 30): array
    {
        $entries = collect();

        // 1. The activity log: created / updated, with which fields changed.
        $activities = Activity::query()
            ->where('subject_type', get_class($record))
            ->where('subject_id', $record->getKey())
            ->with('causer')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        foreach ($activities as $activity) {
            $changed = array_keys($activity->properties['attributes'] ?? []);

            $what = match ($activity->event ?? $activity->description) {
                'created' => 'Created',
                'deleted' => 'Deleted',
                default => $changed === []
                    ? ucfirst((string) ($activity->event ?? $activity->description))
                    : 'Changed '.implode(', ', array_map(
                        fn (string $field) => str_replace('_', ' ', $field),
                        array_slice($changed, 0, 6),
                    )).(count($changed) > 6 ? ' and '.(count($changed) - 6).' more' : ''),
            };

            $entries->push(self::entry($activity->created_at, $activity->causer?->name, $what, null));
        }

        // 2. The approval story: submission, then each recorded decision with
        // its stage and note. Withdrawn/expired states read from the request.
        $requests = ApprovalRequest::query()
            ->where('approvable_type', get_class($record))
            ->where('approvable_id', $record->getKey())
            ->with(['requestedBy:id,name', 'decisions.decidedBy:id,name'])
            ->get();

        foreach ($requests as $request) {
            $entries->push(self::entry(
                $request->created_at,
                $request->requestedBy?->name,
                'Submitted for approval',
                null,
            ));

            foreach ($request->decisions as $decision) {
                $entries->push(self::entry(
                    $decision->decided_at ?? $decision->created_at,
                    $decision->decidedBy?->name,
                    ucfirst($decision->decision instanceof \BackedEnum ? $decision->decision->value : (string) $decision->decision)
                        .($decision->stage_name ? ' ('.$decision->stage_name.')' : ''),
                    $decision->note,
                ));
            }
        }

        return $entries
            ->sortByDesc('when')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array{when: string, when_human: string, who: ?string, what: string, note: ?string} */
    private static function entry(?Carbon $when, ?string $who, string $what, ?string $note): array
    {
        $when ??= Carbon::now();

        return [
            'when' => $when->toIso8601String(),
            'when_human' => $when->diffForHumans(),
            'who' => $who,
            'what' => $what,
            'note' => $note,
        ];
    }
}
