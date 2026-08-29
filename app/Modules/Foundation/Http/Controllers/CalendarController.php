<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\CalendarEvent;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\CalendarFeed;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One month of everything the business already knows is happening.
 *
 * ⚠⚠ THE GRID IS BUILT ON THE SERVER, not in the browser. A month is not the
 * same thing in every timezone and not the same length in every month, and two
 * implementations of "which days does this grid show" would eventually disagree
 * about which cell an event belongs in. The screen renders cells it is handed.
 */
class CalendarController extends Controller
{
    public function index(Request $request, CalendarFeed $feed): Response
    {
        $user = $request->user();

        // ⚠ Validated rather than trusted. `?month=nonsense` reaching Carbon
        // throws, and an unparseable month is a 500 on a page somebody reached
        // by editing the address bar.
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->query('month') ?? now()->format('Y-m')).'-01')
            ->startOfMonth();

        // ⚠⚠ The window is the whole GRID, not the month. A grid starting on
        // Saturday shows days from the previous month, and gathering only the
        // month leaves those cells silently empty while the days either side of
        // them are full.
        $gridStart = $month->startOfWeek(CarbonImmutable::SATURDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::FRIDAY);

        $items = $feed->between($user, $gridStart->toDateString(), $gridEnd->toDateString());

        $byDate = [];

        foreach ($items as $item) {
            $byDate[$item['date']][] = $item;
        }

        $days = [];

        for ($day = $gridStart; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay()) {
            $key = $day->toDateString();

            $days[] = [
                'date' => $key,
                'day' => $day->day,
                'in_month' => $day->month === $month->month,
                'is_today' => $day->isToday(),
                'items' => $byDate[$key] ?? [],
            ];
        }

        return Inertia::render('calendar/index', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'previous' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'days' => $days,
            'can' => ['manage' => $user->can('calendar.manage')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        CalendarEvent::create(array_merge($data, [
            'business_id' => app(BusinessContext::class)->id(),
            'created_by' => $request->user()->id,
        ]));

        return back()->with('success', 'Added to the calendar.');
    }

    public function update(Request $request, CalendarEvent $event): RedirectResponse
    {
        $this->assertMayChange($request, $event);

        $event->update($this->validated($request));

        return back()->with('success', 'Calendar entry saved.');
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        $this->assertMayChange($request, $event);

        $event->delete();

        return back()->with('success', 'Removed from the calendar.');
    }

    /**
     * ⚠⚠ A PRIVATE EVENT IS 404, NOT 403. Telling somebody "you may not edit
     * event 41" confirms that event 41 exists and belongs to a colleague, which
     * is exactly what private was for.
     *
     * ⚠ A shared event may be corrected by anyone who may manage the calendar.
     * On a nine-person business, requiring the author means a meeting nobody can
     * move the week its author is on leave.
     */
    private function assertMayChange(Request $request, CalendarEvent $event): void
    {
        $user = $request->user();

        abort_if($event->is_private && $event->created_by !== $user->id, 404);
    }

    /**
     * @return array<string,mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            // ⚠ `after_or_equal`, not `after`: an event that starts and ends on
            // the same day is the ordinary case, not an error.
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['boolean'],
            'is_private' => ['boolean'],
        ]);

        return $data;
    }
}
