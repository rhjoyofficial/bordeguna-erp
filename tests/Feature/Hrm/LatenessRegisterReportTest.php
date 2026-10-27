<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\database\seeders\AttendancePolicySeeder;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\EarnedLeaveAccrual;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\ShowCauseNotice;
use App\Modules\Hrm\Reporting\Definitions\LatenessRegisterReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The yearly lateness register (Joy, 24 Aug 2026).
 *
 * The rule worth pinning hardest is the one a hardcoded report would get wrong:
 * office and retail name their lateness bands differently and retail grew a
 * THIRD band on the day this was written, so "ordinary" has to mean whichever
 * band an employee's own policy lists first.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Register Co', 'slug' => 'register-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('hrm.attendance.manage');
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->retail = Department::create(['name' => 'Retail Sales', 'office_id' => $this->office->id, 'is_active' => true]);

    // The company default: two bands, within_grace then late_arrival.
    AttendancePolicy::create([
        'name' => 'Standard', 'version' => 1,
        'config' => AttendancePolicySeeder::defaultConfig(),
        'effective_from' => '2026-01-01',
    ]);

    // ⚠ Retail's own policy, and deliberately a DIFFERENT SHAPE: its own band
    // names, and a THIRD band the company default does not have. This is the
    // real production shape as of 24 Aug 2026.
    AttendancePolicy::create([
        'department_id' => $this->retail->id,
        'name' => 'Retail Sales', 'version' => 1,
        'config' => [
            ...AttendancePolicySeeder::defaultConfig(),
            'grace_windows' => [
                [
                    'key' => 'ordinary_late', 'label' => 'Ordinary late',
                    'from_minutes' => 0, 'to_minutes' => 15,
                    'tiers' => [['from_occurrence' => 1, 'consequence' => 'none'], ['from_occurrence' => 5, 'consequence' => 'deduction']],
                ],
                [
                    'key' => 'serious_late', 'label' => 'Serious late',
                    'from_minutes' => 15, 'to_minutes' => 180,
                    'tiers' => [['from_occurrence' => 1, 'consequence' => 'none'], ['from_occurrence' => 3, 'consequence' => 'deduction']],
                ],
                [
                    'key' => 'beyond_three_hours', 'label' => 'More than 3 hours late',
                    'from_minutes' => 180, 'to_minutes' => null,
                    'tiers' => [['from_occurrence' => 1, 'consequence' => 'deduction']],
                    'requires_review' => true,
                ],
            ],
        ],
        'effective_from' => '2026-01-01',
    ]);

    $this->makeEmployee = function (string $code, ?Department $department = null) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => 'Emp'.$code, 'last_name' => 'Test',
            'employment_type' => 'permanent', 'status' => 'active',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'department_id' => $department?->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        return $employee;
    };

    $this->lateRow = function (Employee $employee, string $date, string $window, int $minutes, string $consequence = 'none') {
        return DailyAttendance::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => AttendanceStatus::Late,
            'consequence' => AttendanceConsequence::from($consequence),
            'worked_minutes' => 480,
            'late_minutes' => $minutes,
            'grace_window_key' => $window,
            'occurrence_number' => 1,
        ]);
    };

    $this->rowsFor = function (array $values = []) {
        $report = app(LatenessRegisterReport::class);

        return collect($report->rows(new ReportParameters(['year' => '2026', ...$values])))->all();
    };
});

