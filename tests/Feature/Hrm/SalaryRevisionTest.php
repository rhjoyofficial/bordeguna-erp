<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplySalaryRevision;
use App\Modules\Hrm\Actions\AssignSchedule;
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
use App\Support\RecordTimeline;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
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

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
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
    approveIfPending($run->fresh()->approvalRequest, test()->hr);
    $transition->markApproved($run->fresh(), test()->hr);
    $transition->disburse($run->fresh(), test()->hr);

    return $run->fresh();
}

// --- ADJ-06: proposal before fact ------------------------------------------------

it('creates no salary structure while a proposal is pending', function () {
    propose('2026-07-24');

    // ADJ-06 ticked Proposal → Approval → Effective revision and left HR direct
    // entry blank. A structure that exists is a structure payroll would pay.
    expect(SalaryStructure::where('employee_id', $this->employee->id)->count())->toBe(1);
    expect(SalaryRevision::sole()->isApplied())->toBeFalse();
});

it('opens the new structure only once approved', function () {
    $revision = propose('2026-07-24');

    raiseHr()->post(route('hrm.revisions.approve', $revision))->assertRedirect()->assertSessionHasNoErrors();

    $revision->refresh();
    expect($revision->isApplied())->toBeTrue();
    expect($revision->salary_structure_id)->not->toBeNull();

    $structures = SalaryStructure::historyFor($this->employee->id);
    expect($structures)->toHaveCount(2);
    // P1 close-old-open-new: the old row is closed, never overwritten.
    expect($structures->last()->effective_to->toDateString())->toBe('2026-07-23');
});

it('creates nothing at all when a proposal is rejected', function () {
    $revision = propose('2026-07-24');

    raiseHr()->post(route('hrm.revisions.reject', $revision))->assertRedirect();

    expect($revision->fresh()->isApplied())->toBeFalse();
    expect(SalaryStructure::where('employee_id', $this->employee->id)->count())->toBe(1);
});

it('carries overtime eligibility onto the structure it opens', function () {
    $revision = propose('2026-07-24', eligible: true);
    raiseHr()->post(route('hrm.revisions.approve', $revision));

    expect(SalaryStructure::activeFor($this->employee->id, '2026-08-05')->overtime_eligible)->toBeTrue();
});

it('refuses a second proposal while one is still pending', function () {
    propose('2026-07-24');

    expect(fn () => propose('2026-08-24'))->toThrow(ValidationException::class);
});

it('refuses a proposal dated on or before the structure it revises', function () {
    expect(fn () => propose('2025-01-01'))->toThrow(ValidationException::class);
    expect(fn () => propose('2024-06-01'))->toThrow(ValidationException::class);
});

it('refuses a proposal without exactly one basic component', function () {
    expect(fn () => propose('2026-07-24', [
        ['salary_component_id' => $this->houseRent->id, 'value' => 30000],
    ]))->toThrow(ValidationException::class);
});

it('puts a pending revision in the shared approval inbox', function () {
    propose('2026-07-24');

    $actionable = app(ApprovalInbox::class)->pendingActionableFor($this->hr);

    expect($actionable)->toHaveCount(1);
    expect($actionable->first()->approvable)->toBeInstanceOf(SalaryRevision::class);
});

// --- SAL-05: retroactive arrears --------------------------------------------------

