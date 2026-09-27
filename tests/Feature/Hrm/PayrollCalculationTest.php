<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\ValidatePayrollRun;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Run Co', 'slug' => 'run-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.payroll.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.salary.manage', 'hrm.payroll.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    (new SalaryComponentSeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001',
        'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
        // WFL-03 requires payment details before a payroll may calculate.
        'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    // WFL-03 also requires a shift and calendar, since without one there is no
    // way to say which days were working days.
    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $shift->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    // PAY-13's structure: Gross 50,000 = Basic 25,000 + House Rent 20,000 + Medical 5,000.
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 25000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 20000],
        ['salary_component_id' => SalaryComponent::where('code', 'medical')->sole()->id, 'value' => 5000],
    ], 'PAY-13 structure');

    $this->period = PayrollPeriod::forMonth(2026, 7);
});

function payrollHr()
{
    return test()->actingAs(test()->hr)->withSession(['current_business_id' => test()->business->id]);
}

/**
 * Writes a processed attendance day exactly as the Phase-2b engine would have
 * left it. Payroll reads these; it never derives them.
 */
function attendanceDay(string $date, AttendanceStatus $status, array $overrides = []): DailyAttendance
{
    return DailyAttendance::create([
        'employee_id' => test()->employee->id,
        'attendance_date' => $date,
        'status' => $status,
        'consequence' => $overrides['consequence'] ?? AttendanceConsequence::None,
        'worked_minutes' => $overrides['worked_minutes'] ?? 480,
        'payable_minutes' => $overrides['payable_minutes'] ?? 480,
        'late_minutes' => $overrides['late_minutes'] ?? 0,
        'is_provisional' => false,
        'holds_payroll' => $overrides['holds_payroll'] ?? false,
        'processed_at' => now(),
    ]);
}

/**
 * PAY-13's month: 25 present, 1 absent, 2 paid leave, 4 late arrivals totalling
 * 38 minutes that carry a warning only.
 */
function pay13Attendance(): void
{
    $date = Carbon::parse('2026-06-24');
    $late = [10, 9, 12, 7]; // 38 minutes across four days

    for ($i = 0; $i < 25; $i++) {
        $lateMinutes = $i < 4 ? $late[$i] : 0;
        attendanceDay($date->toDateString(), $lateMinutes > 0 ? AttendanceStatus::Late : AttendanceStatus::Present, [
            'late_minutes' => $lateMinutes,
            // POL-03: the first three are excused and the fourth is a warning,
            // so none of them deducts.
            'consequence' => $lateMinutes > 0 && $i === 3 ? AttendanceConsequence::Warning : AttendanceConsequence::None,
        ]);
        $date->addDay();
    }

    attendanceDay($date->toDateString(), AttendanceStatus::Absent, ['worked_minutes' => 0, 'payable_minutes' => 0]);
    $date->addDay();

    attendanceDay($date->toDateString(), AttendanceStatus::OnLeave, ['worked_minutes' => 0, 'payable_minutes' => 0]);
    $date->addDay();
    attendanceDay($date->toDateString(), AttendanceStatus::OnLeave, ['worked_minutes' => 0, 'payable_minutes' => 0]);
}

// --- The acceptance test, through the real pipeline ---------------------------

it('reproduces PAY-13 from real attendance and salary records', function () {
    pay13Attendance();

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->where('employee_id', $this->employee->id)->sole();

    expect((float) $payslip->gross_salary)->toBe(50000.00);
    // 1 absent day at 50,000 ÷ 30.
    expect((float) $payslip->total_deductions)->toBe(1666.67);
    // No overtime yet — approved overtime assignments arrive in Phase 3d — so
    // this is gross alone. Adding 8 hours and the 2,000 allowance gives the
    // 51,166.66 the pure acceptance test asserts.
    expect((float) $payslip->net)->toBe(48333.33);
    expect($payslip->is_blocked)->toBeFalse();
});

it('reads attendance facts rather than recomputing them', function () {
    pay13Attendance();

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->sole();
    $facts = $payslip->calculation['config'];

    // The absent line came from a DailyAttendance row, not from punches.
    $absent = collect($payslip->calculation['lines'])->firstWhere('code', 'absent');
    expect($absent['source'])->toBe('attendance');
    // Compared as a float: JSON renders 1.0 as 1.
    expect((float) $absent['quantity'])->toBe(1.0);
    expect($facts['divisor_days'])->toBe(30);
});

it('does not deduct for the four late arrivals, because the policy did not', function () {
    pay13Attendance();

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->sole();

    expect($payslip->lines()->where('code', 'late_early')->exists())->toBeFalse();
    expect(implode(' ', $payslip->explanation()))->toContain('no salary consequence');
});

it('prices a deducting consequence when the policy did decide one', function () {
    pay13Attendance();
    // A fifth late arrival, which POL-03's ladder escalates to a deduction.
    // 22 July: the fixture's 25 present days run to 18 July, then absent on the
    // 19th and leave on the 20th and 21st.
    attendanceDay('2026-07-22', AttendanceStatus::Late, [
        'late_minutes' => 11,
        'consequence' => AttendanceConsequence::Deduction,
    ]);

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->sole();

    expect((float) $payslip->lines()->where('code', 'late_early')->sole()->amount)->toBe(1666.67);
});

// --- Period, storage and provenance --------------------------------------------

it('opens the payroll month from the 24th to the 23rd', function () {
    expect($this->period->start_date->toDateString())->toBe('2026-06-24');
    expect($this->period->end_date->toDateString())->toBe('2026-07-23');
    expect($this->period->divisor_days)->toBe(30);
});

