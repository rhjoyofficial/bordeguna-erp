<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\database\seeders\SalaryComponentSeeder;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Models\SalaryStructureLine;
use App\Modules\Hrm\Payroll\Data\PayrollPeriodSpec;
use App\Modules\Hrm\Reporting\Definitions\ForgivenessRegisterReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Support\ForgivenessValue;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * The forgiveness register (Joy, 25 Aug 2026).
 *
 * ⚠⚠ Forgiving a deduction is the one act in this system where somebody gives
 * money away with a click. Until this report nothing added it up: the lateness
 * register counted forgiven DAYS caused by lateness only, never what they were
 * worth, never a forgiven absence, and never who forgave it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Forgive Co', 'slug' => 'forgive-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new SalaryComponentSeeder)->run();

    $office = Office::create(['business_id' => $this->business->id, 'name' => 'Head Office']);
    $department = Department::create([
        'business_id' => $this->business->id, 'office_id' => $office->id, 'name' => 'Retail',
    ]);

    $this->manager = User::factory()->create(['name' => 'Musa Islam']);
    $this->manager->businesses()->attach($this->business->id);

    $this->employee = Employee::create([
        'employee_code' => 'BDG-000019',
        'first_name' => 'Shoykod', 'last_name' => 'Hossain',
        'employment_type' => 'permanent', 'status' => 'active',
        'joining_date' => '2025-01-01',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $this->employee->id,
        'office_id' => $office->id,
        'department_id' => $department->id,
        'is_primary' => true,
        'effective_from' => '2025-01-01',
    ]);

    // ⚠ Gross is the sum of the structure's LINES, not a column. 35,000 as a
    // single Basic line, so one day is 35000/30 = 1,166.67 under PAY-02.
    $this->giveSalary = function (string $amount, string $from) {
        $structure = SalaryStructure::create([
            'business_id' => $this->business->id,
            'employee_id' => $this->employee->id,
            'effective_from' => $from,
        ]);

        SalaryStructureLine::create([
            'salary_structure_id' => $structure->id,
            'salary_component_id' => SalaryComponent::where('code', 'basic')->first()->id,
            'value' => $amount,
        ]);

        return $structure;
    };

    ($this->giveSalary)('35000', '2025-01-01');

    $this->forgive = function (string $date, ?string $revokedAt = null, string $reason = 'Family emergency') {
        return AttendanceWaiver::create([
            'business_id' => $this->business->id,
            'employee_id' => $this->employee->id,
            'attendance_date' => $date,
            'waived_status' => 'late',
            'waived_consequence' => 'deduct_one_day',
            'reason' => $reason,
            'waived_by' => $this->manager->id,
            'revoked_at' => $revokedAt,
            'revoked_by' => $revokedAt === null ? null : $this->manager->id,
        ]);
    };

    $this->rowsFor = function (int $year) {
        $rows = collect(app(ForgivenessRegisterReport::class)
            ->rows(new ReportParameters(['year' => (string) $year]))->all());

        return $rows->keyBy('month');
    };
});

it('prices a forgiven day at what payroll would have deducted', function () {
    // ⚠ Not re-derived here: the same rule payroll uses, on the salary in force
    // that day. 35000 / 30.
    $value = ForgivenessValue::for(($this->forgive)('2026-03-10'));

    expect($value['amount'])->toBe(1166.67)
        ->and($value['month'])->toBe(3)
        ->and($value['year'])->toBe(2026);
});

it('puts a day forgiven after the cutoff into the next payroll month', function () {
    // ⚠⚠ C-WVR-4. Under the 24-to-23 cycle the 26th of August is September's
    // payroll, and September is where that money would have been taken.
    expect(ForgivenessValue::for(($this->forgive)('2026-08-26'))['month'])->toBe(9)
        ->and(ForgivenessValue::for(($this->forgive)('2026-08-23'))['month'])->toBe(8);

    expect(PayrollPeriodSpec::containing(Carbon::parse('2026-12-26'))->year)->toBe(2027)
        ->and(PayrollPeriodSpec::containing(Carbon::parse('2026-12-26'))->month)->toBe(1);
});