it('counts each employee against the bands their own policy lists', function () {
    $officeWorker = ($this->makeEmployee)('BDG-000001');
    $retailWorker = ($this->makeEmployee)('BDG-000002', $this->retail);

    // Office band names.
    ($this->lateRow)($officeWorker, '2026-03-02', 'within_grace', 5);
    ($this->lateRow)($officeWorker, '2026-03-03', 'late_arrival', 40);
    // Retail's own names, including the third band the default does not have.
    ($this->lateRow)($retailWorker, '2026-03-02', 'ordinary_late', 6);
    ($this->lateRow)($retailWorker, '2026-03-04', 'serious_late', 45);
    ($this->lateRow)($retailWorker, '2026-03-05', 'beyond_three_hours', 200, 'deduction');

    $rows = collect(($this->rowsFor)())->where('month', 'March');

    $office = $rows->firstWhere('employee_code', 'BDG-000001');
    $retail = $rows->firstWhere('employee_code', 'BDG-000002');

    expect($office['ordinary_lates'])->toBe(1)
        ->and($office['serious_lates'])->toBe(1)
        ->and($office['extreme_lates'])->toBe(0)
        ->and($office['late_minutes'])->toBe(45);

    expect($retail['ordinary_lates'])->toBe(1)
        ->and($retail['serious_lates'])->toBe(1)
        ->and($retail['extreme_lates'])->toBe(1)
        ->and($retail['deduction_days'])->toBe(1);
});

it('prints twelve months for every employee, clean ones as zeros', function () {
    ($this->makeEmployee)('BDG-000001');

    $rows = ($this->rowsFor)();

    expect($rows)->toHaveCount(12)
        ->and($rows[0]['month'])->toBe('January')
        ->and($rows[0]['ordinary_lates'])->toBe(0)
        ->and($rows[11]['month'])->toBe('December');
});

it('reports deducted days net of forgiveness, with the forgiven ones beside them', function () {
    $employee = ($this->makeEmployee)('BDG-000001');

    ($this->lateRow)($employee, '2026-04-06', 'within_grace', 9, 'deduction');
    ($this->lateRow)($employee, '2026-04-07', 'within_grace', 8, 'deduction');

    AttendanceWaiver::create([
        'business_id' => $this->business->id,
        'employee_id' => $employee->id,
        'attendance_date' => '2026-04-07',
        'waived_status' => AttendanceStatus::Late->value,
        'waived_consequence' => AttendanceConsequence::Deduction->value,
        'reason' => 'Traffic on the day of the strike.',
        'waived_by' => $this->hr->id,
    ]);

    $april = collect(($this->rowsFor)())->firstWhere('month', 'April');

    expect($april['deduction_days'])->toBe(1)
        ->and($april['forgiven_days'])->toBe(1);
});

it('shows the show cause notices and the earned leave decision for the month', function () {
    $employee = ($this->makeEmployee)('BDG-000001');

    ShowCauseNotice::create([
        'business_id' => $this->business->id,
        'employee_id' => $employee->id,
        'issued_by' => $this->hr->id,
        'subject' => 'Repeated late arrival',
        'body' => 'Please explain.',
        'issued_at' => '2026-05-12 10:00:00',
    ]);

    EarnedLeaveAccrual::create([
        'business_id' => $this->business->id,
        'employee_id' => $employee->id,
        'year' => 2026, 'month' => 5,
        'days_granted' => 0,
        'withheld_reason' => '4 ordinary lates in the month, more than the 3 allowed.',
        'ordinary_lates' => 4, 'serious_lates' => 0,
    ]);

    EarnedLeaveAccrual::create([
        'business_id' => $this->business->id,
        'employee_id' => $employee->id,
        'year' => 2026, 'month' => 6,
        'days_granted' => 1,
        'ordinary_lates' => 1, 'serious_lates' => 0,
    ]);

    $rows = collect(($this->rowsFor)());

    expect($rows->firstWhere('month', 'May')['notices'])->toBe(1)
        ->and($rows->firstWhere('month', 'May')['earned_leave'])->toContain('Withheld')
        ->and($rows->firstWhere('month', 'June')['earned_leave'])->toBe('1 day earned')
        ->and($rows->firstWhere('month', 'July')['earned_leave'])->toBe('');
});

it('offers the register to HR and withholds it from everyone else', function () {
    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    $staff->assignRole('Staff');

    $registry = app(ReportRegistry::class);

    expect($registry->availableTo($this->hr)->keys())->toContain('attendance_lateness_register')
        ->and($registry->availableTo($staff)->keys())->not->toContain('attendance_lateness_register');
});

