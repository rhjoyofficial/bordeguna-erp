<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\RequestPayrollUnlock;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\Actions\ValidatePayrollRun;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Jobs\CalculatePayrollRunJob;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Support\ApprovalInbox;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Cycle Co', 'slug' => 'cycle-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Finance', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.salary.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new PayrollWorkflowSeeder)->run();

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

    $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
    $this->superAdmin->businesses()->attach($this->business->id);

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

    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 25000],
    ]);

    $this->period = PayrollPeriod::forMonth(2026, 7);
});

function cycleHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

function calculatedRun(): PayrollRun
{
    return app(CalculatePayroll::class)->handle(test()->period);
}

// --- WFL-03: pre-calculation validation ---------------------------------------

it('reports every validation problem across every employee, not just the first', function () {
    // Two employees, each missing something different.
    $noSalary = Employee::create([
        'employee_code' => 'BDG-000002', 'first_name' => 'No', 'last_name' => 'Salary',
        'employment_type' => 'permanent', 'status' => 'active', 'bank_account_number' => '999',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $noSalary->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);
    $this->employee->update(['bank_account_number' => null, 'mfs_account_number' => null]);

    $report = app(ValidatePayrollRun::class)->handle($this->period);

    expect($report['issue_count'])->toBe(2);
    expect($report['employees'])->toHaveCount(2);
    expect(implode(' ', collect($report['employees'])->pluck('issues')->flatten()->all()))
        ->toContain('No salary structure')
        ->toContain('bank account');
});

it('refuses to calculate while any validation issue stands', function () {
    $this->employee->update(['bank_account_number' => null]);

    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->status)->toBe(PayrollStatus::Draft);
    expect($run->is_validated)->toBeFalse();
    expect($run->hasValidationIssues())->toBeTrue();
    expect($run->payslips()->count())->toBe(0);
});

it('calculates once the issues are cleared', function () {
    $this->employee->update(['bank_account_number' => null]);
    app(CalculatePayroll::class)->handle($this->period);

    $this->employee->update(['bank_account_number' => '1234567890']);
    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->status)->toBe(PayrollStatus::Calculated);
    expect($run->is_validated)->toBeTrue();
    expect($run->payslips()->count())->toBe(1);
});

// --- WFL-04: queued calculation -------------------------------------------------

it('queues the calculation rather than running it in the request', function () {
    Queue::fake();

    cycleHr()->post(route('hrm.payroll.calculate'), ['year' => 2026, 'month' => 7])
        ->assertRedirect()->assertSessionHasNoErrors();

    Queue::assertPushed(CalculatePayrollRunJob::class);
});

it('restores the tenant and the acting user inside the job', function () {
    $job = new CalculatePayrollRunJob($this->business->id, $this->period->id, $this->hr->id);
    app(BusinessContext::class)->set(0);

    $job->handle(app(BusinessContext::class), app(CalculatePayroll::class));

    $run = $this->period->refresh()->latestRun();
    expect($run->status)->toBe(PayrollStatus::Calculated);
    // Without re-establishing the user the run would credit nobody.
    expect($run->calculated_by)->toBe($this->hr->id);
});

it('exposes progress for a running calculation', function () {
    $run = calculatedRun();

    cycleHr()->get(route('hrm.payroll.progress', $run))
        ->assertOk()
        ->assertJsonPath('status', 'calculated')
        ->assertJsonPath('state', 'done');
});

// --- WFL-02: the lifecycle -------------------------------------------------------

it('walks the full lifecycle from draft to locked', function () {
    $run = calculatedRun();
    expect($run->status)->toBe(PayrollStatus::Calculated);

    $transition = app(TransitionPayrollRun::class);

    $transition->review($run, $this->hr);
    expect($run->fresh()->status)->toBe(PayrollStatus::Reviewed);

    // Approval runs through the shared workflow, not a bespoke flag.
    cycleHr()->post(route('hrm.payroll.approve', $run))->assertRedirect();
    expect($run->fresh()->status)->toBe(PayrollStatus::Approved);

    $transition->disburse($run->fresh(), $this->hr);
    expect($run->fresh()->status)->toBe(PayrollStatus::Disbursed);

    $transition->lock($run->fresh(), $this->hr);
    expect($run->fresh()->status)->toBe(PayrollStatus::Locked);
});

it('refuses a transition the state machine does not allow', function () {
    $run = calculatedRun();

    // Calculated cannot jump straight to Disbursed.
    expect(fn () => app(TransitionPayrollRun::class)->disburse($run, $this->hr))
        ->toThrow(ValidationException::class);
});

