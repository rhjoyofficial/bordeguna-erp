<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Hrm\Models\HolidayCalendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Ships each business a default Bangladesh holiday calendar out of the box (W5):
 * Friday weekly off (SCH-06) plus the stable fixed-Gregorian national holidays.
 * Movable/lunar holidays (Eid, Puja, Buddha Purnima …) shift every year and are
 * entered by HR — the seeder never guesses them.
 *
 * ⚠ Idempotent PER HOLIDAY, not per calendar. It used to skip a calendar
 * entirely once it held any holiday at all, which meant adding a date to the
 * list below silently never reached a calendar already in production — the case
 * that actually matters, since every live calendar has holidays. A HR-entered
 * date is still never touched: `firstOrCreate` matches on calendar + date, and
 * the table's unique key is the same pair.
 */
class BangladeshHolidayCalendarSeeder extends Seeder
{
    /** month-day => name, for holidays whose Gregorian date is fixed. */
    private const FIXED_HOLIDAYS = [
        '02-21' => 'International Mother Language Day',
        '03-26' => 'Independence Day',
        '05-01' => 'May Day',
        // Observed nationally on 5 August every year since 2025. Fixed-Gregorian,
        // so it belongs here rather than with the movable holidays HR enters.
        '08-05' => 'July Mass Uprising Day',
        '12-16' => 'Victory Day',
        '12-25' => 'Christmas Day',
    ];

    public function run(): void
    {
        $year = Carbon::now()->year;

        foreach (Business::all() as $business) {
            $calendar = HolidayCalendar::withoutGlobalScope(BusinessScope::class)->firstOrCreate(
                ['business_id' => $business->id, 'name' => 'Bangladesh', 'year' => $year],
                ['weekly_off_days' => [5], 'is_default' => true, 'is_active' => true], // 5 = Friday
            );

            foreach (self::FIXED_HOLIDAYS as $monthDay => $name) {
                $date = "{$year}-{$monthDay}";

                // ⚠ `whereDate()` rather than `firstOrCreate(['date' => …])`.
                // `holidays.date` is a real DATE column so the string would in
                // fact match here — but this project has now been bitten five
                // times by the same lookup against a DATETIME column, where it
                // silently matches nothing and inserts a duplicate on every run.
                // The pattern is worth keeping uniform rather than reasoning
                // about the column type at each call site.
                $exists = $calendar->holidays()->whereDate('date', $date)->exists();

                if (! $exists) {
                    $calendar->holidays()->create(['date' => $date, 'name' => $name]);
                }
            }
        }
    }
}
