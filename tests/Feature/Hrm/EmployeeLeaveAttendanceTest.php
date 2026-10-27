<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\DecideLeavePay;
use App\Modules\Hrm\Actions\MakeDayUnpaid;
use App\Modules\Hrm\Actions\RequestLeave;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\HrCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\LeaveWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
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
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\EmployeeLeaveAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ 27 Oct 2026 (Joy: "from where hr can see an employee's all the things,
 * like leave, how much left, paid"): the employee page's leave and attendance
 * tab (EmployeeLeaveAttendance). Shoykod: 10 sick days granted, a three-day
 * sick leave in September with two of its days made unpaid, October's
 * attendance with one worked day made unpaid, and a waiting addition.
 */
beforeEach(function () {
    $this->withoutVite();
    Carbon::setTestNow('2026-10-27 10:00:00');

    $this->business = Business::create(['name' => 'Leave Tab Co', 'slug' => 'leave-tab-co']);
    app(BusinessContext::class)->set($this->business->id);

    $permissions = ['hrm.employees.manage', 'hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage', 'hrm.leave.manage', 'hrm.leave.approve'];
    foreach ($permissions as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('hrm.leave.approve');
    // Manages staff, leave and attendance, but does not read payroll.
    Role::firstOrCreate(['name' => 'HR clerk', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['hrm.employees.manage', 'hrm.leave.manage', 'hrm.attendance.manage']);
    // Manages staff only.
    Role::firstOrCreate(['name' => 'Desk', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo(['hrm.employees.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new LeaveTypeSeeder)->run();
    (new LeaveWorkflowSeeder)->run();
    (new PayrollWorkflowSeeder)->run();
    (new PayrollInputWorkflowSeeder)->run();
    (new HrCorrectionWorkflowSeeder)->run();

    $user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->hr = $user('Owner');
    $this->manager = $user('Manager');
    $this->clerk = $user('HR clerk');
    $this->desk = $user('Desk');

    $office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create(['name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true]);
    AttendancePolicy::create(['name' => 'Standard', 'version' => 1, 'config' => AttendancePolicySeeder::defaultConfig(), 'effective_from' => '2026-01-01']);
    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed', 'shift_id' => $shift->id,
        'holiday_calendar_id' => $calendar->id, 'effective_from' => '2025-01-01',
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000007', 'first_name' => 'Shoykod', 'last_name' => 'Ali',
        'employment_type' => 'permanent', 'status' => 'active', 'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create(['employee_id' => $this->employee->id, 'office_id' => $office->id, 'is_primary' => true, 'effective_from' => '2025-01-01']);

    // Gross 30,000, so one day of pay is 1,000.
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 15000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 15000],
    ], 'Opening structure');

    $this->sick = LeaveType::where('code', 'sick')->sole();
    LeaveLedgerEntry::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->sick->id, 'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Grant, 'days' => 10, 'occurred_on' => '2026-01-01']);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    // Monday 14 to Wednesday 16 Sep 2026, approved and applied, then two of
    // its three days made unpaid.
    $leave = app(RequestLeave::class)->handle($this->employee, $this->sick, '2026-09-14', '2026-09-16', LeaveDayPortion::FullDay, 'Fever', $this->hr);
    approveIfPending($leave->approvalRequest, $this->manager);
    approveIfPending($leave->fresh()->approvalRequest, $this->hr);
    $this->leave = app(DecideLeavePay::class)->handle(app(ApplyLeave::class)->handle($leave->fresh()), $this->hr, 2, 'Absent without telling anyone');

    // October's payroll month (24 Sep to 23 Oct).
    $day = fn (string $date, AttendanceStatus $status, bool $provisional = false) => DailyAttendance::create([
        'employee_id' => $this->employee->id, 'attendance_date' => $date, 'status' => $status,
        'consequence' => AttendanceConsequence::None, 'worked_minutes' => $status === AttendanceStatus::Absent ? 0 : 480,
        'is_provisional' => $provisional, 'holds_payroll' => false,
    ]);
    $day('2026-10-19', AttendanceStatus::MissingPunch, true);
    $day('2026-10-20', AttendanceStatus::Present);
    $day('2026-10-21', AttendanceStatus::Late);
    $day('2026-10-22', AttendanceStatus::HalfDay);
    $day('2026-10-23', AttendanceStatus::Absent);
    // 24 Oct already sits in November's payroll month.
    $day('2026-10-24', AttendanceStatus::Present);

    $this->penalty = app(MakeDayUnpaid::class)->handle($this->employee, '2026-10-20', false, 'Used the phone on the packing floor', $this->hr);
    approveIfPending($this->penalty->approvalRequest, $this->hr);

    // A hand-typed addition still waiting for its approval.
    $this->addition = PayrollAdjustment::create([
        'employee_id' => $this->employee->id, 'type' => PayrollAdjustmentType::Addition, 'amount' => 500,
        'payroll_period_id' => PayrollPeriod::forMonth(2026, 11)->id, 'reason' => 'Eid travel', 'requested_by' => $this->hr->id,
    ]);
    $this->addition->submitForApproval($this->hr);
});

afterEach(fn () => Carbon::setTestNow());

it('shows leave left, leave requests with paid and unpaid days, attendance per payroll month and pay changes', function () {
    ($this->as)($this->hr)->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leave_attendance.leave_year', 2026)
            // 1. Sick: 10 got, 3 taken less 2 given back by the pay decision.
            ->where('leave_attendance.balances.rows', fn ($rows) => collect($rows)->firstWhere('name', $this->sick->name) === [
                'id' => $this->sick->id, 'name' => $this->sick->name, 'got' => 10, 'used' => 1, 'left' => 9,
            ])
            ->where('leave_attendance.balances.url', route('hrm.leave.index', ['employee_id' => $this->employee->id, 'year' => 2026], false))
            // 2. The request: 3 days, 1 paid, 2 unpaid, and who decided.
            ->has('leave_attendance.requests.rows', 1)
            ->where('leave_attendance.requests.rows.0.start_date', '2026-09-14')
            ->where('leave_attendance.requests.rows.0.end_date', '2026-09-16')
            ->where('leave_attendance.requests.rows.0.days', 3)
            ->where('leave_attendance.requests.rows.0.paid_days', 1)
            ->where('leave_attendance.requests.rows.0.unpaid_days', 2)
            ->where('leave_attendance.requests.rows.0.status', 'approved')
            ->where('leave_attendance.requests.rows.0.pay_label', '2 of 3 days unpaid')
            ->where('leave_attendance.requests.rows.0.pay_decision.reason', 'Absent without telling anyone')
            ->where('leave_attendance.requests.rows.0.pay_decision.by', $this->hr->name)
            // 3. Six payroll months, newest first; November is still running.
            ->has('leave_attendance.attendance', 6)
            ->where('leave_attendance.attendance.0.label', 'November 2026 (24 Oct to 23 Nov 2026)')
            ->where('leave_attendance.attendance.0.running', true)
            ->where('leave_attendance.attendance.0.present', 1)
            ->where('leave_attendance.attendance.1.label', 'October 2026 (24 Sep to 23 Oct 2026)')
            ->where('leave_attendance.attendance.1.running', false)
            ->where('leave_attendance.attendance.1.present', 1)
            ->where('leave_attendance.attendance.1.late', 1)
            ->where('leave_attendance.attendance.1.half_day', 1)
            ->where('leave_attendance.attendance.1.absent', 1)
            ->where('leave_attendance.attendance.1.missing_punch', 1)
            ->where('leave_attendance.attendance.1.provisional', 1)
            ->where('leave_attendance.attendance.1.made_unpaid', 1)
            ->where('leave_attendance.attendance.1.url', route('hrm.attendance.employee.show', ['employee' => $this->employee->id, 'from' => '2026-09-24', 'to' => '2026-10-23'], false))
            // September holds the leave days.
            ->where('leave_attendance.attendance.2.label', 'September 2026 (24 Aug to 23 Sep 2026)')
            ->where('leave_attendance.attendance.2.on_leave', 3)
            ->where('leave_attendance.attendance.2.made_unpaid', 0)
            // 4. Pay changes: the waiting addition, then the approved penalty.
            ->has('leave_attendance.pay_changes.rows', 2)
            ->where('leave_attendance.pay_changes.rows.0.type', 'addition')
            ->where('leave_attendance.pay_changes.rows.0.status', 'waiting')
            ->where('leave_attendance.pay_changes.rows.0.period', 'November 2026')
            ->where('leave_attendance.pay_changes.rows.0.amount', '500.00')
            ->where('leave_attendance.pay_changes.rows.0.decided_on', null)
            ->where('leave_attendance.pay_changes.rows.1.type', 'penalty')
            ->where('leave_attendance.pay_changes.rows.1.type_label', 'Penalty')
            ->where('leave_attendance.pay_changes.rows.1.is_earning', false)
            ->where('leave_attendance.pay_changes.rows.1.status', 'approved')
            ->where('leave_attendance.pay_changes.rows.1.period', 'October 2026')
            ->where('leave_attendance.pay_changes.rows.1.amount', '1,000.00')
            ->where('leave_attendance.pay_changes.rows.1.decided_on', '27/10/2026'));
});

it('counts a cancelled day made unpaid no more, and marks the penalty cancelled', function () {
    $this->penalty->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Wrong person'])->save();

    $props = (new EmployeeLeaveAttendance($this->employee, $this->hr->fresh(), 2026))->props();

    expect($props['attendance'][1]['made_unpaid'])->toBe(0.0)
        ->and(collect($props['pay_changes']['rows'])->firstWhere('type', 'penalty')['status'])->toBe('cancelled');
});

it('reads another leave year from ?leave_year=', function () {
    ($this->as)($this->hr)->get(route('hrm.employees.show', ['employee' => $this->employee, 'leave_year' => 2025]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leave_attendance.leave_year', 2025)
            ->where('leave_attendance.balances.rows', fn ($rows) => collect($rows)->firstWhere('name', $this->sick->name)['got'] === 0));
});

it('keeps pay changes from a reader without the payroll permission', function () {
    ($this->as)($this->clerk)->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leave_attendance.pay_changes', null)
            ->has('leave_attendance.balances.rows')
            ->has('leave_attendance.requests.rows', 1)
            ->has('leave_attendance.attendance', 6));
});

it('keeps every part from a reader who only manages staff', function () {
    ($this->as)($this->desk)->get(route('hrm.employees.show', $this->employee))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('leave_attendance.balances', null)
            ->where('leave_attendance.requests', null)
            ->where('leave_attendance.attendance', null)
            ->where('leave_attendance.pay_changes', null));
});

it('reads the tab in a fixed number of queries however many requests, days and adjustments there are', function () {
    $count = function () {
        $tab = new EmployeeLeaveAttendance($this->employee->fresh(), $this->hr->fresh(), 2026);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $tab->props();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    // The first read also loads the permission cache; count from the second.
    $count();
    $first = $count();

    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
        DailyAttendance::create([
            'employee_id' => $this->employee->id, 'attendance_date' => $date, 'status' => AttendanceStatus::Present,
            'consequence' => AttendanceConsequence::None, 'worked_minutes' => 480, 'is_provisional' => false, 'holds_payroll' => false,
        ]);
        $adjustment = PayrollAdjustment::create([
            'employee_id' => $this->employee->id, 'type' => PayrollAdjustmentType::Deduction, 'amount' => 100,
            'payroll_period_id' => PayrollPeriod::forMonth(2026, 11)->id, 'reason' => 'Broken glass', 'requested_by' => $this->hr->id,
        ]);
        $adjustment->submitForApproval($this->hr);
    }
    $second = app(RequestLeave::class)->handle($this->employee, $this->sick, '2026-10-26', '2026-10-26', LeaveDayPortion::FullDay, 'Fever', $this->hr);
    expect($second)->toBeInstanceOf(LeaveRequest::class);

    expect($count())->toBe($first);
});