it('refuses review while a payslip is blocked', function () {
    $run = calculatedRun();
    $run->update(['blocked_count' => 1]);

    expect(fn () => app(TransitionPayrollRun::class)->review($run, $this->hr))
        ->toThrow(ValidationException::class);
});

it('records who did what and when', function () {
    $run = calculatedRun();
    app(TransitionPayrollRun::class)->review($run, $this->hr);

    expect($run->fresh()->reviewed_by)->toBe($this->hr->id);
    expect($run->fresh()->reviewed_at)->not->toBeNull();
});

it('keeps a rejection as its own state with the reason', function () {
    $run = calculatedRun();
    app(TransitionPayrollRun::class)->review($run, $this->hr);

    cycleHr()->post(route('hrm.payroll.reject', $run), ['note' => 'Overtime looks wrong'])
        ->assertRedirect();

    $run->refresh();
    expect($run->status)->toBe(PayrollStatus::Rejected);
    expect($run->closed_reason)->toBe('Overtime looks wrong');
    expect($run->approvalRequest->status)->toBe(ApprovalStatus::Rejected);
});

// --- WFL-05 / WFL-06: re-runs, versioning and reopen -------------------------------

it('re-runs freely while calculated, replacing rather than accumulating', function () {
    $first = calculatedRun();
    $second = calculatedRun();

    expect($second->id)->toBe($first->id);
    expect(PayrollRun::count())->toBe(1);
    expect($second->payslips()->count())->toBe(1);
});

it('refuses a direct re-run once past calculated', function () {
    $run = calculatedRun();
    app(TransitionPayrollRun::class)->review($run, $this->hr);

    cycleHr()->post(route('hrm.payroll.calculate'), ['year' => 2026, 'month' => 7])
        ->assertRedirect()->assertSessionHas('error');
});

it('reopens an approved payroll as a new version, superseding the old one', function () {
    $run = calculatedRun();
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, $this->hr);
    cycleHr()->post(route('hrm.payroll.approve', $run));

    $next = $transition->reopen($run->fresh(), 'Attendance correction landed');

    expect($next->version)->toBe(2);
    expect($next->status)->toBe(PayrollStatus::Draft);
    expect($next->reopened_from_id)->toBe($run->id);
    // The superseded version keeps its figures and says why it was replaced.
    expect($run->fresh()->status)->toBe(PayrollStatus::Cancelled);
    expect($run->fresh()->closed_reason)->toContain('Reopened as version 2');
});

it('refuses to reopen a disbursed payroll, since the difference becomes an arrear', function () {
    $run = calculatedRun();
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, $this->hr);
    cycleHr()->post(route('hrm.payroll.approve', $run));
    $transition->disburse($run->fresh(), $this->hr);

    expect(fn () => $transition->reopen($run->fresh(), 'Too late'))
        ->toThrow(ValidationException::class);
});

// --- WFL-07: the shared inbox -------------------------------------------------------

it('shows a payroll awaiting approval in the shared inbox', function () {
    $run = calculatedRun();
    app(TransitionPayrollRun::class)->review($run, $this->hr);

    $actionable = app(ApprovalInbox::class)->pendingActionableFor($this->hr);

    expect($actionable)->toHaveCount(1);
    expect($actionable->first()->approvable)->toBeInstanceOf(PayrollRun::class);
});

// --- WFL-08: unlocking a locked payroll ---------------------------------------------

function lockedRun(): PayrollRun
{
    $run = calculatedRun();
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, test()->hr);
    cycleHr()->post(route('hrm.payroll.approve', $run));
    $transition->disburse($run->fresh(), test()->hr);
    $transition->lock($run->fresh(), test()->hr);

    return $run->fresh();
}

it('routes an unlock through three separate authorities', function () {
    $run = lockedRun();

    $unlock = app(RequestPayrollUnlock::class)->handle($run, $this->hr, 'Bank rejected two transfers');

    expect($unlock->approvalRequest->totalStages())->toBe(3);
    expect($unlock->isApplied())->toBeFalse();

    // Owner holds all three permissions out of the box, so it can be walked
    // through end to end — but they remain three distinct decisions.
    cycleHr()->post(route('hrm.payroll.unlock.approve', $unlock))->assertRedirect();
    expect($unlock->fresh()->approvalRequest->current_stage)->toBe(2);

    cycleHr()->post(route('hrm.payroll.unlock.approve', $unlock))->assertRedirect();
    expect($unlock->fresh()->approvalRequest->current_stage)->toBe(3);

    cycleHr()->post(route('hrm.payroll.unlock.approve', $unlock))->assertRedirect();

    expect($unlock->fresh()->isApplied())->toBeTrue();
    // The locked run keeps its figures; a fresh version carries the rework.
    expect($run->fresh()->status)->toBe(PayrollStatus::Locked);
    expect(PayrollRun::where('reopened_from_id', $run->id)->exists())->toBeTrue();
});

