<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplySalaryRevision;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\RequestSalaryRevision;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryRevision;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Models\Shift;
use App\Support\ApprovalInbox;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Raise Co', 'slug' => 'raise-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.salary.view', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.salary.manage', 'hrm.salary.view', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new LeaveTypeSeeder)->run();
    (new PayrollWorkflowSeeder)->run();
    (new PayrollInputWorkflowSeeder)->run();

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    // Correction C5 / ACL-02: a Department Manager sees no salary at all.
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $this->calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    app(\App\Modules\Hrm\Actions\AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $this->calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    $this->basic = SalaryComponent::where('code', 'basic')->sole();
    $this->houseRent = SalaryComponent::where('code', 'house_rent')->sole();

    // Gross 50,000.
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => $this->basic->id, 'value' => 25000],
        ['salary_component_id' => $this->houseRent->id, 'value' => 25000],
    ], 'Opening structure');
});

function raiseHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

/** @param array<int, array{salary_component_id: int, value: float}>|null $lines */
function propose(string $from, ?array $lines = null, bool $eligible = false): SalaryRevision
{
    return app(RequestSalaryRevision::class)->handle(
        test()->employee,
        $from,
        $lines ?? [
            ['salary_component_id' => test()->basic->id, 'value' => 30000],
            ['salary_component_id' => test()->houseRent->id, 'value' => 30000],
        ],
        'Annual increment 2026',
        test()->hr,
        $eligible,
    );
}

/** Calculate a period, walk it to Disbursed — money that has actually left. */
function disbursedRun(PayrollPeriod $period): PayrollRun
{
    $run = app(CalculatePayroll::class)->handle($period);
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, test()->hr);
    $run->fresh()->approvalRequest->approve(test()->hr);
    $transition->markApproved($run->fresh(), test()->hr);
    $transition->disburse($run->fresh(), test()->hr);

    return $run->fresh();
}

// --- ADJ-06: proposal before fact ------------------------------------------------

it('creates no salary structure while a proposal is pending', function () {
    propose('2026-08-01');

    // ADJ-06 ticked Proposal → Approval → Effective revision and left HR direct
    // entry blank. A structure that exists is a structure payroll would pay.
    expect(SalaryStructure::where('employee_id', $this->employee->id)->count())->toBe(1);
    expect(SalaryRevision::sole()->isApplied())->toBeFalse();
});

it('opens the new structure only once approved', function () {
    $revision = propose('2026-08-01');

    raiseHr()->post(route('hrm.revisions.approve', $revision))->assertRedirect()->assertSessionHasNoErrors();

    $revision->refresh();
    expect($revision->isApplied())->toBeTrue();
    expect($revision->salary_structure_id)->not->toBeNull();

    $structures = SalaryStructure::historyFor($this->employee->id);
    expect($structures)->toHaveCount(2);
    // P1 close-old-open-new: the old row is closed, never overwritten.
    expect($structures->last()->effective_to->toDateString())->toBe('2026-07-31');
});

