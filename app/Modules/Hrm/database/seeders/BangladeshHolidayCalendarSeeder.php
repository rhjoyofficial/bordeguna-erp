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
 * entered by HR — the seeder never guesses them. Idempotent.
 */
class BangladeshHolidayCalendarSeeder extends Seeder
{
    /** month-day => name, for holidays whose Gregorian date is fixed. */
    private const FIXED_HOLIDAYS = [
        '02-21' => 'International Mother Language Day',
        '03-26' => 'Independence Day',
        '05-01' => 'May Day',
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

            if ($calendar->holidays()->exists()) {
                continue;
            }

            foreach (self::FIXED_HOLIDAYS as $monthDay => $name) {
                $calendar->holidays()->create(['date' => "{$year}-{$monthDay}", 'name' => $name]);
            }
        }
    }
}
