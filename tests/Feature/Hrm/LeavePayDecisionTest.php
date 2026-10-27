<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ApplyLeave;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\DecideLeavePay;
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
use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeavePayDecision;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Support\ApprovalCards;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ 27 Oct 2026 (Joy): "Shoykod was absent for three days, on deduct from
 * sick ... another approval needs to mark as paid or unpaid not related with
 * the approval of leave." The pay decision on an approved leave (DecideLeavePay).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Pay Decision Co', 'slug' => 'pay-decision-co']);
    app(BusinessContext::class)->set($this->business->id);

    $permissions = ['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage', 'hrm.leave.manage', 'hrm.leave.approve'];
    foreach ($permissions as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('hrm.leave.approve');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new LeaveTypeSeeder)->run();
    (new LeaveWorkflowSeeder)->run();
    (new PayrollWorkflowSeeder)->run();
    (new PayrollInputWorkflowSeeder)->run();
    (new HrCorrectionWorkflowSeeder)->run();

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');
    $this->staffUser = User::factory()->create();
    $this->staffUser->businesses()->attach($this->business->id);
    $this->staffUser->assignRole('Staff');

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

    // Gross 30,000, so one unpaid day costs 30,000 / 30 = 1,000.
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 15000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 15000],
    ], 'Opening structure');

    $this->sick = LeaveType::where('code', 'sick')->sole();
    $this->unpaidType = LeaveType::where('code', 'unpaid')->sole();
    LeaveLedgerEntry::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->sick->id, 'year' => 2026,
        'entry_type' => LeaveLedgerEntryType::Grant, 'days' => 10, 'occurred_on' => '2026-01-01']);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    // Monday 14 to Wednesday 16 Sep 2026: three working days in the September
    // payroll (24 Aug to 23 Sep), approved through both stages and applied.
    $this->approvedLeave = function (LeaveType $type, string $from = '2026-09-14', string $to = '2026-09-16'): LeaveRequest {
        $leave = app(RequestLeave::class)->handle($this->employee, $type, $from, $to, LeaveDayPortion::FullDay, 'Fever', $this->hr);
        approveIfPending($leave->approvalRequest, $this->manager);
        approveIfPending($leave->fresh()->approvalRequest, $this->hr);

        return app(ApplyLeave::class)->handle($leave->fresh());
    };

    $this->sickBalance = fn () => app(LeaveBalance::class)->for($this->employee->id, $this->sick->id, 2026);
});

it('makes an approved paid sick leave unpaid: the days go back to the balance and payroll deducts them', function () {
    $leave = ($this->approvedLeave)($this->sick);
    expect((float) $leave->unpaid_days)->toBe(0.0)
        ->and(($this->sickBalance)())->toBe(7.0);

    ($this->as)($this->hr)->post(route('hrm.leave.pay', $leave), ['unpaid_days' => 3, 'reason' => 'Absent without telling anyone'])
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Pay decided: Unpaid'));

    $leave->refresh();
    expect((float) $leave->unpaid_days)->toBe(3.0)
        // The approval is untouched: still approved and in effect.
        ->and($leave->isApplied())->toBeTrue()
        ->and($leave->isCancelled())->toBeFalse()
        // The three sick days are back.
        ->and(($this->sickBalance)())->toBe(10.0)
        ->and((float) LeaveLedgerEntry::where('entry_type', LeaveLedgerEntryType::PayDecision)->sum('days'))->toBe(3.0);

    // Payroll reads the request's unpaid days: Leave Without Pay, 3 x 1,000.
    DailyAttendance::query()->update(['finalized_at' => now(), 'is_provisional' => false]);
    $run = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 9));
    expect($run->validation_report['issue_count'] ?? 0)->toBe(0, json_encode($run->validation_report));
    $line = $run->payslips()->sole()->lines()->where('code', 'lwp')->sole();

    expect((float) $line->amount)->toBe(3000.0)
        ->and((float) $line->quantity)->toBe(3.0);
});

