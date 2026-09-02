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
 * Module 1, Slice 1 of the master plan (2 Sep 2026): the reporting hierarchy.
 *
 * The system had NO concept of who reports to whom - the boss's staff-benefit
 * spec needs an approval chain, the HRM spec's manager-self-service needs a
 * team, and the collection pack has been gathering "reports to" codes with
 * nowhere to put them. `reports_to` is that place.
 *
 * ⚠⚠ A hierarchy's one structural failure is a CYCLE: A reports to B reports
 * to A leaves every walk up the chain spinning forever. The rule pins both
 * the self-loop and the longer cycle, because the second is the one a form
 * full of honest data can still produce.
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
});

function chainOwner()
{
    return test()->actingAs(test()->owner)->withSession(['current_business_id' => test()->business->id]);
}

/** The minimum master-field payload the update route insists on. */
function hierarchyPayload(Employee $employee, array $overrides = []): array
{
    return array_merge([
        'first_name' => $employee->first_name,
        'last_name' => $employee->last_name,
        'employment_type' => 'permanent',
        'joining_date' => '2025-01-01',
    ], $overrides);
}

it('stores who an employee reports to, through the ordinary edit route', function () {
    chainOwner()
        ->patch(route('hrm.employees.update', $this->staffer), hierarchyPayload($this->staffer, [
            'reports_to' => $this->manager->id,
        ]))
        ->assertSessionHasNoErrors();

    expect($this->staffer->fresh()->reports_to)->toBe($this->manager->id)
        ->and($this->staffer->fresh()->supervisor->id)->toBe($this->manager->id)
        ->and($this->manager->fresh()->directReports->pluck('id'))->toContain($this->staffer->id);
});

it('clears the supervisor when the field is emptied - the founder reports to nobody', function () {
    $this->staffer->update(['reports_to' => $this->manager->id]);

    chainOwner()
        ->patch(route('hrm.employees.update', $this->staffer), hierarchyPayload($this->staffer, [
            'reports_to' => null,
        ]))
        ->assertSessionHasNoErrors();

    expect($this->staffer->fresh()->reports_to)->toBeNull();
});

it('refuses an employee reporting to themselves', function () {
    chainOwner()
        ->patch(route('hrm.employees.update', $this->manager), hierarchyPayload($this->manager, [
            'reports_to' => $this->manager->id,
        ]))
        ->assertSessionHasErrors('reports_to');

    expect($this->manager->fresh()->reports_to)->toBeNull();
});

it('refuses the cycle a form full of honest data can produce', function () {
    // Musa reports to Moin, Rakib reports to Musa: a real chain.
    $this->manager->update(['reports_to' => $this->founder->id]);
    $this->staffer->update(['reports_to' => $this->manager->id]);

    // Now somebody sets Moin to report to Rakib - every walk up would spin.
    chainOwner()
        ->patch(route('hrm.employees.update', $this->founder), hierarchyPayload($this->founder, [
            'reports_to' => $this->staffer->id,
        ]))
        ->assertSessionHasErrors('reports_to');

    expect($this->founder->fresh()->reports_to)->toBeNull();
});

it('offers the supervisor choices on the edit screen, never including the employee themselves', function () {
    chainOwner()
        ->get(route('hrm.employees.edit', $this->staffer))
        ->assertInertia(fn (Assert $page) => $page
            ->component('hrm/employees/edit')
            ->has('supervisor_options')
            ->where('supervisor_options', fn ($options) => collect($options)
                ->pluck('value')->doesntContain((string) $this->staffer->id)));
});

it('renders the organization chart to any signed-in member, roots first', function () {
    $this->manager->update(['reports_to' => $this->founder->id]);
    $this->staffer->update(['reports_to' => $this->manager->id]);

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

it('keeps a separated employee off the chart', function () {
    $this->manager->update(['reports_to' => $this->founder->id]);
    $this->staffer->update(['reports_to' => $this->manager->id, 'status' => 'separated']);

    chainOwner()
        ->get(route('hrm.org-chart'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tree.0.reports.0.reports', []));
});
