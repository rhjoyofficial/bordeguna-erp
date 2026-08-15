<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Holiday;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\WorkingDayOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manages named/versioned holiday calendars (SCH-06/07): the weekly off-days and
 * the dated holidays. Assigning a calendar to offices/teams/employees is the
 * effective-dated schedule assignment (next slice); here we define the calendars.
 */
class HolidayCalendarController extends Controller
{
    /** Carbon dayOfWeek: 0=Sunday … 6=Saturday. */
    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function index(): Response
    {
        $this->authorize('hrm.scheduling.manage');

        $calendars = HolidayCalendar::with(['holidays', 'workingDayOverrides'])->orderByDesc('year')->orderBy('name')->get()
            ->map(fn (HolidayCalendar $calendar) => [
                'id' => $calendar->id,
                'name' => $calendar->name,
                'year' => $calendar->year,
                'weekly_off_days' => $calendar->weekly_off_days,
                'weekly_off_label' => collect($calendar->weekly_off_days)->map(fn ($d) => self::WEEKDAYS[$d] ?? $d)->implode(', '),
                'is_default' => $calendar->is_default,
                'is_active' => $calendar->is_active,
                'holidays' => $calendar->holidays->map(fn (Holiday $holiday) => [
                    'id' => $holiday->id,
                    'date' => $holiday->date->toDateString(),
                    'name' => $holiday->name,
                ]),
                'working_day_overrides' => $calendar->workingDayOverrides->map(fn (WorkingDayOverride $override) => [
                    'id' => $override->id,
                    'date' => $override->date->toDateString(),
                    'reason' => $override->reason,
                ]),
            ]);

        return Inertia::render('hrm/calendars/index', [
            'calendars' => $calendars,
            'weekdays' => collect(self::WEEKDAYS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $data = $this->validatedCalendar($request);

        DB::transaction(fn () => $this->persistDefault(HolidayCalendar::create($data), $data['is_default']));

        return back()->with('success', 'Calendar created.');
    }

    public function update(Request $request, HolidayCalendar $calendar): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $data = $this->validatedCalendar($request, $calendar);

        DB::transaction(function () use ($calendar, $data) {
            $calendar->update($data);
            $this->persistDefault($calendar, $data['is_default']);
        });

        return back()->with('success', 'Calendar updated.');
    }

    public function toggle(HolidayCalendar $calendar): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $calendar->update(['is_active' => ! $calendar->is_active]);

        return back()->with('success', $calendar->is_active ? 'Calendar activated.' : 'Calendar deactivated.');
    }

    public function destroy(HolidayCalendar $calendar): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $calendar->delete();

        return back()->with('success', 'Calendar deleted.');
    }

    public function storeHoliday(Request $request, HolidayCalendar $calendar): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        // One holiday per date per calendar. whereDate compares the date part
        // only (the column is a date cast stored with a 00:00:00 time).
        if ($calendar->holidays()->whereDate('date', $data['date'])->exists()) {
            throw ValidationException::withMessages(['date' => 'This date is already a holiday in this calendar.']);
        }

        $calendar->holidays()->create($data);

        return back()->with('success', 'Holiday added.');
    }

    public function destroyHoliday(HolidayCalendar $calendar, Holiday $holiday): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        // Never let one calendar's URL remove another's holiday.
        abort_unless($holiday->holiday_calendar_id === $calendar->id, 404);

        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }

    /**
     * The dated exception the other way (a worked Friday, a make-up day):
     * this date IS a working day, whatever the pattern or a holiday says.
     */
    public function storeWorkingDay(Request $request, HolidayCalendar $calendar): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($calendar->workingDayOverrides()->whereDate('date', $data['date'])->exists()) {
            throw ValidationException::withMessages(['date' => 'This date is already a working-day override in this calendar.']);
        }

        // ⚠ Refused rather than silently pointless: a date that is already a
        // working day needs no override, and storing one anyway teaches the
        // operator that the button does nothing.
        if ($calendar->isWorkingDay(Carbon::parse($data['date']))) {
            throw ValidationException::withMessages(['date' => 'This date is already a working day on this calendar.']);
        }

        $calendar->workingDayOverrides()->create($data);

        return back()->with('success', 'Working-day override added.');
    }

    public function destroyWorkingDay(HolidayCalendar $calendar, WorkingDayOverride $override): RedirectResponse
    {
        $this->authorize('hrm.scheduling.manage');

        abort_unless($override->holiday_calendar_id === $calendar->id, 404);

        $override->delete();

        return back()->with('success', 'Working-day override removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCalendar(Request $request, ?HolidayCalendar $calendar = null): array
    {
        $businessId = app(BusinessContext::class)->id();

        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('holiday_calendars', 'name')
                    ->where('business_id', $businessId)
                    ->where('year', $request->integer('year'))
                    ->ignore($calendar?->id),
            ],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'weekly_off_days' => ['present', 'array'],
            'weekly_off_days.*' => ['integer', 'between:0,6'],
            'is_default' => ['required', 'boolean'],
        ]);
    }

    /**
     * A business has at most one default calendar — setting one clears the rest.
     */
    private function persistDefault(HolidayCalendar $calendar, bool $isDefault): void
    {
        if ($isDefault) {
            HolidayCalendar::where('id', '!=', $calendar->id)->update(['is_default' => false]);
        }
    }
}