it('makes part of a leave unpaid in half-day steps and keeps the history', function () {
    $leave = ($this->approvedLeave)($this->sick);

    $decided = app(DecideLeavePay::class)->handle($leave, $this->hr, 1.5, 'Came back late');

    expect((float) $decided->unpaid_days)->toBe(1.5)
        ->and(DecideLeavePay::label(3, 1.5))->toBe('1.5 of 3 days unpaid')
        ->and(($this->sickBalance)())->toBe(8.5);

    $history = LeavePayDecision::sole();
    expect((float) $history->unpaid_days_before)->toBe(0.0)
        ->and((float) $history->unpaid_days_after)->toBe(1.5)
        ->and((float) $history->quota_days)->toBe(1.5)
        ->and($history->reason)->toBe('Came back late')
        ->and($history->decided_by)->toBe($this->hr->id)
        ->and($history->at_approval)->toBeFalse();

    // Not a half-day step, and nothing to change: both refused.
    expect(fn () => app(DecideLeavePay::class)->handle($decided, $this->hr, 1.2, 'x'))->toThrow(ValidationException::class)
        ->and(fn () => app(DecideLeavePay::class)->handle($decided, $this->hr, 1.5, 'x'))->toThrow(ValidationException::class);

    // The leave screen shows the pay, who decided it, and the history.
    ($this->as)($this->hr)->get(route('hrm.leave.index', ['year' => 2026]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requests.0.pay_label', '1.5 of 3 days unpaid')
            ->where('requests.0.pay_blocked', null)
            ->where('requests.0.pay_history.0.reason', 'Came back late')
            ->where('requests.0.pay_history.0.by', $this->hr->name));
});

it('makes an unpaid sick leave paid again, drawing the balance first', function () {
    $leave = ($this->approvedLeave)($this->sick);
    app(DecideLeavePay::class)->handle($leave, $this->hr, 3, 'Absent');
    expect(($this->sickBalance)())->toBe(10.0);

    $paid = app(DecideLeavePay::class)->handle($leave, $this->hr, 0, 'Doctor note arrived');

    expect((float) $paid->unpaid_days)->toBe(0.0)
        ->and(($this->sickBalance)())->toBe(7.0)
        // Quota stood behind every day, so no company grant.
        ->and($paid->isPaidByAuthority())->toBeFalse()
        ->and(LeavePayDecision::count())->toBe(2);
});

it('pays an Unpaid Leave as a company grant, with no balance moving', function () {
    $leave = ($this->approvedLeave)($this->unpaidType);
    expect((float) $leave->unpaid_days)->toBe(3.0);
    $rowsBefore = LeaveLedgerEntry::count();

    $paid = app(DecideLeavePay::class)->handle($leave, $this->hr, 0, 'Owner pays this one');

    expect((float) $paid->unpaid_days)->toBe(0.0)
        ->and($paid->isPaidByAuthority())->toBeTrue()
        ->and($paid->paid_override_reason)->toBe('Owner pays this one')
        ->and(LeaveLedgerEntry::count())->toBe($rowsBefore)
        ->and((float) LeavePayDecision::sole()->granted_days)->toBe(3.0);
});

it('refuses once the payroll covering the days is reviewed', function () {
    $leave = ($this->approvedLeave)($this->sick);
    DailyAttendance::query()->update(['finalized_at' => now(), 'is_provisional' => false]);
    $run = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 9));
    app(TransitionPayrollRun::class)->review($run, $this->hr);

    expect(DecideLeavePay::refusal($leave->fresh()))->toContain('September 2026 is already reviewed');

    ($this->as)($this->hr)->post(route('hrm.leave.pay', $leave), ['unpaid_days' => 3, 'reason' => 'Too late'])
        ->assertSessionHasErrors('status');

    expect((float) $leave->fresh()->unpaid_days)->toBe(0.0)
        ->and(LeavePayDecision::count())->toBe(0);
});

