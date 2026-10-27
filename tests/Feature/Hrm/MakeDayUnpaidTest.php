<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\MakeDayUnpaid;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Actions\TransitionPayrollRun;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\HrCorrectionWorkflowSeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\database\seeders\PayrollInputWorkflowSeeder;
use App\Modules\Hrm\database\seeders\PayrollWorkflowSeeder;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\PayrollAdjustmentType;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ 27 Oct 2026 (Joy): "one day one employee came but break some rules for
 * that office give him that day unpaid, how it can be done show me."
 * HRM, Attendance, Daily: Make unpaid (MakeDayUnpaid).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Unpaid Day Co', 'slug' => 'unpaid-day-co']);
    app(BusinessContext::class)->set($this->business->id);

    $permissions = ['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.payroll.approve', 'hrm.scheduling.manage', 'hrm.leave.manage', 'hrm.leave.approve'];
    foreach ($permissions as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    // Records attendance but may not raise payroll adjustments.
    Role::firstOrCreate(['name' => 'Recorder', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('hrm.attendance.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();
    (new LeaveTypeSeeder)->run();
    (new PayrollWorkflowSeeder)->run();
    (new PayrollInputWorkflowSeeder)->run();
    (new HrCorrectionWorkflowSeeder)->run();

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');
    $this->recorder = User::factory()->create();
    $this->recorder->businesses()->attach($this->business->id);
    $this->recorder->assignRole('Recorder');

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
        'employee_code' => 'BDG-000009', 'first_name' => 'Rahim', 'last_name' => 'Uddin',
        'employment_type' => 'permanent', 'status' => 'active', 'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create(['employee_id' => $this->employee->id, 'office_id' => $office->id, 'is_primary' => true, 'effective_from' => '2025-01-01']);

    // Gross 30,000: one day is 30,000 / 30 = 1,000, as payroll prices it.
    app(SaveSalaryStructure::class)->handle($this->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 15000],
        ['salary_component_id' => SalaryComponent::where('code', 'house_rent')->sole()->id, 'value' => 15000],
    ], 'Opening structure');

    // Tuesday 20 Oct 2026, a day he came to work (October payroll: 24 Sep to 23 Oct).
    $this->day = fn (AttendanceStatus $status = AttendanceStatus::Present, string $date = '2026-10-20') => DailyAttendance::create([
        'employee_id' => $this->employee->id,
        'attendance_date' => $date,
        'status' => $status,
        'consequence' => AttendanceConsequence::None,
        'worked_minutes' => $status === AttendanceStatus::Present ? 480 : 0,
        'is_provisional' => false, 'holds_payroll' => false,
    ]);

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    $this->makeUnpaid = fn (string $portion = 'full', string $date = '2026-10-20') => ($this->as)($this->hr)->post(route('hrm.attendance.daily.unpaid'), [
        'employee_id' => $this->employee->id, 'date' => $date, 'portion' => $portion, 'reason' => 'Used the phone on the packing floor',
    ]);
});

it('makes a worked day unpaid as a Penalty of one day of pay on that payroll, waiting for approval', function () {
    ($this->day)();

    ($this->makeUnpaid)()->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $m) => str_contains($m, '1,000.00') && str_contains($m, 'October 2026'));

    $penalty = PayrollAdjustment::sole();
    expect($penalty->type)->toBe(PayrollAdjustmentType::Penalty)
        ->and((float) $penalty->amount)->toBe(1000.0)
        ->and($penalty->period->label())->toBe('October 2026')
        ->and($penalty->source_key)->toBe('day_unpaid:'.$this->employee->id.':2026-10-20')
        ->and($penalty->reason)->toBe('Rule break, full day unpaid (20/10/2026): Used the phone on the packing floor')
        ->and($penalty->approvalRequest->status)->toBe(ApprovalStatus::Pending)
        // The attendance day itself is untouched: he did come.
        ->and(DailyAttendance::sole()->status)->toBe(AttendanceStatus::Present);

    // The Daily row says so and links to the penalty.
    ($this->as)($this->hr)->get(route('hrm.attendance.daily.index', ['on' => '2026-10-20']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_make_unpaid', true)
            ->where('days.0.unpaid.id', $penalty->id)
            ->where('days.0.unpaid.reason', 'Used the phone on the packing floor')
            ->where('days.0.unpaid.pending', true)
            ->where('days.0.unpaid_portions', []));

    // Once approved, the October payslip carries it.
    approveIfPending($penalty->approvalRequest, $this->hr);
    $run = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 10));
    $line = $run->payslips()->where('employee_id', $this->employee->id)->sole()->lines()->where('code', 'penalty')->sole();
    expect((float) $line->amount)->toBe(1000.0);
});