it('creates nothing at all when a proposal is rejected', function () {
    $revision = propose('2026-08-01');

    raiseHr()->post(route('hrm.revisions.reject', $revision))->assertRedirect();

    expect($revision->fresh()->isApplied())->toBeFalse();
    expect(SalaryStructure::where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('carries overtime eligibility onto the structure it opens', function () {
    $revision = propose('2026-08-01', eligible: true);
    raiseHr()->post(route('hrm.revisions.approve', $revision));

    expect(SalaryStructure::activeFor($this->employee->id, '2026-08-05')->overtime_eligible)->toBeTrue();
});

it('refuses a second proposal while one is still pending', function () {
    propose('2026-08-01');

    expect(fn () => propose('2026-09-01'))->toThrow(ValidationException::class);
});

it('refuses a proposal dated on or before the structure it revises', function () {
    expect(fn () => propose('2025-01-01'))->toThrow(ValidationException::class);
    expect(fn () => propose('2024-06-01'))->toThrow(ValidationException::class);
});

it('refuses a proposal without exactly one basic component', function () {
    expect(fn () => propose('2026-08-01', [
        ['salary_component_id' => $this->houseRent->id, 'value' => 30000],
    ]))->toThrow(ValidationException::class);
});

it('puts a pending revision in the shared approval inbox', function () {
    propose('2026-08-01');

    $actionable = app(ApprovalInbox::class)->pendingActionableFor($this->hr);

    expect($actionable)->toHaveCount(1);
    expect($actionable->first()->approvable)->toBeInstanceOf(SalaryRevision::class);
});

// --- SAL-05: retroactive arrears --------------------------------------------------

it('produces no arrear when the revision starts in the future', function () {
    disbursedRun(PayrollPeriod::forMonth(2026, 7));

    $revision = propose('2026-08-01');
    app(ApplySalaryRevision::class)->handle($revision);

    expect((float) $revision->fresh()->arrear_amount)->toBe(0.0);
    expect(PayrollAdjustment::count())->toBe(0);
});

it('emits an arrear for a month already paid, without touching the closed payslip', function () {
    $july = PayrollPeriod::forMonth(2026, 7);
    $run = disbursedRun($july);
    $paidNet = (float) $run->payslips()->sole()->net;

    // Effective from the first day of the July payroll month (24 Jun).
    $revision = propose('2026-06-24');
    app(ApplySalaryRevision::class)->handle($revision);

    $revision->refresh();

    // Gross moved 50,000 → 60,000, so the month is worth 10,000 more.
    expect((float) $revision->arrear_amount)->toBe(10000.0);
    expect($revision->arrear_breakdown)->toHaveCount(1);
    expect($revision->arrear_breakdown[0]['period'])->toBe('July 2026');

    // PAY-12 / WFL-06: the closed payslip is untouched.
    expect((float) $run->payslips()->sole()->fresh()->net)->toBe($paidNet);
});

it('posts the arrear forward to the next open payroll month', function () {
    disbursedRun(PayrollPeriod::forMonth(2026, 7));

    $revision = propose('2026-06-24');
    app(ApplySalaryRevision::class)->handle($revision);

    $adjustment = PayrollAdjustment::sole();

    expect($adjustment->type)->toBe(PayrollAdjustmentType::Arrear);
    expect((float) $adjustment->amount)->toBe(10000.0);
    expect($adjustment->period->label())->toBe('August 2026');
    expect($adjustment->origin_id)->toBe($revision->id);
});

it('pays a generated arrear without a second approval, since SAL-05 calls it automatic', function () {
    disbursedRun(PayrollPeriod::forMonth(2026, 7));

    $revision = propose('2026-06-24');
    app(ApplySalaryRevision::class)->handle($revision);

    // The human decision already happened on the revision.
    expect(PayrollAdjustment::sole()->approvalRequest)->toBeNull();

    $august = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 8));
    $line = $august->payslips()->sole()->lines()->where('code', 'arrear')->sole();

    expect((float) $line->amount)->toBe(10000.0);
});

it('files a downward revision as a recovery, not an arrear', function () {
    disbursedRun(PayrollPeriod::forMonth(2026, 7));

    $revision = propose('2026-06-24', [
        ['salary_component_id' => $this->basic->id, 'value' => 20000],
        ['salary_component_id' => $this->houseRent->id, 'value' => 20000],
    ]);
    app(ApplySalaryRevision::class)->handle($revision);

    // ADJ-08 named six types precisely so money going back to the company is
    // not filed as money owed to the employee.
    expect(PayrollAdjustment::sole()->type)->toBe(PayrollAdjustmentType::Recovery);
    expect((float) $revision->fresh()->arrear_amount)->toBe(-10000.0);
});

it('generates the arrear exactly once, however often the revision is applied', function () {
    disbursedRun(PayrollPeriod::forMonth(2026, 7));

    $revision = propose('2026-06-24');
    app(ApplySalaryRevision::class)->handle($revision);
    app(ApplySalaryRevision::class)->handle($revision->fresh());
    app(ApplySalaryRevision::class)->handle($revision->fresh());

    expect(PayrollAdjustment::count())->toBe(1);
});

it('ignores a run that has not been disbursed, since it can simply be re-run', function () {
    // WFL-05/06: only money that has already left the building becomes an arrear.
    $july = PayrollPeriod::forMonth(2026, 7);
    app(CalculatePayroll::class)->handle($july);

    $revision = propose('2026-06-24');
    app(ApplySalaryRevision::class)->handle($revision);

    expect((float) $revision->fresh()->arrear_amount)->toBe(0.0);
    expect(PayrollAdjustment::count())->toBe(0);
});

it('measures the arrear against the structure actually paid, not against today', function () {
    disbursedRun(PayrollPeriod::forMonth(2026, 7));

    $revision = propose('2026-06-24');
    app(ApplySalaryRevision::class)->handle($revision);

    // The breakdown names the exact run it re-priced and the net that was paid.
    $row = $revision->fresh()->arrear_breakdown[0];
    expect($row)->toHaveKeys(['period', 'payroll_period_id', 'payroll_run_id', 'paid_net', 'difference']);
    // Stored as JSON, so a whole number comes back as an int.
    expect((float) $row['difference'])->toBe(10000.0);
    expect((float) $row['paid_net'])->toBe(50000.0);
});

// --- Access (Correction C5 / ACL-02) ------------------------------------------------

it('keeps increments away from a department manager', function () {
    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.revisions.index'))->assertForbidden();
});

it('renders the increments screen', function () {
    propose('2026-08-01');

    raiseHr()->get(route('hrm.revisions.index'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('hrm/salary/revisions')
            ->where('can_manage', true)
            ->has('revisions', 1)
            ->has('employees', 1)
            ->has('components'));
});

it('proposes an increment through the real HTTP route', function () {
    raiseHr()->post(route('hrm.revisions.store'), [
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-08-01',
        'reason' => 'Annual increment 2026',
        'overtime_eligible' => false,
        'lines' => [
            ['salary_component_id' => $this->basic->id, 'value' => 30000],
            ['salary_component_id' => $this->houseRent->id, 'value' => 30000],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SalaryRevision::count())->toBe(1);
});
