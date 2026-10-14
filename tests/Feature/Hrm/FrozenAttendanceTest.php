<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rule #18 (approved 14 Oct 2026): once payroll for a cycle is Approved,
 * attendance for that cycle is frozen. Later changes keep the approved days
 * as they were and become an arrear or a deduction on the next open payroll.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Freeze Co', 'slug' => 'freeze-co']);
    app(BusinessContext::class)->set($this->business->id);

    $permissions = ['hrm.attendance.manage', 'hrm.attendance.waive', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage', 'hrm.leave.manage', 'hrm.leave.approve'];
    foreach ($permissions as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo($permissions);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new LeaveTypeSeeder)->run();
    (new LeaveWorkflowSeeder)->run();
    (new PayrollWorkflowSeeder)->run();
    (new PayrollInputWorkflowSeeder)->run();

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $this->calendar = HolidayCalendar::create(['name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true]);
    AttendancePolicy::create(['name' => 'Standard', 'version' => 1, 'config' => AttendancePolicySeeder::defaultConfig(), 'effective_from' => '2026-01-01']);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed', 'shift_id' => $shift->id,
        'holiday_calendar_id' => $this->calendar->id, 'effective_from' => '2025-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000001', 'first_name' => 'Karim', 'last_name' => 'Ahmed',
        'employment_type' => 'permanent', 'status' => 'active', 'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create(['employee_id' => $this->employee->id, 'office_id' => $office->id, 'is_primary' => true, 'effective_from' => '2025-01-01']);

    // Gross 30,000, so one absent day costs 30,000 / 30 = 1,000.
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 15000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 15000],
    ], 'Opening structure');

    // Monday 13 Jul (July cycle: 24 Jun to 23 Jul), no punches: Absent.
    app(ProcessAttendance::class)->forDay($this->employee, '2026-07-13');
    DailyAttendance::query()->update(['finalized_at' => now(), 'is_provisional' => false]);

    $this->july = PayrollPeriod::forMonth(2026, 7);
    $run = app(CalculatePayroll::class)->handle($this->july);
    expect($run->validation_report['issue_count'] ?? 0)->toBe(0, json_encode($run->validation_report));
    $transition = app(TransitionPayrollRun::class);
    $transition->review($run, $this->hr);
    approveIfPending($run->fresh()->approvalRequest, $this->hr);
    $transition->markApproved($run->fresh(), $this->hr);
    $this->run = $run->fresh();

    $this->day = fn () => DailyAttendance::where('employee_id', $this->employee->id)->onDate('2026-07-13')->sole();
    $this->as = fn () => $this->actingAs($this->hr)->withSession(['current_business_id' => $this->business->id]);
});

it('starts from an approved July that deducted the absent day', function () {
    expect($this->run->status->value)->toBe('approved')
        ->and((float) $this->run->payslips()->sole()->lines()->where('code', 'absent')->sole()->amount)->toBe(1000.0)
        ->and(($this->day)()->status)->toBe(AttendanceStatus::Absent);
});

it('keeps the frozen day and pays a forgiven absence back as an arrear next month', function () {
    ($this->as)()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id,
        'attendance_date' => '2026-07-13',
        'reason' => 'Flood, office closed for staff on that road',
    ])->assertSessionHas('warning', fn (string $m) => str_contains($m, 'already approved') && str_contains($m, 'August 2026'));

    $adjustment = PayrollAdjustment::sole();

    expect($adjustment->type)->toBe(PayrollAdjustmentType::Arrear)
        ->and((float) $adjustment->amount)->toBe(1000.0)
        ->and($adjustment->period->label())->toBe('August 2026')
        ->and($adjustment->origin_id)->toBe($this->run->id)
        ->and($adjustment->source_key)->toStartWith('frozen_attendance:');

    // The approved run and its day are untouched.
    expect(($this->day)()->status)->toBe(AttendanceStatus::Absent)
        ->and($this->run->fresh()->status->value)->toBe('approved')
        ->and((float) $this->run->payslips()->sole()->lines()->where('code', 'absent')->sole()->amount)->toBe(1000.0);

    // And August actually pays it, with no second approval.
    $august = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 8));
    expect((float) $august->payslips()->sole()->lines()->where('code', 'arrear')->sole()->amount)->toBe(1000.0);
});

it('takes the arrear back as a deduction when the forgiveness is undone, and never twice', function () {
    ($this->as)()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id, 'attendance_date' => '2026-07-13', 'reason' => 'Flood',
    ]);

    ($this->as)()->post(route('hrm.attendance.waivers.revoke', AttendanceWaiver::sole()))->assertSessionHas('warning');

    $rows = PayrollAdjustment::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->type)->toBe(PayrollAdjustmentType::Deduction)
        ->and((float) $rows[1]->amount)->toBe(1000.0);

    // Nothing new since: a forced recompute settles nothing more.
    app(ProcessAttendance::class)->forDay($this->employee, '2026-07-13', force: true);
    expect(PayrollAdjustment::count())->toBe(2);
});

it('settles a leave approved for a frozen day instead of re-judging the day', function () {
    $earned = LeaveType::where('code', 'earned')->sole();
    LeaveLedgerEntry::create([
        'employee_id' => $this->employee->id, 'leave_type_id' => $earned->id, 'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Grant, 'days' => 10, 'occurred_on' => '2026-01-01',
    ]);

    $request = app(RequestLeave::class)->handle(
        $this->employee, $earned, '2026-07-13', '2026-07-13', LeaveDayPortion::FullDay, 'Sick', $this->hr,
    );
    app(ApplyLeave::class)->handle($request);

    expect(($this->day)()->status)->toBe(AttendanceStatus::Absent);

    $adjustment = PayrollAdjustment::sole();
    expect($adjustment->type)->toBe(PayrollAdjustmentType::Arrear)
        ->and((float) $adjustment->amount)->toBe(1000.0);
});

it('leaves a frozen day alone on an unforced run and writes nothing', function () {
    expect(app(ProcessAttendance::class)->forDay($this->employee, '2026-07-13'))->toBeNull()
        ->and(PayrollAdjustment::count())->toBe(0);
});

it('settles a holiday added later for an approved cycle and says so', function () {
    ($this->as)()->post(route('hrm.calendars.holidays.store', $this->calendar), ['date' => '2026-07-13', 'name' => 'Flood closure'])
        ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'already approved') && str_contains($m, 'for 1 person'));

    expect(($this->day)()->status)->toBe(AttendanceStatus::Absent)
        ->and((float) PayrollAdjustment::sole()->amount)->toBe(1000.0);
});

it('lands the adjustment on the first month that still takes inputs', function () {
    PayrollPeriod::forMonth(2026, 8)->runs()->create(['version' => 1, 'status' => 'approved']);

    ($this->as)()->post(route('hrm.attendance.waivers.store'), [
        'employee_id' => $this->employee->id, 'attendance_date' => '2026-07-13', 'reason' => 'Flood',
    ]);

    expect(PayrollAdjustment::sole()->period->label())->toBe('September 2026');
});

it('does not freeze a cycle whose payroll is only reviewed', function () {
    PayrollRun::whereKey($this->run->id)->update(['status' => 'reviewed']);

    HolidayCalendar::query()->first()->holidays()->create(['date' => '2026-07-13', 'name' => 'Flood closure']);
    app(ProcessAttendance::class)->forDay($this->employee, '2026-07-13', force: true);

    expect(($this->day)()->status)->toBe(AttendanceStatus::Holiday)
        ->and(PayrollAdjustment::count())->toBe(0);
});
