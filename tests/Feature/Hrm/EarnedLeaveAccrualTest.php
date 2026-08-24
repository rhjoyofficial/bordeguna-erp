<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\AccrueMonthlyEarnedLeave;
use App\Modules\Hrm\Actions\AssignSchedule;
use App\Modules\Hrm\Actions\ProcessAttendance;
use App\Modules\Hrm\Actions\RecordPunch;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\database\seeders\LeaveTypeSeeder;
use App\Modules\Hrm\Enums\PunchDirection;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\EarnedLeaveAccrual;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\Shift;
use App\Modules\Hrm\Support\LeaveBalance;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The monthly earned-leave accrual (Joy, 24 Aug 2026): one day a month to each
 * PERMANENT employee, withheld when the month's lateness ran past either
 * allowance.
 *
 * The rules worth pinning: a probationer earns none however clean their month,
 * both thresholds withhold independently, and a withheld month leaves a stated
 * reason rather than silence.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Accrual Co', 'slug' => 'accrual-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.attendance.manage');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    (new LeaveTypeSeeder)->run();
    $this->earned = LeaveType::where('code', 'earned')->first();
    $this->earned->update(['is_active' => true]);

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->shift = Shift::create([
        'name' => 'General', 'start_time' => '09:00', 'end_time' => '18:00',
        'grace_minutes' => 15, 'break_minutes' => 60, 'is_overnight' => false,
    ]);
    $calendar = HolidayCalendar::create([
        'name' => 'Bangladesh', 'year' => 2026, 'weekly_off_days' => [5], 'is_default' => true,
    ]);

    // The accrual rule lives on the company-default policy: 1 day a month,
    // withheld above 3 ordinary or 1 serious late.
    $this->policy = AttendancePolicy::create([
        'name' => 'Standard', 'version' => 1,
        'config' => [
            ...AttendancePolicySeeder::defaultConfig(),
            'el_accrual' => ['enabled' => true, 'days_per_month' => 1, 'max_ordinary_lates' => 3, 'max_serious_lates' => 1],
        ],
        'effective_from' => '2026-01-01',
    ]);

    app(AssignSchedule::class)->handle(ScheduleScope::Business, null, [
        'schedule_type' => 'fixed',
        'shift_id' => $this->shift->id,
        'holiday_calendar_id' => $calendar->id,
        'effective_from' => '2025-01-01',
    ]);

    $this->makeEmployee = function (string $code, string $type = 'permanent', ?string $confirmed = null) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => 'Emp'.$code, 'last_name' => 'Test',
            'employment_type' => $type, 'status' => 'active',
            'confirmation_date' => $confirmed,
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        return $employee;
    };

    // A late day in a chosen band: 09:05 is ordinary, 09:40 is serious.
    $this->lateDay = function (Employee $employee, string $date, string $in) {
        app(RecordPunch::class)->handle($employee, "$date $in", PunchSource::Manual, [
            'direction' => PunchDirection::In, 'note' => 'test punch',
        ]);
        app(RecordPunch::class)->handle($employee, "$date 18:30:00", PunchSource::Manual, [
            'direction' => PunchDirection::Out, 'note' => 'test punch',
        ]);
        app(ProcessAttendance::class)->forDay($employee, $date, force: true);
    };

    $this->accrue = fn (bool $dryRun = false) => app(AccrueMonthlyEarnedLeave::class)->handle(2026, 3, $dryRun);
});

it('credits a permanent employee whose month stayed within both allowances', function () {
    $employee = ($this->makeEmployee)('BDG-000001');
    // Three ordinary lates is exactly the allowance, not past it.
    foreach (['2026-03-02', '2026-03-03', '2026-03-04'] as $date) {
        ($this->lateDay)($employee, $date, '09:05:00');
    }

    $result = ($this->accrue)();

    expect($result['granted'])->toBe(1)
        ->and($result['withheld'])->toBe(0)
        ->and(app(LeaveBalance::class)->for($employee->id, $this->earned->id, 2026))->toBe(1.0);

    $decision = EarnedLeaveAccrual::where('employee_id', $employee->id)->forMonth(2026, 3)->sole();
    expect((float) $decision->days_granted)->toBe(1.0)
        ->and($decision->withheld_reason)->toBeNull()
        ->and($decision->ordinary_lates)->toBe(3);
});

