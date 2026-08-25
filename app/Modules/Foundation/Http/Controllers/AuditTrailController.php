<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\ActivityLogEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who changed what, and when.
 *
 * ⚠⚠ THE TRAIL WAS ALREADY BEING WRITTEN AND NOBODY COULD READ IT. Accounts,
 * customers, suppliers, warehouses, money accounts, fixed assets, leads,
 * approvals and employees have all been logging every change since the day
 * each shipped, into a table with no screen over it. An audit trail nobody can
 * open is a cost with no benefit: it is the same unreachable-engine shape as
 * the five dead actions before it, at the scale of the whole system.
 *
 * ⚠ Gated on `foundation.organization.manage`, which is Owner today. The trail
 * carries what changed, and among those changes are supplier bank details and
 * pay. It sits with the organisation settings for exactly that reason, and
 * gets no softer permission of its own.
 *
 * ⚠ Read-only, with no delete anywhere. Evidence that can be tidied away by the
 * person it is evidence about is not evidence. Trimming old entries, if it is
 * ever needed, is a deliberate command with Joy's approval, never a button.
 */
class AuditTrailController extends Controller
{
    /**
     * How a model class is said out loud. Derived, so a module added later
     * appears without anybody remembering to edit a list.
     */
    private function label(?string $subjectType): string
    {
        if ($subjectType === null) {
            return 'Unknown';
        }

        return Str::headline(class_basename($subjectType));
    }

    public function index(Request $request): Response
    {
        $this->authorize('foundation.organization.manage');

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'type' => (string) $request->query('type', ''),
            'event' => (string) $request->query('event', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];

        $entries = ActivityLogEntry::query()
            ->with('causer:id,name')
            ->when($filters['type'] !== '', fn (Builder $q) => $q->where('subject_type', $filters['type']))
            ->when($filters['event'] !== '', fn (Builder $q) => $q->where('event', $filters['event']))
            ->when($filters['from'] !== '', fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->when($filters['search'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['search'].'%';

                // ⚠ `properties` is searched as text on purpose: it is where the
                // changed VALUES live, and "who set the credit limit to 50000"
                // is the question this screen exists to answer.
                $q->where(function (Builder $inner) use ($term) {
                    $inner->where('description', 'like', $term)
                        ->orWhere('properties', 'like', $term)
                        ->orWhereHas('causer', fn (Builder $causer) => $causer->where('name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ActivityLogEntry $entry) => [
                'id' => $entry->id,
                'at' => $entry->created_at?->timezone(config('app.timezone'))->format('j M Y, g:i a'),
                'event' => $entry->event ?? $entry->description,
                'subject' => $this->label($entry->subject_type),
                'subject_id' => $entry->subject_id,
                'causer' => $entry->causer?->name ?? 'System',
                'changes' => $this->changes($entry),
            ]);

        return Inertia::render('settings/audit', [
            'entries' => $entries,
            'filters' => $filters,
            // Only the types that actually appear, so the filter never offers a
            // choice that returns nothing.
            'types' => ActivityLogEntry::query()
                ->select('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')
                ->filter()
                ->map(fn (string $type) => ['value' => $type, 'label' => $this->label($type)])
                ->values(),
            'events' => ActivityLogEntry::query()
                ->select('event')->distinct()->orderBy('event')->pluck('event')->filter()->values(),
        ]);
    }

    /**
     * The old and new values, as a list a person can read.
     *
     * ⚠ `old` is absent on a creation and `attributes` is absent on a deletion,
     * so both sides are read defensively rather than assumed to pair up.
     *
     * @return list<array{field:string, from:?string, to:?string}>
     */
    private function changes(ActivityLogEntry $entry): array
    {
        $properties = $entry->properties?->toArray() ?? [];
        $new = $properties['attributes'] ?? [];
        $old = $properties['old'] ?? [];

        $fields = array_unique(array_merge(array_keys($new), array_keys($old)));
        sort($fields);

        $changes = [];

        foreach ($fields as $field) {
            $changes[] = [
                'field' => Str::headline($field),
                'from' => $this->readable($old[$field] ?? null),
                'to' => $this->readable($new[$field] ?? null),
            ];
        }

        return $changes;
    }

    private function readable(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }
}
