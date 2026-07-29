<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\CalculatePayroll;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Salary\SalaryResolver;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Correction C14 — TA & DA, an allowance earned per day reported for work.
 *
 * The pure engine rules live in `tests/Unit/Hrm/PayrollEngineTest.php`. This
 * file covers the half the engine cannot see: which real attendance days count
 * as reporting for work, and that the whole path from a salary structure to a
 * stored payslip carries the allowance without disturbing anything else.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Allowance Co', 'slug' => 'allowance-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.salary.manage', 'hrm.payroll.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['hrm.salary.manage', 'hrm.payroll.manage']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    (new SalaryComponentSeeder)->run();

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);

    // A Retail Sales Officer, the role this correction exists for.
    $this->employee = Employee::create([
        'employee_code' => 'BDG-000020',
        'first_name' => 'Shoykod', 'last_name' => 'Hasan',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
        'bank_account_number' => '1234567890',
    ]);
    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $this->office->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    $shift = Shift::create([
        'name' => 'Retail', 'start_time' => '08:30', 'end_time' => '18:00',
        'grace_minutes' => 0, 'break_minutes' => 90, 'is_overnight' => false,
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

    $this->period = PayrollPeriod::forMonth(2026, 7);
});

/**
 * A processed day, exactly as the Phase-2b attendance engine would leave it.
 */
function allowanceDay(string $date, AttendanceStatus $status, array $overrides = []): DailyAttendance
{
    return DailyAttendance::create([
        'employee_id' => test()->employee->id,
        'attendance_date' => $date,
        'status' => $status,
        'consequence' => $overrides['consequence'] ?? AttendanceConsequence::None,
        'worked_minutes' => $overrides['worked_minutes'] ?? 480,
        'payable_minutes' => $overrides['payable_minutes'] ?? 480,
        'late_minutes' => $overrides['late_minutes'] ?? 0,
        'flags' => $overrides['flags'] ?? null,
        'is_provisional' => false,
        'holds_payroll' => $overrides['holds_payroll'] ?? false,
        'processed_at' => now(),
    ]);
}

/**
 * Gross 12,000 with a per-day TA & DA of 246, the BDG-000020 shape.
 */
function allowanceStructure(float $rate = 246.0): void
{
    app(SaveSalaryStructure::class)->handle(test()->employee, '2025-01-01', [
        ['salary_component_id' => SalaryComponent::where('code', 'basic')->sole()->id, 'value' => 12000],
        ['salary_component_id' => SalaryComponent::where('code', 'ta_da')->sole()->id, 'value' => $rate],
    ], 'TA & DA structure');
}

// =============================================================================
// Which days count as reporting for work (C14a, C14b, C14c).
// =============================================================================

it('counts a present, late or field-duty day as reporting for work', function () {
    expect(allowanceDay('2026-07-01', AttendanceStatus::Present)->reportedForWork())->toBeTrue();
    expect(allowanceDay('2026-07-02', AttendanceStatus::Late, ['late_minutes' => 210])->reportedForWork())->toBeTrue();
    expect(allowanceDay('2026-07-03', AttendanceStatus::FieldDuty)->reportedForWork())->toBeTrue();
});

it('counts a half day as a whole day of reporting for work (C14a)', function () {
    // ⚠ The distinction from `presentDays`, which carries this same day as 0.5.
    // They travelled in; the journey is not halved.
    expect(allowanceDay('2026-07-04', AttendanceStatus::HalfDay, ['worked_minutes' => 240])->reportedForWork())->toBeTrue();
});

it('counts approved work on a holiday or weekly off (C14b)', function () {
    // These days keep a Holiday/WeeklyOff status even once POL-09 approves the
    // work, which is exactly why `presentDays` cannot answer this.
    expect(allowanceDay('2026-07-05', AttendanceStatus::Holiday)->reportedForWork())->toBeTrue();
    expect(allowanceDay('2026-07-06', AttendanceStatus::WeeklyOff)->reportedForWork())->toBeTrue();
});

it('does not count unapproved holiday work', function () {
    $day = allowanceDay('2026-07-07', AttendanceStatus::Holiday, [
        'payable_minutes' => 0,
        'flags' => ['unapproved_holiday_work', 'needs_review'],
    ]);

    expect($day->reportedForWork())->toBeFalse();
});