it('stores a formula and source on every payslip line', function () {
    pay13Attendance();

    $run = app(CalculatePayroll::class)->handle($this->period);

    foreach ($run->payslips()->sole()->lines as $line) {
        expect($line->formula)->not->toBeEmpty();
        expect($line->source)->not->toBeEmpty();
    }
});

it('stores the derivation rather than something to be recomputed later', function () {
    pay13Attendance();

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->sole();

    expect($payslip->calculation)->toHaveKeys(['lines', 'notes', 'config', 'period']);
    expect($payslip->explanation())->not->toBeEmpty();
    // The salary structure that produced it is pinned, so a later revision
    // cannot silently change what this month was paid on.
    expect($payslip->salary_structure_id)->not->toBeNull();
});

it('is unaffected by a salary revision dated after the period', function () {
    pay13Attendance();
    $before = app(CalculatePayroll::class)->handle($this->period)->payslips()->sole()->net;

    app(SaveSalaryStructure::class)->handle($this->employee, '2026-08-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 40000],
    ], 'Increment');

    $after = app(CalculatePayroll::class)->handle($this->period)->payslips()->sole()->net;

    expect((float) $after)->toBe((float) $before);
});

// --- Blocking and re-runs --------------------------------------------------------

it('refuses to calculate at all while a missing punch is unresolved', function () {
    pay13Attendance();
    attendanceDay('2026-07-22', AttendanceStatus::MissingPunch, ['holds_payroll' => true]);

    $run = app(CalculatePayroll::class)->handle($this->period);

    // WFL-03 catches this before any calculation begins, which is stricter than
    // PAY-09's per-payslip block and stops HR reviewing figures that were never
    // going to be payable.
    expect($run->hasValidationIssues())->toBeTrue();
    expect($run->payslips()->count())->toBe(0);
    expect(implode(' ', collect($run->validation_report['employees'])->pluck('issues')->flatten()->all()))
        ->toContain('unresolved');
});

it('replaces the payslips when a draft run is recalculated', function () {
    pay13Attendance();
    $action = app(CalculatePayroll::class);

    $first = $action->handle($this->period);
    $second = $action->handle($this->period);

    // WFL-05 allows a free re-run while Calculated, and it must replace rather
    // than accumulate.
    expect($second->id)->toBe($first->id);
    expect($second->version)->toBe(1);
    expect(PayrollRun::count())->toBe(1);
    expect($second->payslips()->count())->toBe(1);
});

it('totals the run across its payslips', function () {
    pay13Attendance();

    $run = app(CalculatePayroll::class)->handle($this->period);

    expect($run->employee_count)->toBe(1);
    expect((float) $run->total_earnings)->toBe(50000.00);
    expect((float) $run->total_deductions)->toBe(1666.67);
    expect((float) $run->total_net)->toBe(48333.33);
    expect($run->status)->toBe(PayrollStatus::Calculated);
});

it('refuses to calculate anyone while one employee has no salary structure', function () {
    $other = Employee::create([
        'employee_code' => 'BDG-000002',
        'first_name' => 'Rahim', 'last_name' => 'Test',
        'employment_type' => 'permanent', 'status' => 'active',
        'bank_account_number' => '999',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $other->id, 'office_id' => $this->office->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $run = app(CalculatePayroll::class)->handle($this->period);

    // WFL-03 gates the whole run, not the one line. Half a payroll is worse
    // than none, because somebody will pay from it.
    expect($run->hasValidationIssues())->toBeTrue();
    expect($run->payslips()->count())->toBe(0);
    expect(implode(' ', collect($run->validation_report['employees'])->pluck('issues')->flatten()->all()))
        ->toContain('No salary structure');
});

it('does not leak payroll across businesses', function () {
    pay13Attendance();
    app(CalculatePayroll::class)->handle($this->period);

    $other = Business::create(['name' => 'Other Run', 'slug' => 'other-run']);
    app(BusinessContext::class)->set($other->id);

    expect(PayrollRun::count())->toBe(0);
    expect(PayrollPeriod::count())->toBe(0);
});

it('puts a mid-cycle leaver on the run and leaves out someone who left before it', function () {
    // Left partway through the cycle: still paid for the days worked.
    $leaver = Employee::create([
        'employee_code' => 'BDG-000002', 'first_name' => 'Left', 'last_name' => 'Midway',
        'employment_type' => 'permanent', 'status' => 'separated',
        'joining_date' => '2025-01-01', 'separation_date' => $this->period->start_date->copy()->addDays(4)->toDateString(),
        'bank_account_number' => '2222222222',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $leaver->id, 'office_id' => $this->office->id, 'is_primary' => true,
        'effective_from' => '2025-01-01', 'effective_to' => $this->period->start_date->copy()->addDays(4)->toDateString(),
    ]);

    // Left before this cycle began: not on the run at all.
    $gone = Employee::create([
        'employee_code' => 'BDG-000003', 'first_name' => 'Long', 'last_name' => 'Gone',
        'employment_type' => 'permanent', 'status' => 'separated',
        'joining_date' => '2025-01-01', 'separation_date' => $this->period->start_date->copy()->subDay()->toDateString(),
        'bank_account_number' => '3333333333',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $gone->id, 'office_id' => $this->office->id, 'is_primary' => true,
        'effective_from' => '2025-01-01', 'effective_to' => $this->period->start_date->copy()->subDay()->toDateString(),
    ]);

    $codes = app(ValidatePayrollRun::class)->population($this->period)->pluck('employee_code');

    expect($codes)->toContain('BDG-000001')
        ->and($codes)->toContain('BDG-000002')
        ->and($codes)->not->toContain('BDG-000003');
});
