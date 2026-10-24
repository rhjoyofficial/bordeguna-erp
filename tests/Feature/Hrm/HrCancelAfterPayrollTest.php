<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\HrCorrectionWorkflowSeeder;
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
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): cancelling a leave whose
 * payroll is already approved needs HR plus one other approver, never changes
 * the approved run, and lands the difference on the next open payroll.
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
    (new HrCorrectionWorkflowSeeder)->run();
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

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

    // Monday 13 Jul (July cycle: 24 Jun to 23 Jul): approved casual leave, paid.
    $this->casual = LeaveType::where('code', 'casual')->sole();
    LeaveLedgerEntry::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->casual->id, 'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Grant, 'days' => 10, 'occurred_on' => '2026-01-01']);
    $leave = app(RequestLeave::class)->handle($this->employee, $this->casual, '2026-07-13', '2026-07-13', LeaveDayPortion::FullDay, 'Family', $this->hr);
    approveIfPending($leave->approvalRequest, $this->hr);
    approveIfPending($leave->fresh()->approvalRequest, $this->hr);
    app(ApplyLeave::class)->handle($leave->fresh());
    $this->leave = $leave->fresh();
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

it('sends the cancellation to a second person, keeps July, and deducts the day in August', function () {
    expect(($this->day)()->status)->toBe(AttendanceStatus::OnLeave)
        ->and($this->run->payslips()->sole()->lines()->where('code', 'absent')->exists())->toBeFalse();

    ($this->as)()->post(route('hrm.leave.cancel', $this->leave), ['reason_text' => 'He was at work that day'])
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'second person'));

    // Nothing changed yet.
    $request = HrCorrectionRequest::sole();
    expect($this->leave->fresh()->isCancelled())->toBeFalse()
        ->and(PayrollAdjustment::count())->toBe(0)
        ->and($request->action)->toBe(HrCorrectionRequest::CANCEL_LEAVE);

    // The requester cannot be the second person.
    ($this->as)()->post(route('hrm.hr-corrections.approve', $request))->assertForbidden();

    $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.hr-corrections.approve', $request))->assertSessionHas('success');

    $adjustment = PayrollAdjustment::sole();
    expect($this->leave->fresh()->isCancelled())->toBeTrue()
        ->and(LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::Cancelled)->sum('days'))->toEqual(1)
        // July is frozen: the day and the approved run are untouched.
        ->and(($this->day)()->status)->toBe(AttendanceStatus::OnLeave)
        ->and($this->run->fresh()->status->value)->toBe('approved')
        // The difference goes to August as a deduction.
        ->and($adjustment->type)->toBe(PayrollAdjustmentType::Deduction)
        ->and((float) $adjustment->amount)->toBe(1000.0)
        ->and($adjustment->period->label())->toBe('August 2026')
        ->and($request->fresh()->status)->toBe('executed');
});