it('makes half a day unpaid for half the day of pay', function () {
    ($this->day)();

    ($this->makeUnpaid)('half')->assertSessionHasNoErrors();

    $penalty = PayrollAdjustment::sole();
    expect((float) $penalty->amount)->toBe(500.0)
        ->and($penalty->reason)->toContain('half day unpaid');
});

it('refuses the same day twice while the first stands', function () {
    ($this->day)();
    ($this->makeUnpaid)()->assertSessionHasNoErrors();

    ($this->makeUnpaid)('half')->assertSessionHasErrors('date');

    expect(PayrollAdjustment::count())->toBe(1);
});

it('is undone by cancelling the penalty, and the day can then be decided again', function () {
    ($this->day)();
    ($this->makeUnpaid)()->assertSessionHasNoErrors();
    $penalty = PayrollAdjustment::sole();
    approveIfPending($penalty->approvalRequest, $this->hr);

    ($this->as)($this->hr)->post(route('hrm.adjustments.cancel', $penalty), ['reason_text' => 'Owner forgave the phone'])
        ->assertSessionHasNoErrors();

    expect($penalty->fresh()->isCancelled())->toBeTrue();
    ($this->as)($this->hr)->get(route('hrm.attendance.daily.index', ['on' => '2026-10-20']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('days.0.unpaid', null)
            ->where('days.0.unpaid_portions', ['full', 'half']));

    ($this->makeUnpaid)('half')->assertSessionHasNoErrors();
    expect(PayrollAdjustment::whereNull('cancelled_at')->sole()->amount)->toEqual('500.00');
});

it('frees the day when the penalty is rejected', function () {
    ($this->day)();
    ($this->makeUnpaid)()->assertSessionHasNoErrors();
    PayrollAdjustment::sole()->approvalRequest->reject($this->hr, 'Not this time');

    ($this->makeUnpaid)()->assertSessionHasNoErrors();

    expect(PayrollAdjustment::count())->toBe(2);
});

it('refuses a day he did not work, each with a plain reason', function (AttendanceStatus $status, string $why) {
    ($this->day)($status);

    expect(fn () => app(MakeDayUnpaid::class)->handle($this->employee, '2026-10-20', false, 'Rule', $this->hr))
        ->toThrow(ValidationException::class, $why);
    expect(PayrollAdjustment::count())->toBe(0);
})->with([
    'absent' => [AttendanceStatus::Absent, 'the absence deduction already takes'],
    'on leave' => [AttendanceStatus::OnLeave, 'Decide its pay on the leave instead'],
    'weekly off' => [AttendanceStatus::WeeklyOff, 'not a working day'],
    'missing punch' => [AttendanceStatus::MissingPunch, 'Fix it first'],
    'half day, asked for the full day' => [AttendanceStatus::HalfDay, 'only the other half'],
]);

it('refuses a day with no attendance processed, and a closed payroll', function () {
    expect(fn () => app(MakeDayUnpaid::class)->handle($this->employee, '2026-10-20', false, 'Rule', $this->hr))
        ->toThrow(ValidationException::class, 'No attendance is processed');

    ($this->day)();
    $run = app(CalculatePayroll::class)->handle(PayrollPeriod::forMonth(2026, 10));
    app(TransitionPayrollRun::class)->review($run, $this->hr);

    expect(fn () => app(MakeDayUnpaid::class)->handle($this->employee, '2026-10-20', false, 'Rule', $this->hr))
        ->toThrow(ValidationException::class, 'October 2026 is already reviewed');
});

it('needs the payroll power and a reason', function () {
    ($this->day)();

    ($this->as)($this->recorder)->post(route('hrm.attendance.daily.unpaid'), [
        'employee_id' => $this->employee->id, 'date' => '2026-10-20', 'portion' => 'full', 'reason' => 'x',
    ])->assertForbidden();

    ($this->as)($this->hr)->post(route('hrm.attendance.daily.unpaid'), [
        'employee_id' => $this->employee->id, 'date' => '2026-10-20', 'portion' => 'full', 'reason' => '',
    ])->assertSessionHasErrors('reason');

    // The recorder sees the page without the button.
    ($this->as)($this->recorder)->get(route('hrm.attendance.daily.index', ['on' => '2026-10-20']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can_make_unpaid', false));

    expect(PayrollAdjustment::count())->toBe(0);
});

it('still lets HR add a Deduction, Penalty or Recovery by hand on Payroll, Inputs', function (PayrollAdjustmentType $type) {
    ($this->as)($this->hr)->post(route('hrm.adjustments.store'), [
        'employee_id' => $this->employee->id,
        'type' => $type->value,
        'amount' => 750,
        'payroll_period_id' => PayrollPeriod::forMonth(2026, 10)->id,
        'reason' => 'Broke the weighing scale',
        'evidence' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect(PayrollAdjustment::sole()->type)->toBe($type);
})->with([PayrollAdjustmentType::Deduction, PayrollAdjustmentType::Penalty, PayrollAdjustmentType::Recovery]);