it('defines the unlock stages by permission rather than by invented roles', function () {
    // HR Head and Management do not exist as roles in this system, so the
    // stages authorise on permissions that can be granted to whatever roles
    // the business actually has.
    $workflow = ApprovalWorkflow::where('key', 'payroll_unlock')->sole();
    $stages = $workflow->stages()->orderBy('sequence')->get();

    expect($stages->pluck('name')->all())->toBe(['HR Head', 'Finance', 'Management']);
    expect($stages->pluck('allowed_permissions')->flatten()->all())
        ->toBe(['payroll.unlock.hr', 'payroll.unlock.finance', 'payroll.unlock.management']);
    // No stage depends on a role that does not exist.
    expect($stages->pluck('allowed_roles')->flatten()->filter()->all())->toBe([]);
});

it('grants the finance stage to the finance role, the one authority that exists', function () {
    $finance = Role::where('business_id', $this->business->id)->where('name', 'Finance')->sole();

    expect($finance->hasPermissionTo('payroll.unlock.finance'))->toBeTrue();
    expect($finance->hasPermissionTo('payroll.unlock.hr'))->toBeFalse();
});

it('lets a super admin override the chain, but never silently', function () {
    $run = lockedRun();

    $unlock = app(RequestPayrollUnlock::class)->handle(
        $run, $this->superAdmin, 'Payment file rejected by the bank', true, 'Directors approved by phone',
    );

    expect($unlock->isApplied())->toBeTrue();
    expect($unlock->is_emergency_override)->toBeTrue();
    expect($unlock->override_reason)->toBe('Directors approved by phone');
    expect($unlock->approvalRequest)->toBeNull();
});

it('refuses an override from someone who is not a super admin', function () {
    $run = lockedRun();

    expect(fn () => app(RequestPayrollUnlock::class)->handle($run, $this->hr, 'Reason', true, 'Because'))
        ->toThrow(ValidationException::class);
});

it('refuses an override with no stated reason', function () {
    $run = lockedRun();

    expect(fn () => app(RequestPayrollUnlock::class)->handle($run, $this->superAdmin, 'Reason', true, null))
        ->toThrow(ValidationException::class);
});

it('refuses an unlock request for a payroll that is not locked', function () {
    $run = calculatedRun();

    expect(fn () => app(RequestPayrollUnlock::class)->handle($run, $this->hr, 'Not locked yet'))
        ->toThrow(ValidationException::class);
});

// --- Access ---------------------------------------------------------------------------

it('blocks a user without the payroll permission', function () {
    $this->actingAs($this->staffUser)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.payroll.index'))->assertForbidden();

    $this->actingAs($this->staffUser)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.payroll.calculate'), ['year' => 2026, 'month' => 7])->assertForbidden();
});

