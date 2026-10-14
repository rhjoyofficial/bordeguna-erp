<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\WorkScheduleSetting;
use App\Modules\Foundation\Models\WorkScheduleSlot;
use App\Support\Preferences\ScheduleAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Work schedule (schedule.manage, Owner and Manager, 14 Oct 2026):
 * the slots of the working day and the two group-wide settings. Every change
 * is written to the activity log.
 *
 * ⚠ Two ACTIVE slots that share a weekday may not overlap: the alert engine
 * fires on a slot's start, and "Now" on the rhythm page must name one slot.
 * Touching ends are fine (11:00 to 11:10 after 09:05 to 11:00).
 *
 * The person's own switches (alerts and sound) are here too, in mine(): auth
 * only, no permission, a person sets their own.
 */
class WorkScheduleController extends Controller
{
    public function index(): Response
    {
        $settings = WorkScheduleSetting::current();

        return Inertia::render('settings/work-schedule', [
            'slots' => WorkScheduleSlot::query()->ordered()->get()->map(fn (WorkScheduleSlot $slot) => [
                ...$slot->toFeed(),
                'is_active' => $slot->is_active,
                'sort_order' => $slot->sort_order,
            ])->values(),
            'settings' => [
                'alert_seconds' => $settings->alert_seconds,
                'is_enabled' => $settings->is_enabled,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->refuseOverlap($data);

        $slot = WorkScheduleSlot::create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        $this->log($request, 'Added a work schedule slot', ['slot_id' => $slot->id, 'slot' => $slot->toFeed()]);

        return back()->with('success', 'The slot was added.');
    }

    public function update(Request $request, WorkScheduleSlot $slot): RedirectResponse
    {
        $data = $this->validated($request);
        $this->refuseOverlap($data, $slot->id);

        $was = $slot->toFeed() + ['is_active' => $slot->is_active];
        $slot->update([...$data, 'updated_by' => $request->user()->id]);

        $this->log($request, 'Changed a work schedule slot', [
            'slot_id' => $slot->id,
            'was' => $was,
            'now' => $slot->toFeed() + ['is_active' => $slot->is_active],
        ]);

        return back()->with('success', 'The slot was saved.');
    }

    public function toggle(Request $request, WorkScheduleSlot $slot): RedirectResponse
    {
        if (! $slot->is_active) {
            $this->refuseOverlap([
                'starts_at' => WorkScheduleSlot::hm($slot->starts_at),
                'ends_at' => WorkScheduleSlot::hm($slot->ends_at),
                'weekdays' => $slot->days(),
                'is_active' => true,
            ], $slot->id);
        }

        $slot->update(['is_active' => ! $slot->is_active, 'updated_by' => $request->user()->id]);

        $this->log(
            $request,
            $slot->is_active ? 'Resumed a work schedule slot' : 'Paused a work schedule slot',
            ['slot_id' => $slot->id, 'title' => $slot->title],
        );

        return back()->with('success', $slot->is_active ? 'The slot is active.' : 'The slot is paused.');
    }

    public function destroy(Request $request, WorkScheduleSlot $slot): RedirectResponse
    {
        $properties = ['slot_id' => $slot->id, 'slot' => $slot->toFeed()];
        $slot->delete();

        $this->log($request, 'Removed a work schedule slot', $properties);

        return back()->with('success', 'The slot was removed.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'alert_seconds' => ['required', 'integer', 'min:'.WorkScheduleSetting::MIN_SECONDS, 'max:'.WorkScheduleSetting::MAX_SECONDS],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $settings = WorkScheduleSetting::current();
        $was = ['alert_seconds' => $settings->alert_seconds, 'is_enabled' => $settings->is_enabled];
        $settings->update([
            'alert_seconds' => (int) $data['alert_seconds'],
            'is_enabled' => (bool) $data['is_enabled'],
            'updated_by' => $request->user()->id,
        ]);

        $this->log($request, 'Changed the work schedule settings', [
            'was' => $was,
            'now' => ['alert_seconds' => $settings->alert_seconds, 'is_enabled' => $settings->is_enabled],
        ]);

        return back()->with('success', 'The work schedule settings were saved.');
    }

    /** The person's own switches (Settings > Appearance): either or both. */
    public function mine(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'sound' => ['sometimes', 'boolean'],
        ]);

        ScheduleAlerts::save($request->user(), array_map('boolval', $data));

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'title' => ['required', 'string', 'max:80'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'kind' => ['required', Rule::in(WorkScheduleSlot::KINDS)],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6', 'distinct'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ], [
            'ends_at.after' => 'The slot must end after it starts.',
        ]);

        $days = array_map('intval', $data['weekdays']);
        sort($days);
        $subtitle = trim((string) ($data['subtitle'] ?? ''));

        return [
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'title' => trim($data['title']),
            'subtitle' => $subtitle === '' ? null : $subtitle,
            'kind' => $data['kind'],
            'weekdays' => $days,
            'is_active' => (bool) $data['is_active'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * An active slot may not overlap another active slot on any shared
     * weekday. Times compare as 'HH:MM' strings, which order like the clock.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseOverlap(array $data, ?int $ignoreId = null): void
    {
        if (! $data['is_active']) {
            return;
        }

        $start = WorkScheduleSlot::hm($data['starts_at']);
        $end = WorkScheduleSlot::hm($data['ends_at']);

        $clash = WorkScheduleSlot::query()
            ->where('is_active', true)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->first(fn (WorkScheduleSlot $other) => array_intersect($other->days(), $data['weekdays']) !== []
                && $start < WorkScheduleSlot::hm($other->ends_at)
                && WorkScheduleSlot::hm($other->starts_at) < $end);

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'starts_at' => sprintf(
                    'This overlaps "%s" (%s to %s) on a shared day. Change the times or the days, or pause one of them.',
                    $clash->title,
                    WorkScheduleSlot::hm($clash->starts_at),
                    WorkScheduleSlot::hm($clash->ends_at),
                ),
            ]);
        }
    }

    private function log(Request $request, string $message, array $properties): void
    {
        activity('system')->causedBy($request->user())->withProperties($properties)->log($message);
    }
}
