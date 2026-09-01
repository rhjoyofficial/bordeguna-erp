<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Support\ApprovalCards;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy, 1 Sep 2026: "when I clicked on the Open record, shows nothing."
 *
 * ⚠⚠ Every "Open record" button on an approval card points at a screen that
 * FILTERS. A link with no filter inherits that screen's default - and a
 * payroll screen defaults to TODAY'S month, so the approval for August landed
 * on an empty September. The record was never missing; the link never asked
 * for it.
 *
 * These tests follow the link the way a person does: take the URL off the
 * card, request it, and insist the record is actually on the page that comes
 * back. A test that only asserted the URL string would have passed on the day
 * the screen was blank.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Link Co', 'slug' => 'link-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.payroll.manage', 'guard_name' => 'web']);
    $owner = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $owner->givePermissionTo('hrm.payroll.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Owner');

    $this->cardFor = function ($approvable) {
        $request = $approvable->approvalRequest()->firstOrFail()->load('requestedBy');
        $request->setRelation('approvable', $approvable);

        return app(ApprovalCards::class)->present($request);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('opens the payroll run the approval is about, not whatever month it is today', function () {
    // The approval is for AUGUST; the day the approver clicks is 1 SEPTEMBER.
    Carbon::setTestNow('2026-09-01 09:00:00');

    $period = PayrollPeriod::create([
        'business_id' => $this->business->id, 'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30,
    ]);

    $run = PayrollRun::create([
        'business_id' => $this->business->id, 'payroll_period_id' => $period->id,
        'version' => 3, 'status' => 'calculated',
        'employee_count' => 9, 'total_earnings' => 250000, 'total_deductions' => 10951.34, 'total_net' => 239048.66,
    ]);

    $run->submitForApproval($this->user);

    $card = ($this->cardFor)($run->fresh());

    expect($card['view_url'])->not->toBeNull();

    // Follow the link exactly as the browser would.
    $this->actingAs($this->user)->get($card['view_url'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/index')
            ->where('year', 2026)
            ->where('month', 8)
            ->where('run.id', $run->id)
            ->where('run.version', 3));
});

/**
 * ⚠ The source guard, in the spirit of EveryApprovalReachesSomebodyTest: a new
 * card cannot quietly link to a filtering screen without saying WHICH record it
 * means. Screens listed here default their filter to today (or to nothing), so
 * a bare link to them is a link to the wrong page.
 */
it('never links an approval card at a filtering screen without filters', function () {
    $source = file_get_contents(app_path('Support/ApprovalCards.php'));

    $filteringScreens = [
        'hrm.payroll.index',
        'hrm.payroll.inputs.index',
        'hrm.leave.index',
        'hrm.loans.index',
        'inventory.stock.index',
    ];

    foreach ($filteringScreens as $screen) {
        expect($source)->not->toContain("route('{$screen}')",
            "ApprovalCards links at {$screen} with no filters; that screen will show its own default, not this record.");
    }
});
