<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\AttendancePolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds POL-03's actual worked policy as the starting rule set, so a business
 * opens on working defaults rather than an empty configuration screen.
 *
 * The two bands and their ladders are transcribed from the questionnaire's own
 * worked example, quoted below. Nothing here is invented — where the example is
 * silent (the exact half-day boundary, POL-05's "Configurable"), the value is a
 * neutral default HR is expected to set.
 */
class AttendancePolicySeeder extends Seeder
{
    /**
     * POL-03, verbatim: "First 3 late arrivals (09:00:01–09:15) are excused per
     * month. The 4th results in a verbal warning. From the 5th onward, a
     * pro-rata one-day salary deduction applies. For arrivals between
     * 09:15:01–11:30, the first occurrence is excused, the second receives a
     * warning, the third receives a warning plus salary deduction, and the
     * fourth may trigger disciplinary action."
     *
     * Expressed as minutes after the shift start, so the same policy works for
     * any shift rather than being hardcoded to a 09:00 one.
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'grace_windows' => [
                [
                    'key' => 'within_grace',
                    'label' => 'Up to 15 minutes late',
                    'from_minutes' => 0,   // exclusive: 09:00:01 counts
                    'to_minutes' => 15,    // inclusive: 09:15:00 still this band
                    'tiers' => [
                        ['from_occurrence' => 1, 'consequence' => 'none'],
                        ['from_occurrence' => 4, 'consequence' => 'warning'],
                        ['from_occurrence' => 5, 'consequence' => 'deduction'],
                    ],
                ],
                [
                    'key' => 'late_arrival',
                    'label' => '15 minutes to 2.5 hours late',
                    'from_minutes' => 15,  // exclusive: 09:15:01 starts this band
                    'to_minutes' => 150,   // 11:30 for a 09:00 shift
                    'tiers' => [
                        ['from_occurrence' => 1, 'consequence' => 'none'],
                        ['from_occurrence' => 2, 'consequence' => 'warning'],
                        ['from_occurrence' => 3, 'consequence' => 'warning_and_deduction'],
                        ['from_occurrence' => 4, 'consequence' => 'disciplinary'],
                    ],
                ],
            ],
            // POL-04 states 15 minutes, matching Correction C4's late grace.
            'early_leave_grace_minutes' => 15,
            // POL-05 answered "Configurable" with no number given — neutral
            // defaults for HR to adjust, not a rule claimed from the document.
            'half_day_maximum_hours' => 4,
            'full_day_minimum_hours' => 8,
            'half_day_arrival_after_minutes' => null,
            // POL-09: holiday work never counts unless approved.
            'count_holiday_work_without_approval' => false,
        ];
    }

    public function run(): void
    {
        foreach (Business::all() as $business) {
            $exists = AttendancePolicy::withoutGlobalScopes()
                ->where('business_id', $business->id)
                ->exists();

            if ($exists) {
                continue;
            }

            AttendancePolicy::withoutGlobalScopes()->create([
                'business_id' => $business->id,
                'name' => 'Standard attendance policy',
                'version' => 1,
                'config' => self::defaultConfig(),
                // Backdated so days already captured are judged by it; a later
                // revision opens a new row from its own date (POL-12).
                'effective_from' => Carbon::now()->startOfYear()->toDateString(),
                'effective_to' => null,
            ]);
        }
    }
}
