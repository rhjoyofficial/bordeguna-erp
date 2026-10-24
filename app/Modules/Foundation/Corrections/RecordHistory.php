<?php

namespace App\Modules\Foundation\Corrections;

use App\Models\User;
use App\Modules\Foundation\Corrections\Contracts\RedactsHistory;
use App\Modules\Foundation\Models\ClosedPeriodException;
use App\Modules\Foundation\Models\CorrectionLink;
use App\Modules\Foundation\Models\CorrectionRequest;
use App\Modules\Foundation\Models\DuplicateAcknowledgement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * A record's History tab (PHASE-0-CONTRACT 1.13): READ-ONLY, invents nothing.
 *
 * Merges the activity log (before and after values, redacted), the correction
 * links in both directions, the correction requests and their decisions, the
 * closed-month exceptions and the duplicate acknowledgements.
 *
 * ⚠ Nothing is back-filled (ChatGPT REPLY-0 3.10). When the log starts after the
 * record was created, `gap_notice` says so in a sentence instead of pretending.
 */
final class RecordHistory
{
    private const SKIPPED_EVENTS = ['corrected', 'reversed', 'correction_of', 'requested'];

    /** @return array{entries: list<array>, recorded_since: ?string, gap_notice: ?string} */
    public static function for(Model $record, User $viewer): array
    {
        $hidden = $record instanceof RedactsHistory ? $record->historyHidden($viewer) : [];
        $hidden = [...$hidden, 'password', 'remember_token', 'secret_hash'];
        $redact = fn (?array $values) => $values === null ? null : array_diff_key($values, array_flip($hidden));
        $type = $record->getMorphClass();
        $id = $record->getKey();
        $registry = app(DocumentPolicyRegistry::class);

        $entries = collect();

        $activities = Activity::query()
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->with('causer')
            ->orderBy('id')
            ->get();

        foreach ($activities as $activity) {
            $event = (string) ($activity->event ?? $activity->description);

            if (in_array($event, self::SKIPPED_EVENTS, true)) {
                continue;
            }

            $after = $redact($activity->properties['attributes'] ?? null);
            $before = $redact($activity->properties['old'] ?? null);

            [$kind, $summary] = match ($event) {
                'created' => ['created', 'Created'],
                'updated' => ['changed', 'Changed '.self::fields($after)],
                'deleted' => ['deleted', 'Moved to the Recycle bin'],
                'restored' => ['restored', 'Restored from the Recycle bin'],
                'blocked_attempt' => ['blocked_attempt', 'Tried and refused: '.($activity->properties['message'] ?? '')],
                default => [$event, ucfirst(str_replace('_', ' ', $event))],
            };

            if ($kind === 'changed' && $after === []) {
                continue;
            }

            $entries->push(new HistoryEntry($activity->created_at, $activity->causer?->name, $kind, $summary, $before, $after, $activity->properties['reason'] ?? null));
        }

        foreach (CorrectionLink::query()->where('original_type', $type)->where('original_id', $id)->with(['actor:id,name', 'approver:id,name', 'reason', 'reversalJournal:id,journal_number,journal_date', 'successorJournal:id,journal_number'])->get() as $link) {
            // ⭐ 24 Oct 2026: a link with no live key issued a document against
            // this one and left it live (a price credit, an extra charge).
            $verb = match (true) {
                $link->live_key === null => "{$link->successor_number} issued",
                $link->successor_id === null => 'Reversed',
                default => 'Corrected',
            };
            $links = array_values(array_filter([
                $link->reversalJournal ? ['label' => $link->reversalJournal->journal_number, 'url' => self::dayBookUrl($link->reversalJournal->journal_date)] : null,
                $link->successor_number ? ['label' => $link->successor_number, 'url' => self::urlOf($registry, $link->successor_type, (int) $link->successor_id)] : null,
            ]));

            $entries->push(new HistoryEntry(
                $link->created_at,
                $link->actor?->name,
                match (true) {
                    $link->live_key === null => 'issued',
                    $link->successor_id === null => 'reversed',
                    default => 'corrected',
                },
                "{$verb} by {$link->actor?->name}".($link->approver ? ", approved by {$link->approver->name}" : '')
                    .($link->closed_period_exception ? ', as a closed-month exception' : '').': '.$link->reasonDisplay(),
                reason: $link->reasonDisplay(),
                links: $links,
            ));
        }

        foreach (CorrectionLink::query()->where('successor_type', $type)->where('successor_id', $id)->with('actor:id,name')->get() as $link) {
            $entries->push(new HistoryEntry(
                $link->created_at,
                $link->actor?->name,
                'correction_of',
                "Correction of {$link->original_number}",
                reason: $link->reasonDisplay(),
                links: [['label' => $link->original_number, 'url' => self::urlOf($registry, $link->original_type, (int) $link->original_id)]],
            ));
        }

        foreach (CorrectionRequest::query()->where('document_type', $type)->where('document_id', $id)->with(['requestedBy:id,name', 'approvalRequest.decisions.decidedBy:id,name', 'reason'])->get() as $request) {
            $entries->push(new HistoryEntry($request->created_at, $request->requestedBy?->name, 'requested', $request->summary().', sent for approval', reason: $request->reasonDisplay()));

            foreach ($request->approvalRequest?->decisions ?? [] as $decision) {
                $entries->push(new HistoryEntry(
                    Carbon::parse($decision->decided_at ?? $decision->created_at),
                    $decision->decidedBy?->name,
                    'request_decided',
                    'Request '.(is_object($decision->decision) ? $decision->decision->value : $decision->decision).($request->status === CorrectionRequestStatus::FailedStale ? ', but nothing was posted: '.$request->failure_message : ''),
                    reason: $decision->note,
                ));
            }
        }

        foreach (ClosedPeriodException::query()->where('original_type', $type)->where('original_id', $id)->with('grantedBy:id,name')->get() as $exception) {
            $entries->push(new HistoryEntry($exception->created_at, $exception->grantedBy?->name, 'closed_period_exception',
                'Closed-month exception for '.implode(', ', $exception->dates ?? []).", {$exception->journals_posted} journal(s), {$exception->status}"));
        }

        foreach (DuplicateAcknowledgement::query()->where('subject_type', $type)->where('subject_id', $id)->with('user:id,name')->get() as $ack) {
            $numbers = collect($ack->candidates ?? [])->pluck('number')->filter()->implode(', ');
            $entries->push(new HistoryEntry($ack->created_at, $ack->user?->name, 'duplicate_ack', "Saved although it looked like {$numbers}"));
        }

        $first = $activities->first()?->created_at;
        $created = $record->created_at ?? null;
        $gap = null;

        if ($created !== null && ($first === null || $first->gt(Carbon::parse($created)->addMinute()))) {
            $since = ($first ?? now())->format('j M Y');
            $gap = "History before {$since} was not recorded for this record.";
        }

        return [
            'entries' => self::sorted($entries),
            'recorded_since' => $first?->toDateString(),
            'gap_notice' => $gap,
        ];
    }

    /** @param Collection<int, HistoryEntry> $entries */
    private static function sorted(Collection $entries): array
    {
        return $entries->sortByDesc(fn (HistoryEntry $e) => $e->at->getTimestamp())->values()->map(fn (HistoryEntry $e) => $e->toArray())->all();
    }

    private static function fields(?array $after): string
    {
        $names = array_map(fn (string $f) => str_replace('_', ' ', $f), array_keys($after ?? []));

        return implode(', ', array_slice($names, 0, 6)).(count($names) > 6 ? ' and '.(count($names) - 6).' more' : '');
    }

    private static function urlOf(DocumentPolicyRegistry $registry, ?string $morph, int $id): ?string
    {
        if ($morph === null || ($type = $registry->typeOfMorph($morph)) === null) {
            return null;
        }

        $class = $registry->for($type)->modelClass();
        $model = $class::query()->find($id);

        return $model === null ? null : $registry->for($type)->urlOf($model);
    }

    private static function dayBookUrl(mixed $date): ?string
    {
        if ($date === null || ! app('router')->has('accounting.day-book')) {
            return null;
        }

        $day = Carbon::parse($date)->toDateString();

        return route('accounting.day-book', ['from' => $day, 'to' => $day]);
    }
}
