<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\AttendancePolicy;
use Illuminate\Support\Facades\Artisan;

/**
 * The back-dating tool for the lateness allowance (Joy, 27 Sep 2026): set how
 * many late days in a band are free, across every version, so a cycle already
 * under way uses the new number.
 */
function bandPolicy(int $business, int $version, int $deductFrom): AttendancePolicy
{
    return AttendancePolicy::create([
        'business_id' => $business,
        'name' => 'Test policy',
        'version' => $version,
        'config' => [
            'grace_windows' => [
                [
                    'key' => 'slightly_late', 'label' => 'Up to 15 minutes late',
                    'from_minutes' => 0, 'to_minutes' => 15, 'requires_review' => false,
                    'tiers' => [
                        ['from_occurrence' => 1, 'consequence' => 'none'],
                        ['from_occurrence' => $deductFrom, 'consequence' => 'deduction'],
                    ],
                ],
            ],
        ],
        'effective_from' => "2026-0{$version}-01",
    ]);
}

it('sets the free-day allowance on every policy version of the business', function () {
    $business = Business::create(['name' => 'FreeDays Co', 'slug' => 'freedays-co']);
    app(BusinessContext::class)->set($business->id);

    bandPolicy($business->id, 1, 4); // 3 free
    bandPolicy($business->id, 2, 4); // 3 free

    Artisan::call('hrm:set-lateness-free-days', ['days' => 4, '--business' => $business->id]);

    foreach (AttendancePolicy::where('business_id', $business->id)->get() as $policy) {
        $band = collect($policy->config['grace_windows'])->firstWhere('key', 'slightly_late');
        // 4 free means the deduction now starts at the 5th occurrence.
        expect(collect($band['tiers'])->last()['from_occurrence'])->toBe(5);
    }
});

it('leaves other bands untouched and honours a dry run', function () {
    $business = Business::create(['name' => 'DryRun Co', 'slug' => 'dryrun-co']);
    app(BusinessContext::class)->set($business->id);
    $policy = bandPolicy($business->id, 1, 4);

    Artisan::call('hrm:set-lateness-free-days', ['days' => 4, '--business' => $business->id, '--dry-run' => true]);

    $band = collect($policy->fresh()->config['grace_windows'])->firstWhere('key', 'slightly_late');
    expect(collect($band['tiers'])->last()['from_occurrence'])->toBe(4); // unchanged by the dry run
});