it('adds up the days and the money for a month, and names who forgave', function () {
    ($this->forgive)('2026-03-03');
    ($this->forgive)('2026-03-04');

    $march = ($this->rowsFor)(2026)['Mar 2026'];

    expect($march['forgiven_days'])->toBe(2)
        ->and($march['forgiven_amount'])->toBe(2333.34)
        ->and($march['forgiven_by'])->toBe('Musa Islam')
        ->and($march['revoked_days'])->toBe(0);
});

it('keeps an undone waiver out of the money and counts it separately', function () {
    // ⚠⚠ C-WVR-3. A revoked waiver forgave nothing in the end, but it must stay
    // visible: an undo that vanished would be a way to forgive money quietly.
    ($this->forgive)('2026-04-06');
    ($this->forgive)('2026-04-07', revokedAt: '2026-04-20 10:00:00');

    $april = ($this->rowsFor)(2026)['Apr 2026'];

    expect($april['forgiven_days'])->toBe(1)
        ->and($april['forgiven_amount'])->toBe(1166.67)
        ->and($april['revoked_days'])->toBe(1);
});

it('prints every month of the year, so a clean month reads as nothing happened', function () {
    ($this->forgive)('2026-05-05');

    $rows = ($this->rowsFor)(2026);

    expect($rows)->toHaveCount(12)
        ->and($rows['Jan 2026']['forgiven_days'])->toBe(0)
        ->and($rows['Jan 2026']['forgiven_amount'])->toBe(0.0)
        ->and($rows['May 2026']['forgiven_days'])->toBe(1);
});

it('prices at the salary of the day forgiven, not the salary today', function () {
    // A raise in June must not rewrite what March gave away.
    ($this->giveSalary)('60000', '2026-06-01');

    ($this->forgive)('2026-03-10');
    ($this->forgive)('2026-07-10');

    $rows = ($this->rowsFor)(2026);

    expect($rows['Mar 2026']['forgiven_amount'])->toBe(1166.67)
        ->and($rows['Jul 2026']['forgiven_amount'])->toBe(2000.0);
});

it('carries the reason across, because a waiver moves money', function () {
    ($this->forgive)('2026-09-02', reason: 'Bus strike, whole area stopped');

    expect(($this->rowsFor)(2026)['Sep 2026']['reasons'])->toContain('Bus strike');
});

it('is a payroll report, not an attendance one', function () {
    // ⚠ Group matters: a scoped manager is withheld the whole Payroll group,
    // and this report states amounts per person.
    $report = app(ForgivenessRegisterReport::class);

    expect($report->group())->toBe('Payroll')
        ->and($report->permission())->toBe('hrm.payroll.manage')
        ->and($report->totalColumns())->toContain('forgiven_amount');
});

it('does not leak one employee\'s rows into another\'s', function () {
    // ⚠⚠ `yield from` has leaked keys into the outer generator three times on
    // this project, silently overwriting one person's twelve months with the
    // next person's.
    $second = Employee::create([
        'employee_code' => 'BDG-000020',
        'first_name' => 'Rakib', 'last_name' => 'Hossain',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $second->id, 'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    ($this->forgive)('2026-02-02');

    $rows = collect(app(ForgivenessRegisterReport::class)
        ->rows(new ReportParameters(['year' => '2026']))->all());

    expect($rows)->toHaveCount(24)
        ->and($rows->where('employee_code', 'BDG-000019')->count())->toBe(12)
        ->and($rows->where('employee_code', 'BDG-000020')->count())->toBe(12)
        ->and($rows->where('employee_code', 'BDG-000020')->sum('forgiven_days'))->toBe(0);
});