it('produces no arrear when the revision starts in the future', function () {
    disbursedRun(PayrollPeriod::forMonth(2026, 7));

    $revision = propose('2026-07-24');
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

it('skips a month already reviewed or approved when posting the arrear forward', function () {
    // ⚠ Audit 13 Oct 2026: isOpen() counted a reviewed or approved month as
    // open, and an arrear posted there was never paid. September is approved
    // here, so the arrear has to land in October.
    disbursedRun(PayrollPeriod::forMonth(2026, 7));
    PayrollPeriod::forMonth(2026, 8)->runs()->create(['version' => 1, 'status' => 'reviewed']);
    PayrollPeriod::forMonth(2026, 9)->runs()->create(['version' => 1, 'status' => 'approved']);

    $revision = propose('2026-06-24');
    app(ApplySalaryRevision::class)->handle($revision);

    expect(PayrollAdjustment::sole()->period->label())->toBe('October 2026');
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
    propose('2026-07-24');

    raiseHr()->get(route('hrm.revisions.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/salary/revisions')
            ->where('can_manage', true)
            ->has('revisions', 1)
            ->has('employees', 1)
            ->has('components'));
});

it('proposes an increment through the real HTTP route', function () {
    raiseHr()->post(route('hrm.revisions.store'), [
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-07-24',
        'reason' => 'Annual increment 2026',
        'overtime_eligible' => false,
        'lines' => [
            ['salary_component_id' => $this->basic->id, 'value' => 30000],
            ['salary_component_id' => $this->houseRent->id, 'value' => 30000],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(SalaryRevision::count())->toBe(1);
});

it('rolls the approval back when applying the revision fails', function () {
    $revision = propose('2026-07-24');
    $this->mock(ApplySalaryRevision::class)->shouldReceive('handle')->andThrow(new RuntimeException('apply failed'));

    raiseHr()->post(route('hrm.revisions.approve', $revision))->assertStatus(500);

    expect($revision->fresh()->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        ->and($revision->fresh()->isApplied())->toBeFalse();
});

// --- Rule #16 (14 Oct 2026): a revision starts only on a cycle start (the 24th) ---

it('refuses a mid-cycle effective date and names the next 24th instead of rounding it', function () {
    try {
        propose('2026-08-01');
        $this->fail('A mid-cycle date was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors()['effective_from'][0])->toContain('2026-08-24');
        // The form offers this date back so the proposer can confirm it.
        expect($e->errors()['suggested_effective_from'][0])->toBe('2026-08-24');
    }

    // Never silently rounded: nothing was stored.
    expect(SalaryRevision::count())->toBe(0);
});

it('accepts the offered 24th and keeps the date that was first asked for', function () {
    $revision = app(RequestSalaryRevision::class)->handle(
        $this->employee,
        '2026-08-24',
        [
            ['salary_component_id' => $this->basic->id, 'value' => 30000],
            ['salary_component_id' => $this->houseRent->id, 'value' => 30000],
        ],
        'Annual increment 2026',
        $this->hr,
        requestedFrom: '2026-08-01',
    );

    expect($revision->effective_from->toDateString())->toBe('2026-08-24');
    expect($revision->requested_effective_from->toDateString())->toBe('2026-08-01');
});

it('refuses a requested date that the offered 24th does not follow', function () {
    expect(fn () => app(RequestSalaryRevision::class)->handle(
        $this->employee,
        '2026-08-24',
        [['salary_component_id' => $this->basic->id, 'value' => 30000]],
        'Annual increment 2026',
        $this->hr,
        requestedFrom: '2026-06-01',
    ))->toThrow(ValidationException::class);
});

it('offers the next 24th back through the real HTTP route', function () {
    raiseHr()->post(route('hrm.revisions.store'), [
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-09-10',
        'reason' => 'Annual increment 2026',
        'lines' => [
            ['salary_component_id' => $this->basic->id, 'value' => 30000],
            ['salary_component_id' => $this->houseRent->id, 'value' => 30000],
        ],
    ])->assertSessionHasErrors(['effective_from', 'suggested_effective_from']);

    raiseHr()->post(route('hrm.revisions.store'), [
        'employee_id' => $this->employee->id,
        'effective_from' => '2026-09-24',
        'requested_effective_from' => '2026-09-10',
        'reason' => 'Annual increment 2026',
        'lines' => [
            ['salary_component_id' => $this->basic->id, 'value' => 30000],
            ['salary_component_id' => $this->houseRent->id, 'value' => 30000],
        ],
    ])->assertSessionHasNoErrors();

    expect(SalaryRevision::sole()->requested_effective_from->toDateString())->toBe('2026-09-10');
});

// --- Rule #22 (14 Oct 2026): self-approval stays allowed, with a reason and a mark --

it('asks for a reason before somebody approves their own raise, and does not block it', function () {
    $this->employee->update(['user_id' => $this->hr->id]);
    $revision = propose('2026-07-24');

    raiseHr()->post(route('hrm.revisions.approve', $revision))
        ->assertSessionHasErrors('self_approval_reason');
    expect($revision->fresh()->isApplied())->toBeFalse();

    raiseHr()->post(route('hrm.revisions.approve', $revision), ['note' => 'Owner raise agreed with the accountant'])
        ->assertSessionHasNoErrors();

    $decision = $revision->fresh()->approvalRequest->decisions()->sole();
    expect($revision->fresh()->isApplied())->toBeTrue()
        ->and($decision->self_approved)->toBeTrue()
        ->and($decision->note)->toBe('Owner raise agreed with the accountant');

    // Visible in the record's story and in the approvals history.
    expect(collect(RecordTimeline::for($revision->fresh()))->pluck('what')->implode(' | '))->toContain('self-approved');
    raiseHr()->get(route('approvals.history'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('requests.0.self_approved', true));
});

it('needs no reason and leaves no mark when the raise is somebody else\'s', function () {
    $revision = propose('2026-07-24');

    raiseHr()->post(route('hrm.revisions.approve', $revision))->assertSessionHasNoErrors();

    expect($revision->fresh()->approvalRequest->decisions()->sole()->self_approved)->toBeFalse();
});

it('tells the inbox card that the approver would be approving their own request', function () {
    $this->employee->update(['user_id' => $this->hr->id]);
    propose('2026-07-24');

    raiseHr()->get(route('approvals.index'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('requests.0.self_approval', true));
});

it('treats approving a payroll that pays the approver as a self-approval', function () {
    $this->employee->update(['user_id' => $this->hr->id]);
    $run = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 7));
    app(TransitionPayrollRun::class)->review($run, $this->hr);

    $approval = $run->fresh()->approvalRequest;
    expect(fn () => $approval->approve($this->hr))->toThrow(ValidationException::class);

    $approval->fresh()->approve($this->hr, 'Only approver this month');
    expect($approval->fresh()->decisions()->latest('id')->first()->self_approved)->toBeTrue();
});