it('withholds the day when ordinary lates run past the allowance', function () {
    $employee = ($this->makeEmployee)('BDG-000001');
    foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'] as $date) {
        ($this->lateDay)($employee, $date, '09:05:00');
    }

    $result = ($this->accrue)();

    expect($result['granted'])->toBe(0)
        ->and($result['withheld'])->toBe(1)
        ->and(app(LeaveBalance::class)->for($employee->id, $this->earned->id, 2026))->toBe(0.0);

    $decision = EarnedLeaveAccrual::where('employee_id', $employee->id)->forMonth(2026, 3)->sole();
    expect((float) $decision->days_granted)->toBe(0.0)
        ->and($decision->withheld_reason)->toContain('4 ordinary lates')
        ->and($decision->ordinary_lates)->toBe(4);
});

it('withholds the day on serious lates independently of the ordinary count', function () {
    $employee = ($this->makeEmployee)('BDG-000001');
    // No ordinary lates at all, two serious ones - past the allowance of 1.
    ($this->lateDay)($employee, '2026-03-02', '09:40:00');
    ($this->lateDay)($employee, '2026-03-03', '09:40:00');

    $result = ($this->accrue)();

    expect($result['withheld'])->toBe(1);

    $decision = EarnedLeaveAccrual::where('employee_id', $employee->id)->forMonth(2026, 3)->sole();
    expect($decision->withheld_reason)->toContain('2 serious lates')
        ->and($decision->serious_lates)->toBe(2)
        ->and($decision->ordinary_lates)->toBe(0);
});

// ⛔ Joy, 24 Aug 2026, asked and answered: probationers get no earned leave.
it('gives a probationer nothing however clean the month was', function () {
    $probationer = ($this->makeEmployee)('BDG-000002', 'probation');

    $result = ($this->accrue)();

    expect($result['granted'])->toBe(0)
        ->and($result['withheld'])->toBe(0)
        ->and(app(LeaveBalance::class)->for($probationer->id, $this->earned->id, 2026))->toBe(0.0)
        ->and(EarnedLeaveAccrual::count())->toBe(0);
});

// Permanent as at the LAST DAY of the accrued month, which is the only thing a
// confirmation date can answer - a March accrual is not earned by someone
// confirmed in April.
it('accrues from the month the employee was permanent by, not before it', function () {
    $confirmedLater = ($this->makeEmployee)('BDG-000003', 'permanent', '2026-04-15');
    $confirmedEarlier = ($this->makeEmployee)('BDG-000004', 'permanent', '2026-03-10');

    $result = ($this->accrue)();

    expect($result['granted'])->toBe(1)
        ->and(app(LeaveBalance::class)->for($confirmedLater->id, $this->earned->id, 2026))->toBe(0.0)
        ->and(app(LeaveBalance::class)->for($confirmedEarlier->id, $this->earned->id, 2026))->toBe(1.0);
});

it('decides once however many times it runs', function () {
    $employee = ($this->makeEmployee)('BDG-000001');

    ($this->accrue)();
    $second = ($this->accrue)();

    expect($second['granted'])->toBe(0)
        ->and($second['skipped'])->toBe(1)
        ->and(app(LeaveBalance::class)->for($employee->id, $this->earned->id, 2026))->toBe(1.0)
        ->and(EarnedLeaveAccrual::where('employee_id', $employee->id)->forMonth(2026, 3)->count())->toBe(1);
});

it('records nothing on a dry run', function () {
    $employee = ($this->makeEmployee)('BDG-000001');

    $result = ($this->accrue)(true);

    expect($result['granted'])->toBe(1)
        ->and($result['rows'])->toHaveCount(1)
        ->and(EarnedLeaveAccrual::count())->toBe(0)
        ->and(app(LeaveBalance::class)->for($employee->id, $this->earned->id, 2026))->toBe(0.0);
});