it('does not count a holiday nobody worked', function () {
    $day = allowanceDay('2026-07-08', AttendanceStatus::Holiday, ['worked_minutes' => 0, 'payable_minutes' => 0]);

    expect($day->reportedForWork())->toBeFalse();
});

it('does not count leave, absence or an unresolved missing punch', function () {
    expect(allowanceDay('2026-07-09', AttendanceStatus::OnLeave, ['worked_minutes' => 0])->reportedForWork())->toBeFalse();
    expect(allowanceDay('2026-07-10', AttendanceStatus::Absent, ['worked_minutes' => 0])->reportedForWork())->toBeFalse();
    expect(allowanceDay('2026-07-11', AttendanceStatus::MissingPunch, ['holds_payroll' => true])->reportedForWork())->toBeFalse();
});

// =============================================================================
// End to end: structure to stored payslip.
// =============================================================================

it('keeps the per-day allowance out of gross on a real salary structure', function () {
    allowanceStructure();

    $structure = SalaryStructure::activeFor($this->employee->id, '2026-07-23');
    $breakdown = app(SalaryResolver::class)->resolve($structure->lines()->with('component')->get());

    // 12,000 Basic and nothing else. The 246 is on the structure, not in gross.
    expect($breakdown->gross)->toBe(12000.00);
    expect($breakdown->basic)->toBe(12000.00);
});

it('pays the allowance on a real payroll run without disturbing the absence deduction', function () {
    allowanceStructure();

    $date = Carbon::parse('2026-06-24');
    for ($i = 0; $i < 20; $i++) {
        allowanceDay($date->toDateString(), AttendanceStatus::Present);
        $date->addDay();
    }
    // One half day, one approved holiday worked, one absence.
    allowanceDay($date->copy()->toDateString(), AttendanceStatus::HalfDay, ['worked_minutes' => 240]);
    allowanceDay($date->copy()->addDay()->toDateString(), AttendanceStatus::Holiday);
    allowanceDay($date->copy()->addDays(2)->toDateString(), AttendanceStatus::Absent, ['worked_minutes' => 0, 'payable_minutes' => 0]);

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->where('employee_id', $this->employee->id)->sole();

    // 22 days reported for work: 20 present + the half day IN FULL + the
    // approved holiday. `presentDays` would have said 20.5 and paid 5,043.
    $taDa = $payslip->lines()->where('code', 'ta_da')->sole();
    expect((float) $taDa->amount)->toBe(5412.00); // 246 × 22
    expect((float) $taDa->quantity)->toBe(22.0);

    // ⚠ The property the whole correction protects: absence is still priced off
    // 12,000 alone. Had the allowance reached gross it would be higher.
    expect((float) $payslip->lines()->where('code', 'absent')->sole()->amount)->toBe(400.00); // 12,000 ÷ 30
    expect((float) $payslip->gross_salary)->toBe(12000.00);
});

it('shows the allowance once, not twice, on a stored payslip', function () {
    allowanceStructure();
    allowanceDay('2026-07-01', AttendanceStatus::Present);

    $run = app(CalculatePayroll::class)->handle($this->period);
    $payslip = $run->payslips()->sole();

    expect($payslip->lines()->where('code', 'ta_da')->count())->toBe(1);
});

// =============================================================================
// The basis is shared with other screens, so it is closed off where it has no
// meaning.
// =============================================================================

it('refuses a per-day basis on a deduction', function () {
    $this->actingAs($this->hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.salary.components.store'), [
            'code' => 'bad_deduction',
            'name' => 'Nonsense',
            'type' => 'deduction',
            'calculation_basis' => 'per_present_day',
            'sequence' => 500,
        ])->assertSessionHasErrors('calculation_basis');
});

it('refuses a per-day basis on a bonus event', function () {
    $period = PayrollPeriod::forMonth(2026, 8);

    $this->actingAs($this->hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.bonus.events.store'), [
            'name' => 'Eid',
            'calculation_basis' => 'per_present_day',
            'value' => 246,
            'payroll_period_id' => $period->id,
        ])->assertSessionHasErrors('calculation_basis');
});