it('renders the payroll screen with its lifecycle state', function () {
    calculatedRun();

    cycleHr()->get(route('hrm.payroll.index', ['year' => 2026, 'month' => 7]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('hrm/payroll/index')
            ->where('run.status', 'calculated')
            ->where('run.is_rerunnable', true)
            ->has('payslips', 1));
});

// --- The validation report is prose somebody reads before releasing salary ----

/**
 * ⚠ These assert the VERB, not just the number.
 *
 * "2 days of attendance is unresolved" and "2 days of attendance still awaits
 * HR review" both shipped: the noun was pluralised and the verb was not. Every
 * `toContain('2 days')` assertion passed the whole time, which is exactly how
 * the same bug survived once before on the dashboard ("1 employee have punched
 * today"). This is the report Payroll reads before a month's salary is
 * released, so it is the last place broken English belongs.
 */
function attendanceDayFor(array $attributes): void
{
    DailyAttendance::create(array_merge([
        'employee_id' => test()->employee->id,
        'business_id' => test()->business->id,
        'status' => AttendanceStatus::Present,
        'worked_minutes' => 480,
        'payable_minutes' => 480,
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'consequence' => AttendanceConsequence::None,
    ], $attributes));
}

function payrollIssuesText(): string
{
    $report = app(ValidatePayrollRun::class)->handle(test()->period);

    return implode(' ', collect($report['employees'])->pluck('issues')->flatten()->all());
}

it('says one day is unresolved, and two days are', function () {
    attendanceDayFor(['attendance_date' => '2026-07-01', 'holds_payroll' => true]);

    expect(payrollIssuesText())
        ->toContain('1 day of attendance is unresolved')
        ->not->toContain('days of attendance is');

    attendanceDayFor(['attendance_date' => '2026-07-02', 'holds_payroll' => true]);

    expect(payrollIssuesText())
        ->toContain('2 days of attendance are unresolved')
        ->not->toContain('days of attendance is');
});

it('says one day awaits review, and two days await', function () {
    attendanceDayFor(['attendance_date' => '2026-07-01', 'is_provisional' => true]);

    expect(payrollIssuesText())
        ->toContain('1 day of attendance still awaits HR review')
        ->not->toContain('days of attendance still awaits');

    attendanceDayFor(['attendance_date' => '2026-07-02', 'is_provisional' => true]);

    expect(payrollIssuesText())
        ->toContain('2 days of attendance still await HR review')
        ->not->toContain('days of attendance still awaits');
});

// --- Warnings: visible, never blocking (Joy, 2026-08-01) ---------------------

/**
 * ⚠⚠ The property that matters most here is what warnings DO NOT do.
 *
 * A missing attendance record is expected during go-live, a partial period, a
 * mid-month joiner, a historical import or salary-only payroll — and is also
 * exactly what a stalled scheduler looks like. So it must be seen and must not
 * stop anybody being paid.
 */
it('warns about unrecorded attendance days without blocking the calculation', function () {
    $this->travelTo('2026-08-01');

    $run = app(CalculatePayroll::class)->handle($this->period);

    // The whole point: it calculated anyway.
    expect($run->status)->toBe(PayrollStatus::Calculated)
        ->and($run->payslips()->count())->toBe(1)
        ->and($run->hasValidationIssues())->toBeFalse()
        ->and($run->hasValidationWarnings())->toBeTrue();

    expect(implode(' ', collect($run->validation_report['warnings'])->pluck('warnings')->flatten()->all()))
        ->toContain('elapsed days of this period have no attendance record')
        ->toContain('please verify before approval');
});

it('keeps warnings out of the blocking channel entirely', function () {
    $this->travelTo('2026-08-01');

    $report = app(ValidatePayrollRun::class)->handle($this->period);

    expect($report['issue_count'])->toBe(0)
        ->and($report['employees'])->toBe([])
        ->and($report['warning_count'])->toBeGreaterThan(0);
});

it('says nothing about days of the period that have not elapsed yet', function () {
    // Two days in, both recorded. Nothing is missing; the rest is not yet due.
    $this->travelTo('2026-06-25');

    attendanceDayFor(['attendance_date' => '2026-06-24']);
    attendanceDayFor(['attendance_date' => '2026-06-25']);

    $report = app(ValidatePayrollRun::class)->handle($this->period);

    expect($report['warning_count'])->toBe(0);
});

it('asks nothing of the days before an employee joined', function () {
    $this->travelTo('2026-08-01');

    $this->employee->update(['joining_date' => '2026-07-22']);
    attendanceDayFor(['attendance_date' => '2026-07-22']);
    attendanceDayFor(['attendance_date' => '2026-07-23']);

    expect(app(ValidatePayrollRun::class)->handle($this->period)['warning_count'])->toBe(0);
});

it('counts a single unrecorded day in the singular', function () {
    $this->travelTo('2026-08-01');

    $this->employee->update(['joining_date' => '2026-07-22']);
    attendanceDayFor(['attendance_date' => '2026-07-22']);

    expect(implode(' ', collect(app(ValidatePayrollRun::class)->handle($this->period)['warnings'])
        ->pluck('warnings')->flatten()->all()))
        ->toContain('1 elapsed day of this period has no attendance record')
        ->not->toContain('days of this period has');
});

it('still warns on a run that is blocked for a different reason', function () {
    $this->travelTo('2026-08-01');
    $this->employee->update(['bank_account_number' => null]);

    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->hasValidationIssues())->toBeTrue()
        ->and($run->hasValidationWarnings())->toBeTrue();
});
