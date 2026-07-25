<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Scopes\BusinessScope;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Pay Co', 'slug' => 'pay-co']);
    $this->otherBusiness = Business::create(['name' => 'Other Pay', 'slug' => 'other-pay']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.salary.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach ([$this->business, $this->otherBusiness] as $biz) {
        foreach (['Owner', 'Manager', 'Finance', 'Staff'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'business_id' => $biz->id, 'guard_name' => 'web']);
        }
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.salary.manage', 'hrm.salary.view']);
    Role::where('business_id', $this->business->id)->where('name', 'Finance')->first()
        ->givePermissionTo('hrm.salary.view');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->payrollHr = User::factory()->create();
    $this->payrollHr->businesses()->attach($this->business->id);
    $this->payrollHr->assignRole('Owner');

    $this->finance = User::factory()->create();
    $this->finance->businesses()->attach($this->business->id);
    $this->finance->assignRole('Finance');

    // Correction C5 / ACL-02: a Department Manager must NOT see salary.
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    (new SalaryComponentSeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2026-01-01',
    ]);

    $this->basic = SalaryComponent::where('code', 'basic')->sole();
    $this->houseRent = SalaryComponent::where('code', 'house_rent')->sole();
    $this->medical = SalaryComponent::where('code', 'medical')->sole();
    $this->pf = SalaryComponent::where('code', 'provident_fund')->sole();
});

function salHr()
{
    return test()->actingAs(test()->payrollHr)->withSession(['current_business_id' => test()->business->id]);
}

function salLines(array $overrides = []): array
{
    return $overrides === [] ? [
        ['salary_component_id' => test()->basic->id, 'value' => 25000],
        ['salary_component_id' => test()->houseRent->id, 'value' => 20000],
        ['salary_component_id' => test()->medical->id, 'value' => 5000],
    ] : $overrides;
}

// --- SAL-02/03 seeded components ----------------------------------------------

it('seeds the components the questionnaire names', function () {
    $codes = SalaryComponent::pluck('code')->all();

    expect($codes)->toContain('basic', 'house_rent', 'medical', 'conveyance');
    expect($codes)->toContain('income_tax', 'provident_fund', 'loan_repayment', 'advance_recovery');
    expect($codes)->toContain('late_early', 'lwp', 'absent', 'festival_loan', 'other');
});

it('leaves the statutory components manual because GOV-06 is unresolved', function () {
    $tax = SalaryComponent::where('code', 'income_tax')->sole();

    expect($tax->is_statutory)->toBeTrue();
    // No invented formula: a fixed, HR-entered amount until GOV-06 is answered.
    expect($tax->calculation_basis->value)->toBe('fixed');
});

it('defines exactly one basic component', function () {
    expect(SalaryComponent::where('is_basic', true)->count())->toBe(1);
    expect(SalaryComponent::basic()->code)->toBe('basic');
});

// --- SAL-01 / C3 component structure ------------------------------------------

it('builds gross from basic plus earnings', function () {
    $structure = app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', salLines());
    $breakdown = $structure->breakdown();

    expect($breakdown->basic)->toBe(25000.0);
    expect($breakdown->gross)->toBe(50000.0);
    expect($breakdown->contractualDeductions)->toBe(0.0);
});

it('resolves a percentage of basic', function () {
    $this->houseRent->update(['calculation_basis' => 'percent_of_basic']);

    $structure = app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', [
        ['salary_component_id' => $this->basic->id, 'value' => 25000],
        ['salary_component_id' => $this->houseRent->id, 'value' => 50], // 50% of basic
    ]);

    $breakdown = $structure->breakdown();
    expect($breakdown->gross)->toBe(37500.0);

    $rentLine = collect($breakdown->lines)->firstWhere('code', 'house_rent');
    expect($rentLine->amount)->toBe(12500.0);
    expect($rentLine->formula)->toBe('50% of Basic 25,000.00');
});

it('resolves a deduction as a percentage of gross, after gross is known', function () {
    $this->pf->update(['calculation_basis' => 'percent_of_gross']);

    $structure = app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', [
        ...salLines(),
        ['salary_component_id' => $this->pf->id, 'value' => 10], // 10% of gross
    ]);

    $breakdown = $structure->breakdown();
    expect($breakdown->gross)->toBe(50000.0);
    expect($breakdown->contractualDeductions)->toBe(5000.0);
    expect($breakdown->contractualNet())->toBe(45000.0);
});