it('lets only someone who manages leave decide the pay, and wants a reason', function () {
    $leave = ($this->approvedLeave)($this->sick);

    ($this->as)($this->staffUser)->post(route('hrm.leave.pay', $leave), ['unpaid_days' => 3, 'reason' => 'mine'])->assertForbidden();
    ($this->as)($this->manager)->post(route('hrm.leave.pay', $leave), ['unpaid_days' => 3, 'reason' => 'approver only'])->assertForbidden();
    ($this->as)($this->hr)->post(route('hrm.leave.pay', $leave), ['unpaid_days' => 3, 'reason' => ''])->assertSessionHasErrors('reason');

    expect((float) $leave->fresh()->unpaid_days)->toBe(0.0);
});

it('refuses a pending leave: the pay is decided on the card or after approval', function () {
    $leave = app(RequestLeave::class)->handle($this->employee, $this->sick, '2026-09-14', '2026-09-14', LeaveDayPortion::FullDay, 'Fever', $this->hr);

    expect(fn () => app(DecideLeavePay::class)->handle($leave, $this->hr, 1, 'early'))
        ->toThrow(ValidationException::class, 'Only an approved leave has a pay decision');
});

it('shows the pay that will apply on the approval card and lets the last approver choose it', function () {
    $leave = app(RequestLeave::class)->handle($this->employee, $this->sick, '2026-09-14', '2026-09-16', LeaveDayPortion::FullDay, 'Fever', $this->staffUser);
    approveIfPending($leave->approvalRequest, $this->manager);

    $this->actingAs($this->hr);
    $card = app(ApprovalCards::class)->present($leave->fresh()->approvalRequest);
    $pay = collect($card['details'])->firstWhere('label', 'Pay if approved')['value'];

    expect($pay)->toBe('Paid (from the balance)')
        ->and($card['pay_choice'])->toBe(['days' => 3.0, 'unpaid_days' => 0.0, 'half_day' => true]);

    // A manager without leave management sees the pay but cannot choose it.
    $this->actingAs($this->manager);
    expect(app(ApprovalCards::class)->present($leave->fresh()->approvalRequest)['pay_choice'])->toBeNull();

    ($this->as)($this->hr)->post(route('hrm.leave.approve', $leave), ['pay_unpaid_days' => 2, 'pay_reason' => 'Only one day was really sick'])
        ->assertSessionHas('success', fn (string $m) => str_contains($m, '2 of 3 days unpaid'));

    $leave->refresh();
    expect($leave->isApplied())->toBeTrue()
        ->and((float) $leave->unpaid_days)->toBe(2.0)
        ->and(($this->sickBalance)())->toBe(9.0)
        ->and(LeavePayDecision::sole()->at_approval)->toBeTrue();
});

it('approves exactly as before when the approver leaves the pay alone', function () {
    $leave = app(RequestLeave::class)->handle($this->employee, $this->sick, '2026-09-14', '2026-09-16', LeaveDayPortion::FullDay, 'Fever', $this->staffUser);
    approveIfPending($leave->approvalRequest, $this->manager);

    ($this->as)($this->hr)->post(route('hrm.leave.approve', $leave))->assertSessionHas('success');

    expect((float) $leave->fresh()->unpaid_days)->toBe(0.0)
        ->and(LeavePayDecision::count())->toBe(0)
        ->and(($this->sickBalance)())->toBe(7.0);
});

it('undoes the pay decision too when the leave is cancelled, so the ledger nets to zero', function () {
    $leave = ($this->approvedLeave)($this->sick);
    app(DecideLeavePay::class)->handle($leave, $this->hr, 1, 'One day unpaid');
    expect(($this->sickBalance)())->toBe(8.0);

    ($this->as)($this->hr)->post(route('hrm.leave.cancel', $leave), ['reason_text' => 'He was at work after all'])
        ->assertSessionHas('success');

    expect($leave->fresh()->isCancelled())->toBeTrue()
        ->and(($this->sickBalance)())->toBe(10.0)
        ->and((float) LeaveLedgerEntry::where('reference_id', $leave->id)->sum('days'))->toBe(0.0);
});
