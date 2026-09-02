<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\HrmPermissionsSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 5, Slice 5: performance, phase 1.
 *
 * Goals with an owner and an outcome, and ONE review per person per period.
 * The outcome of a goal is stamped when decided, and a review saved again
 * for the same period REPLACES its draft - two reviews for one quarter is
 * an argument, not a record. Nothing here touches pay.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Perf Co', 'slug' => 'perf-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 14:00:00');

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->hr->assignRole('Owner');

    $this->employee = Employee::create([
        'business_id' => $this->business->id,
        'employee_code' => 'BDG-000051',
        'first_name' => 'Shoykod', 'last_name' => 'Islam',
        'joining_date' => '2026-07-01',
        'status' => 'active',
        'employment_type' => 'permanent',
    ]);
    EmployeeAssignment::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-07-01',
        'is_primary' => true,
    ]);

    $this->asHr = fn () => $this->actingAs($this->hr)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('sets a goal and stamps its outcome when decided', function () {
    ($this->asHr)()->post(route('hrm.employees.goals.store', $this->employee), [
        'title' => 'Open five new dealer accounts',
        'due_on' => '2026-12-31',
    ])->assertSessionHasNoErrors();

    $goal = DB::table('performance_goals')->first();
    expect($goal->status)->toBe('open');

    ($this->asHr)()->post(route('hrm.goals.outcome', $goal->id), [
        'status' => 'achieved',
    ])->assertSessionHasNoErrors();

    $decided = DB::table('performance_goals')->first();
    expect($decided->status)->toBe('achieved')
        ->and($decided->decided_at)->not->toBeNull()
        ->and($decided->decided_by)->toBe($this->hr->id);
});

it('keeps one review per person per period - saving again replaces', function () {
    foreach ([['3', 'Good counter work'], ['4', 'Improved a lot']] as [$rating, $strengths]) {
        ($this->asHr)()->post(route('hrm.employees.reviews.store', $this->employee), [
            'period' => '2026-Q3',
            'rating' => $rating,
            'strengths' => $strengths,
            'improvements' => 'Punctuality',
        ])->assertSessionHasNoErrors();
    }

    expect(DB::table('performance_reviews')->count())->toBe(1)
        ->and((int) DB::table('performance_reviews')->value('rating'))->toBe(4);
});

it('shows the performance page and keeps outsiders off', function () {
    ($this->asHr)()->get(route('hrm.employees.performance', $this->employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('hrm/employees/performance'));

    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);
    $this->actingAs($bystander)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.employees.performance', $this->employee))
        ->assertForbidden();
});