it('refuses to let an earning be a percentage of gross, which would be circular', function () {
    $this->medical->update(['calculation_basis' => 'percent_of_gross']);

    expect(fn () => app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', salLines()))
        ->toThrow(ValidationException::class);
});

it('rounds each component to two decimals per PAY-10', function () {
    $this->houseRent->update(['calculation_basis' => 'percent_of_basic']);

    $structure = app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', [
        ['salary_component_id' => $this->basic->id, 'value' => 25000],
        ['salary_component_id' => $this->houseRent->id, 'value' => 33.333],
    ]);

    // 25,000 × 33.333% = 8,333.25 exactly, at 2 decimals.
    expect(collect($structure->breakdown()->lines)->firstWhere('code', 'house_rent')->amount)->toBe(8333.25);
});

it('records the formula behind every resolved line', function () {
    $structure = app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', salLines());

    foreach ($structure->breakdown()->lines as $line) {
        expect($line->formula)->not->toBeEmpty();
    }
});

// --- SAL-05/06 effective dating -----------------------------------------------

it('closes the old structure the day before a revision starts', function () {
    $action = app(SaveSalaryStructure::class);
    $first = $action->handle($this->employee, '2026-01-01', salLines(), 'Opening structure');
    $second = $action->handle($this->employee, '2026-07-01', [
        ['salary_component_id' => $this->basic->id, 'value' => 30000],
        ['salary_component_id' => $this->houseRent->id, 'value' => 20000],
    ], 'Annual increment');

    expect($first->fresh()->effective_to->toDateString())->toBe('2026-06-30');
    expect($second->effective_to)->toBeNull();
    expect(SalaryStructure::withoutGlobalScope(BusinessScope::class)->count())->toBe(2);
});

it('never rewrites what someone was paid in a closed month', function () {
    $action = app(SaveSalaryStructure::class);
    $action->handle($this->employee, '2026-01-01', salLines());
    $action->handle($this->employee, '2026-07-01', [
        ['salary_component_id' => $this->basic->id, 'value' => 30000],
        ['salary_component_id' => $this->houseRent->id, 'value' => 25000],
    ]);

    // March is still judged by the original structure.
    expect(SalaryStructure::activeFor($this->employee->id, '2026-03-15')->breakdown()->gross)->toBe(50000.0);
    // August by the new one.
    expect(SalaryStructure::activeFor($this->employee->id, '2026-08-15')->breakdown()->gross)->toBe(55000.0);
});

it('resolves nothing before the first structure takes effect', function () {
    app(SaveSalaryStructure::class)->handle($this->employee, '2026-07-01', salLines());

    expect(SalaryStructure::activeFor($this->employee->id, '2026-06-30'))->toBeNull();
    expect(SalaryStructure::activeFor($this->employee->id, '2026-07-01'))->not->toBeNull();
});

it('allows a retroactive revision, which SAL-05 permits', function () {
    $action = app(SaveSalaryStructure::class);
    $action->handle($this->employee, '2026-01-01', salLines());

    $retro = $action->handle($this->employee, '2026-04-01', [
        ['salary_component_id' => $this->basic->id, 'value' => 28000],
    ], 'Backdated increment');

    expect($retro->effective_from->toDateString())->toBe('2026-04-01');
    expect(SalaryStructure::activeFor($this->employee->id, '2026-05-01')->breakdown()->basic)->toBe(28000.0);
});

it('refuses a revision dated before the current structure began', function () {
    $action = app(SaveSalaryStructure::class);
    $action->handle($this->employee, '2026-06-01', salLines());

    expect(fn () => $action->handle($this->employee, '2026-06-01', salLines()))
        ->toThrow(ValidationException::class);
});

it('refuses to slot a revision behind one that already starts later', function () {
    $action = app(SaveSalaryStructure::class);
    $action->handle($this->employee, '2026-01-01', salLines());
    $action->handle($this->employee, '2026-09-01', salLines());

    expect(fn () => $action->handle($this->employee, '2026-05-01', salLines()))
        ->toThrow(ValidationException::class);
});

it('keeps only one structure active at a time, as SAL-06 requires', function () {
    $action = app(SaveSalaryStructure::class);
    $action->handle($this->employee, '2026-01-01', salLines());
    $action->handle($this->employee, '2026-07-01', salLines());

    $active = SalaryStructure::withoutGlobalScope(BusinessScope::class)
        ->where('employee_id', $this->employee->id)
        ->activeOn('2026-08-01')->get();

    expect($active)->toHaveCount(1);
});

// --- Structure validity --------------------------------------------------------

it('requires a basic component', function () {
    expect(fn () => app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', [
        ['salary_component_id' => $this->houseRent->id, 'value' => 20000],
    ]))->toThrow(ValidationException::class);
});

it('refuses the same component twice in one structure', function () {
    expect(fn () => app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', [
        ['salary_component_id' => $this->basic->id, 'value' => 25000],
        ['salary_component_id' => $this->basic->id, 'value' => 30000],
    ]))->toThrow(ValidationException::class);
});

