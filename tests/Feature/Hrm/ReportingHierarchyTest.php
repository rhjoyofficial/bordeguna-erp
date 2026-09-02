<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 1, Slice 1 of the master plan (2 Sep 2026): the reporting hierarchy
 * made VISIBLE - and guarded.
 *
 * The line itself already existed: employee_assignments.supervisor_id,
 * written by the Reassign flow since 22 July and populated in production. The
 * first cut of this slice added a duplicate employees.reports_to in ignorance
 * of it; the duplicate was removed the same evening (the 31 Aug lesson again:
 * VERIFY IN CODE FIRST). What this slice really adds is the org chart page
 * and the cycle guard the reassign path never had.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Chain Co', 'slug' => 'chain-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.employees.manage', 'guard_name' => 'web']);
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo('hrm.employees.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);

    $make = fn (string $first, string $last) => app(CreateEmployee::class)->handle(
        ['first_name' => $first, 'last_name' => $last, 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $this->office->id],
    );

    $this->founder = $make('Moin', 'Chowdhury');
    $this->manager = $make('Musa', 'Islam');
    $this->staffer = $make('Rakib', 'Hossain');

    // The line lives on the current primary assignment, set here the way the
    // Reassign flow stores it.
    $this->setSupervisor = function (Employee $employee, ?int $supervisorId) {
        $employee->currentAssignment()->update(['supervisor_id' => $supervisorId]);
    };
});

function chainOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

it('refuses a reassignment that would circle the reporting chain', function () {
    // Musa answers to Moin, Rakib to Musa: a real chain.
    ($this->setSupervisor)($this->manager, $this->founder->id);
    ($this->setSupervisor)($this->staffer, $this->manager->id);

    // Now Moin is reassigned to answer to Rakib - every walk up would spin.
    chainOwner()
        ->post(route('hrm.employees.transfer', $this->founder), [
            'effective_from' => now()->toDateString(),
            'office_id' => $this->office->id,
            'supervisor_id' => $this->staffer->id,
        ])
        ->assertSessionHasErrors('supervisor_id');

    expect($this->founder->fresh()->currentAssignment()->supervisor_id)->toBeNull();
});

it('accepts an honest reassignment and records the line on the new posting', function () {
    chainOwner()
        ->post(route('hrm.employees.transfer', $this->staffer), [
            'effective_from' => now()->toDateString(),
            'office_id' => $this->office->id,
            'supervisor_id' => $this->manager->id,
        ])
        ->assertSessionHasNoErrors();

    expect($this->staffer->fresh()->currentAssignment()->supervisor_id)->toBe($this->manager->id)
        ->and($this->staffer->fresh()->currentSupervisor()->id)->toBe($this->manager->id);
});

it('renders the organization chart to any signed-in member, roots first', function () {
    ($this->setSupervisor)($this->manager, $this->founder->id);
    ($this->setSupervisor)($this->staffer, $this->manager->id);

    // An ordinary staff login with NO permission at all - the chart is the
    // company phone book's shape, not a payroll document.
    $plain = User::factory()->create();
    $plain->businesses()->attach($this->business->id);
    $plain->assignRole('Staff');

    test()->actingAs($plain)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.org-chart'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/org-chart')
            ->has('tree', 1) // one root: the founder
            ->where('tree.0.name', 'Moin Chowdhury')
            ->has('tree.0.reports', 1)
            ->where('tree.0.reports.0.name', 'Musa Islam')
            ->where('tree.0.reports.0.reports.0.name', 'Rakib Hossain'));
});

it('keeps a separated employee off the chart and surfaces their orphaned reports at the root', function () {
    ($this->setSupervisor)($this->manager, $this->founder->id);
    ($this->setSupervisor)($this->staffer, $this->manager->id);
    $this->manager->update(['status' => 'separated']);

    chainOwner()
        ->get(route('hrm.org-chart'))
        ->assertInertia(fn (Assert $page) => $page
            // Moin stays a root with nobody under him; Rakib does not vanish
            // with his departed manager - he surfaces at the root.
            ->has('tree', 2)
            ->where('tree.0.name', 'Moin Chowdhury')
            ->where('tree.0.reports', [])
            ->where('tree.1.name', 'Rakib Hossain'));
});

it('orders chart siblings by employee code, never by name (Joy, 2 Sep)', function () {
    // A name that sorts FIRST alphabetically gets the HIGHEST code, so a
    // name sort and a code sort cannot agree.
    app(CreateEmployee::class)->handle(
        ['first_name' => 'Aaa', 'last_name' => 'Aaa', 'employment_type' => 'permanent', 'joining_date' => '2025-01-01'],
        ['office_id' => $this->office->id],
    );

    chainOwner()
        ->get(route('hrm.org-chart'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tree', fn ($tree) => collect($tree)->last()['name'] === 'Aaa Aaa'));
});