it('skips cleanly and says how many were passed over when the type is inactive', function () {
    ($this->makeEmployee)('BDG-000001');
    $this->earned->update(['is_active' => false]);

    $result = ($this->accrue)();

    expect($result['granted'])->toBe(0)
        ->and($result['reason'])->toContain('inactive')
        ->and($result['reason'])->toContain('1 employee(s)')
        ->and(EarnedLeaveAccrual::count())->toBe(0);
});

it('does nothing at all while the policy has the accrual switched off', function () {
    ($this->makeEmployee)('BDG-000001');
    $this->policy->update(['config' => AttendancePolicySeeder::defaultConfig()]);

    $result = ($this->accrue)();

    expect($result['reason'])->toContain('switched off')
        ->and(EarnedLeaveAccrual::count())->toBe(0);
});

it('carries the accrual rule through the policy config round trip', function () {
    $config = PolicyConfig::fromArray($this->policy->config);

    expect($config->elAccrual->enabled)->toBeTrue()
        ->and($config->elAccrual->daysPerMonth)->toBe(1.0)
        ->and($config->elAccrual->maxOrdinaryLates)->toBe(3)
        ->and($config->elAccrual->maxSeriousLates)->toBe(1);

    // And back out again unchanged - the screen edits exactly what the engine
    // reads, defaults included.
    expect(PolicyConfig::fromArray($config->toArray())->elAccrual->toArray())
        ->toBe($config->elAccrual->toArray());
});

it('lets HR revise the accrual rule from the policy screen as a new version', function () {
    $current = PolicyConfig::fromArray($this->policy->config)->toArray();

    $this->actingAs($this->hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.policies.revise', $this->policy), [
            'effective_from' => '2026-04-01',
            'early_leave_grace_minutes' => $current['early_leave_grace_minutes'],
            'half_day_maximum_hours' => $current['half_day_maximum_hours'],
            'full_day_minimum_hours' => $current['full_day_minimum_hours'],
            'half_day_arrival_after_minutes' => $current['half_day_arrival_after_minutes'],
            'count_holiday_work_without_approval' => $current['count_holiday_work_without_approval'],
            'duplicate_scan_window_seconds' => $current['duplicate_scan_window_seconds'],
            'grace_windows' => $current['grace_windows'],
            'el_accrual' => ['enabled' => true, 'days_per_month' => 2, 'max_ordinary_lates' => 5, 'max_serious_lates' => 2],
        ])->assertRedirect()->assertSessionHasNoErrors();

    $revised = AttendancePolicy::where('version', 2)->sole();
    expect($revised->toConfig()->elAccrual->daysPerMonth)->toBe(2.0)
        ->and($revised->toConfig()->elAccrual->maxOrdinaryLates)->toBe(5);

    // The old version keeps the rule it was written with.
    expect($this->policy->fresh()->toConfig()->elAccrual->daysPerMonth)->toBe(1.0);
});

// ⚠ A department revision has no accrual fields on its form. Dropping the rule
// on omission would switch the accrual off for the whole business.
it('keeps the accrual rule when a revision does not mention it', function () {
    $current = PolicyConfig::fromArray($this->policy->config)->toArray();

    $this->actingAs($this->hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.policies.revise', $this->policy), [
            'effective_from' => '2026-04-01',
            'early_leave_grace_minutes' => $current['early_leave_grace_minutes'],
            'half_day_maximum_hours' => $current['half_day_maximum_hours'],
            'full_day_minimum_hours' => $current['full_day_minimum_hours'],
            'half_day_arrival_after_minutes' => $current['half_day_arrival_after_minutes'],
            'count_holiday_work_without_approval' => $current['count_holiday_work_without_approval'],
            'duplicate_scan_window_seconds' => $current['duplicate_scan_window_seconds'],
            'grace_windows' => $current['grace_windows'],
        ])->assertRedirect()->assertSessionHasNoErrors();

    expect(AttendancePolicy::where('version', 2)->sole()->toConfig()->elAccrual->enabled)->toBeTrue();
});