// --- Correction C7c ownership ---------------------------------------------------

it('gives the structure to the business holding the primary assignment', function () {
    $structure = app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', salLines());

    expect($structure->business_id)->toBe($this->business->id);
});

it('refuses a structure for an employee with no active primary assignment', function () {
    $orphan = Employee::create([
        'employee_code' => 'BDG-000099',
        'first_name' => 'No', 'last_name' => 'Placement',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    expect(fn () => app(SaveSalaryStructure::class)->handle($orphan, '2026-01-01', salLines()))
        ->toThrow(ValidationException::class);
});

it('finds the one structure regardless of which business is in context', function () {
    app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', salLines());

    // SAL-06 is a global rule, so the lookup must not be tenant-scoped —
    // otherwise a second structure could be created from another business.
    app(BusinessContext::class)->set($this->otherBusiness->id);

    expect(SalaryStructure::activeFor($this->employee->id))->not->toBeNull();
});

// --- Correction C5 / ACL-02 visibility -------------------------------------------

it('lets payroll HR manage salary', function () {
    salHr()->get(route('hrm.salary.index'))->assertOk();
});

it('lets finance read salary but not change it', function () {
    $this->actingAs($this->finance)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.salary.index'))->assertOk();

    $this->actingAs($this->finance)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.salary.store', $this->employee), [
            'effective_from' => '2026-01-01',
            'lines' => salLines(),
        ])->assertForbidden();
});

it('hides salary from a department manager', function () {
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.salary.index'))->assertForbidden();

    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.salary.show', $this->employee))->assertForbidden();
});

// --- Screens ----------------------------------------------------------------------

it('saves a structure through the screen', function () {
    salHr()->post(route('hrm.salary.store', $this->employee), [
        'effective_from' => '2026-01-01',
        'reason' => 'Opening structure',
        'lines' => salLines(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SalaryStructure::activeFor($this->employee->id)->breakdown()->gross)->toBe(50000.0);
});

it('renders an employee salary page with its history', function () {
    $action = app(SaveSalaryStructure::class);
    $action->handle($this->employee, '2026-01-01', salLines());
    $action->handle($this->employee, '2026-07-01', salLines());

    salHr()->get(route('hrm.salary.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/salary/show')
            ->has('history', 2)
            ->where('current.is_current', true)
            ->where('current.gross', fn ($v) => (float) $v === 50000.0));
});

it('keeps a revision dated in the future visible and marked as scheduled', function () {
    $action = app(SaveSalaryStructure::class);
    $action->handle($this->employee, '2026-01-01', salLines(), 'Opening');
    // SAL-05 allows dating a revision ahead. It is not "current" — it applies
    // to nobody yet — but it must not vanish from the screen.
    $action->handle($this->employee, now()->addMonth()->toDateString(), [
        ['salary_component_id' => $this->basic->id, 'value' => 28000],
    ], 'Scheduled increment');

    salHr()->get(route('hrm.salary.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('history', 2)
            // The one in force today is the opening structure, not the future one.
            ->where('current.reason', 'Opening')
            ->where('history.0.is_future', true)
            ->where('history.0.is_current', false));
});

it('creates a custom component', function () {
    salHr()->post(route('hrm.salary.components.store'), [
        'code' => 'tiffin',
        'name' => 'Tiffin Allowance',
        'type' => 'earning',
        'calculation_basis' => 'fixed',
        'sequence' => 50,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SalaryComponent::where('code', 'tiffin')->exists())->toBeTrue();
});

it('refuses a custom earning priced as a percentage of gross', function () {
    salHr()->post(route('hrm.salary.components.store'), [
        'code' => 'bad',
        'name' => 'Bad Earning',
        'type' => 'earning',
        'calculation_basis' => 'percent_of_gross',
        'sequence' => 60,
    ])->assertSessionHasErrors('calculation_basis');
});

it('refuses to deactivate basic', function () {
    salHr()->patch(route('hrm.salary.components.toggle', $this->basic))->assertRedirect();

    expect($this->basic->fresh()->is_active)->toBeTrue();
});

it('does not leak salary components across businesses', function () {
    app(BusinessContext::class)->set($this->otherBusiness->id);

    expect(SalaryComponent::where('code', 'basic')->count())->toBe(1);
    expect(SalaryComponent::where('code', 'basic')->sole()->business_id)->toBe($this->otherBusiness->id);
});

it('audits a salary structure change', function () {
    $structure = app(SaveSalaryStructure::class)->handle($this->employee, '2026-01-01', salLines());

    $logged = \Spatie\Activitylog\Models\Activity::where('subject_type', $structure->getMorphClass())
        ->where('subject_id', $structure->id)->exists();

    expect($logged)->toBeTrue();
});
