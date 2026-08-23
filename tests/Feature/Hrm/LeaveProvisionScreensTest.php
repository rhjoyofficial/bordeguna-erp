<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\PayrollAccountMappingSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveProvisionRun;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Models\SalaryStructureLine;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The leave provision screens and their permission layer. The engine and the
 * screens ship in the same commit - the reachability rule (an action with no
 * route and no nav link is a feature that does not exist).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Provision Screen Co', 'slug' => 'provision-screen-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.payroll.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.payroll.manage');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    (new ChartOfAccountsSeeder)->run();
    (new SalaryComponentSeeder)->run();
    (new PayrollAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-08-12 10:00:00');

    $office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $department = Department::create(['name' => 'Production', 'office_id' => $office->id, 'is_active' => true]);

    $type = LeaveType::create([
        'business_id' => $this->business->id, 'name' => 'Earned leave', 'code' => 'EL',
        'annual_quota' => 20, 'can_encash' => true,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'E-001', 'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'business_id' => $this->business->id,
        'office_id' => $office->id,
        'department_id' => $department->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $structure = SalaryStructure::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'effective_from' => '2025-01-01',
    ]);

    SalaryStructureLine::create([
        'salary_structure_id' => $structure->id,
        'salary_component_id' => SalaryComponent::where('code', 'basic')->first()->id,
        'value' => '30000',
    ]);

    LeaveLedgerEntry::create([
        'business_id' => $this->business->id,
        'employee_id' => $this->employee->id,
        'leave_type_id' => $type->id,
        'year' => 2026, 'entry_type' => 'grant',
        'days' => 12, 'occurred_on' => '2026-01-01',
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('previews the liability on the screen with the figures that will post', function () {
    ($this->asRole)('Owner')->get('/hrm/leave-provision')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/leave-provision/index')
            // ⚠ 12000 not 12000.0: a whole-taka figure round-trips through JSON
            // as an int, and Inertia's `where` is a strict comparison.
            ->where('plan.totals.liability', 12000)
            ->where('currentBalance', '0.00')
            ->has('plan.rows', 1));
});

it('keeps the provision away from staff who may not manage payroll', function () {
    ($this->asRole)('Staff')->get('/hrm/leave-provision')->assertForbidden();

    ($this->asRole)('Staff')->post('/hrm/leave-provision', [])->assertForbidden();

    expect(LeaveProvisionRun::count())->toBe(0);
});

// ⚠⚠ CHANGED 23 Aug 2026, DELIBERATELY. This test used to record a run and
// show its journal. Joy's final decision that day: leave encashment is NOT
// enabled, unused leave carries no liability, and no provision journal may
// ever post unless he changes the policy. The screen stays as a read-only
// valuation; the Record path refuses before writing anything. The old posting
// tests live in git history at this commit and come back with the policy.
it('refuses to record a provision, because encashment is not enabled', function () {
    ($this->asRole)('Owner')->post('/hrm/leave-provision', ['note' => 'August true-up'])
        ->assertRedirect()
        ->assertSessionHas('error');

    // Nothing was written anywhere: no run, no journal, no liability.
    expect(LeaveProvisionRun::count())->toBe(0)
        ->and(Journal::count())->toBe(0);
});

it('still shows the valuation, clearly as information rather than a posting', function () {
    ($this->asRole)('Owner')->post('/hrm/leave-provision', [])->assertRedirect();

    ($this->asRole)('Owner')->get('/hrm/leave-provision')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // ⚠ 12000 not 12000.0: a whole-taka figure round-trips through JSON
            // as an int, and Inertia's `where` is a strict comparison.
            ->where('plan.totals.liability', 12000)
            // The provision account still carries nothing, because the refused
            // Record above posted nothing.
            ->where('currentBalance', '0.00')
            ->has('runs', 0));
});
