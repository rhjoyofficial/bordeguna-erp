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
 * Module 5, Slice 4: onboarding / offboarding checklists.
 *
 * A joiner and a leaver each have a short list of things that must actually
 * happen; the checklist records WHO ticked WHAT and WHEN. Defaults are
 * planted on first visit and editable as data, not code. Ticking is
 * reversible - people mis-click - but the record of the final state is what
 * the audit reads.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Checklist Co', 'slug' => 'checklist-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new HrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 13:00:00');

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->hr->assignRole('Owner');

    $this->employee = Employee::create([
        'business_id' => $this->business->id,
        'employee_code' => 'BDG-000041',
        'first_name' => 'Nazmul', 'last_name' => 'Haque',
        'joining_date' => '2026-09-01',
        'status' => 'active',
        'employment_type' => 'probation',
    ]);
    EmployeeAssignment::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-09-01',
        'is_primary' => true,
    ]);

    $this->asHr = fn () => $this->actingAs($this->hr)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('plants default items on first visit and remembers who ticked what', function () {
    ($this->asHr)()->get(route('hrm.employees.checklist', $this->employee))->assertOk();
    // Second visit must not duplicate.
    ($this->asHr)()->get(route('hrm.employees.checklist', $this->employee))->assertOk();

    $onboarding = DB::table('hr_checklist_items')->where('kind', 'onboarding')->count();
    expect($onboarding)->toBeGreaterThan(3)
        ->and(DB::table('hr_checklist_items')->count())
        ->toBe(DB::table('hr_checklist_items')->distinct()->count('title'));

    $item = DB::table('hr_checklist_items')->where('kind', 'onboarding')->orderBy('sort')->first();

    ($this->asHr)()->post(route('hrm.employees.checklist.toggle', [$this->employee, $item->id]))
        ->assertSessionHasNoErrors();

    $progress = DB::table('hr_checklist_progress')->first();
    expect($progress->done_by)->toBe($this->hr->id)
        ->and($progress->done_at)->not->toBeNull();

    ($this->asHr)()->get(route('hrm.employees.checklist', $this->employee))
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/employees/checklist')
            ->where('onboarding.0.done', true)
            ->where('onboarding.0.done_by', fn ($value) => $value !== null)
            ->where('onboarding.1.done', false));

    // A mis-click is reversible; the tick disappears entirely.
    ($this->asHr)()->post(route('hrm.employees.checklist.toggle', [$this->employee, $item->id]));
    expect(DB::table('hr_checklist_progress')->count())->toBe(0);
});

it('keeps the checklist behind the employee-management gate', function () {
    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);

    $this->actingAs($bystander)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.employees.checklist', $this->employee))
        ->assertForbidden();
});