it('is reachable through the reports screen', function () {
    $this->actingAs($this->hr)->withSession(['current_business_id' => $this->business->id])
        ->get(route('hrm.reports.show', 'attendance_lateness_register'))
        ->assertOk();
});

// ⚠⚠ SLICE 4. The main agent added a third band to retail as DATA on the same
// day this was built. Nothing in the editor or the engine may assume two.
it('lets HR revise a policy whose window set is not the shape it was seeded with', function () {
    $policy = AttendancePolicy::where('department_id', $this->retail->id)->sole();
    $current = PolicyConfig::fromArray($policy->config)->toArray();

    expect($current['grace_windows'])->toHaveCount(3);

    // Add a fourth band and revise: the round trip must keep all four.
    $windows = [...$current['grace_windows']];
    $windows[2]['to_minutes'] = 300;
    $windows[] = [
        'key' => '', 'label' => 'Half the day gone', 'from_minutes' => 300, 'to_minutes' => null,
        'tiers' => [['from_occurrence' => 1, 'consequence' => 'disciplinary']], 'requires_review' => true,
    ];

    $this->actingAs($this->hr)->withSession(['current_business_id' => $this->business->id])
        ->post(route('hrm.policies.revise', $policy), [
            'effective_from' => '2026-09-01',
            'early_leave_grace_minutes' => $current['early_leave_grace_minutes'],
            'half_day_maximum_hours' => $current['half_day_maximum_hours'],
            'full_day_minimum_hours' => $current['full_day_minimum_hours'],
            'half_day_arrival_after_minutes' => $current['half_day_arrival_after_minutes'],
            'count_holiday_work_without_approval' => $current['count_holiday_work_without_approval'],
            'duplicate_scan_window_seconds' => $current['duplicate_scan_window_seconds'],
            'grace_windows' => $windows,
        ])->assertRedirect()->assertSessionHasNoErrors();

    $revised = AttendancePolicy::where('department_id', $this->retail->id)->where('version', 2)->sole();
    $bands = $revised->toConfig()->graceWindows;

    expect($bands)->toHaveCount(4)
        ->and($bands[3]->key)->toBe('half_the_day_gone')
        ->and($bands[3]->toMinutes)->toBeNull()
        ->and($bands[2]->toMinutes)->toBe(300);

    // And the engine still resolves a band from the enlarged set.
    expect($revised->toConfig()->windowFor(400)?->key)->toBe('half_the_day_gone')
        ->and($revised->toConfig()->windowFor(200)?->key)->toBe('beyond_three_hours');
});

// ⭐ 27 Oct 2026 (Joy): "for employees everything not 1-31 always 24-23".
it('groups the year by payroll cycle, so the 24th belongs to the next month', function () {
    $employee = ($this->makeEmployee)('BDG-000001');

    ($this->lateRow)($employee, '2025-12-24', 'within_grace', 5); // January 2026's cycle
    ($this->lateRow)($employee, '2026-03-23', 'within_grace', 5); // last day of March
    ($this->lateRow)($employee, '2026-03-24', 'within_grace', 5); // first day of April
    ($this->lateRow)($employee, '2026-12-24', 'within_grace', 5); // January 2027, not this year

    ShowCauseNotice::create([
        'business_id' => $this->business->id,
        'employee_id' => $employee->id,
        'issued_by' => $this->hr->id,
        'subject' => 'Repeated late arrival',
        'body' => 'Please explain.',
        'issued_at' => '2026-05-25 10:00:00',
    ]);

    $rows = collect(($this->rowsFor)());

    expect($rows->firstWhere('month', 'January')['ordinary_lates'])->toBe(1)
        ->and($rows->firstWhere('month', 'March')['ordinary_lates'])->toBe(1)
        ->and($rows->firstWhere('month', 'April')['ordinary_lates'])->toBe(1)
        ->and($rows->firstWhere('month', 'December')['ordinary_lates'])->toBe(0)
        ->and($rows->firstWhere('month', 'May')['notices'])->toBe(0)
        ->and($rows->firstWhere('month', 'June')['notices'])->toBe(1);
});
