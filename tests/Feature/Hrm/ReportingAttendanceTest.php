<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\AttendanceImportBatch;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Reporting\Definitions\AbsenceAndLeaveReport;
use App\Modules\Hrm\Reporting\Definitions\AttendanceExceptionReport;
use App\Modules\Hrm\Reporting\Definitions\ImportExceptionReport;
use App\Modules\Hrm\Reporting\Definitions\MonthlyAttendanceRegisterReport;
use App\Modules\Hrm\Reporting\Definitions\OrganisationSummaryReport;
use App\Modules\Hrm\Reporting\Definitions\OvertimeReport;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRunner;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * W3b — the six remaining RPT-01 attendance reports.
 *
 * Each test aims at the thing that would actually go wrong in that report, not
 * at "does it return rows". Four of the six carry a specific trap the design
 * notes call out, and those are what is pinned here.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Register Co', 'slug' => 'register-co']);
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'hrm.attendance.manage', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo('hrm.attendance.manage');

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->department = Department::create([
        'name' => 'Production', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    $this->makeEmployee = function (string $code, string $name, ?Department $department = null) {
        $employee = Employee::create([
            'employee_code' => $code,
            'first_name' => $name, 'last_name' => 'Ahmed',
            'employment_type' => 'permanent', 'status' => 'active',
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'office_id' => $this->office->id,
            'department_id' => ($department ?? $this->department)->id,
            'is_primary' => true, 'effective_from' => '2025-01-01',
        ]);

        return $employee;
    };

    $this->karim = ($this->makeEmployee)('BDG-000001', 'Karim');

    $this->day = function (Employee $employee, string $date, array $attributes = []) {
        return DailyAttendance::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'status' => AttendanceStatus::Present,
            'worked_minutes' => 480,
            'is_provisional' => false,
            'holds_payroll' => false,
            ...$attributes,
        ]);
    };

    $this->runner = app(ReportRunner::class);

    $this->rows = fn ($definition, array $parameters) => iterator_to_array(
        $this->runner->run($definition, new ReportParameters($parameters), $this->hr)->rows,
    );
});

/*
|--------------------------------------------------------------------------
| #2 — Monthly attendance register
|--------------------------------------------------------------------------
*/

it('gives the register one column per day of the month it was actually asked for', function () {
    $definition = app(MonthlyAttendanceRegisterReport::class);

    $february = collect($definition->columns(new ReportParameters(['month' => '2026-02'])))
        ->pluck('key')->filter(fn ($k) => (bool) preg_match('/^d\d+$/', $k))->values();

    $july = collect($definition->columns(new ReportParameters(['month' => '2026-07'])))
        ->pluck('key')->filter(fn ($k) => (bool) preg_match('/^d\d+$/', $k))->values();

    // The whole reason ReportDefinition::columns() takes its parameters. A fixed
    // 31 columns would print three empty ones here every year.
    expect($february)->toHaveCount(28)
        ->and($july)->toHaveCount(31);
});

it('puts an employee with no attendance at all on the register rather than omitting them', function () {
    // The spine is `employees`, not `daily_attendances` — someone who never
    // punched is exactly who a register is consulted about, and their row must
    // be blank rather than absent.
    $ghost = ($this->makeEmployee)('BDG-000002', 'Rahim');

    ($this->day)($this->karim, '2026-06-15');

    $rows = ($this->rows)(app(MonthlyAttendanceRegisterReport::class), ['month' => '2026-06']);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['employee_code'])->toBe('BDG-000001')
        ->and($rows[0]['d15'])->toBe('P')
        ->and($rows[1]['employee_code'])->toBe('BDG-000002')
        ->and($rows[1]['d15'])->toBe('')
        ->and($rows[1]['present_days'])->toBe(0);

    expect($ghost->employee_code)->toBe('BDG-000002');
});

it('counts a late day as present as well as late, so the register still adds up to the month', function () {
    ($this->day)($this->karim, '2026-06-15', [
        'status' => AttendanceStatus::Late, 'late_minutes' => 25,
    ]);
    ($this->day)($this->karim, '2026-06-16');
    ($this->day)($this->karim, '2026-06-17', ['status' => AttendanceStatus::Absent, 'worked_minutes' => 0]);

    $rows = ($this->rows)(app(MonthlyAttendanceRegisterReport::class), ['month' => '2026-06']);

    expect($rows[0]['present_days'])->toBe(2)
        ->and($rows[0]['late_days'])->toBe(1)
        ->and($rows[0]['absent_days'])->toBe(1)
        ->and($rows[0]['d15'])->toBe('L')
        ->and($rows[0]['d17'])->toBe('A');
});

it('holds only one register row in memory however many employees there are', function () {
    // The streaming property, asserted by construction rather than by memory
    // measurement: rows() is a LazyCollection, and taking one must not have
    // walked the rest.
    foreach (range(2, 6) as $n) {
        ($this->day)(($this->makeEmployee)('BDG-00000'.$n, 'Person'.$n), '2026-06-15');
    }

    $rows = app(MonthlyAttendanceRegisterReport::class)
        ->rows(new ReportParameters(['month' => '2026-06']));

    expect($rows)->toBeInstanceOf(Illuminate\Support\LazyCollection::class)
        ->and($rows->take(2)->all())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| #3 — Late / early / missing punch
|--------------------------------------------------------------------------
*/

it('lists a late arrival that resolved to half day, because late is a minutes fact not a status', function () {
    // ⚠ The exact bug ApplyOccurrencePolicy was caught by in 2b. A status filter
    // would drop this row from the report whose job is to list it.
    ($this->day)($this->karim, '2026-06-15', [
        'status' => AttendanceStatus::HalfDay,
        'late_minutes' => 95,
        'worked_minutes' => 240,
        'grace_window_key' => 'band_2',
        'occurrence_number' => 3,
        'consequence' => AttendanceConsequence::Warning,
    ]);

    ($this->day)($this->karim, '2026-06-16');

    $rows = ($this->rows)(app(AttendanceExceptionReport::class), ['month' => '2026-06', 'exception' => 'all']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('Half day')
        ->and($rows[0]['late_minutes'])->toBe(95)
        ->and($rows[0]['grace_window_key'])->toBe('band_2')
        ->and($rows[0]['occurrence_number'])->toBe(3);
});

it('does not turn the exception report into a list of every ordinary day', function () {
    ($this->day)($this->karim, '2026-06-15');
    ($this->day)($this->karim, '2026-06-16');
    ($this->day)($this->karim, '2026-06-17', ['status' => AttendanceStatus::MissingPunch, 'holds_payroll' => true]);

    $all = ($this->rows)(app(AttendanceExceptionReport::class), ['month' => '2026-06', 'exception' => 'all']);
    $missing = ($this->rows)(app(AttendanceExceptionReport::class), ['month' => '2026-06', 'exception' => 'missing']);

    expect($all)->toHaveCount(1)
        ->and($missing)->toHaveCount(1)
        ->and($missing[0]['holds_payroll'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| #4 — Absent and leave
|--------------------------------------------------------------------------
*/

it('names a leave day only from an applied request, never from a pending one', function () {
    $type = LeaveType::create([
        'business_id' => $this->business->id,
        'name' => 'Annual leave', 'code' => 'AL', 'annual_quota' => 20, 'is_paid' => true,
    ]);

    ($this->day)($this->karim, '2026-06-15', ['status' => AttendanceStatus::OnLeave, 'worked_minutes' => 0]);
    ($this->day)($this->karim, '2026-06-16', ['status' => AttendanceStatus::Absent, 'worked_minutes' => 0]);

    // Applied — this one may name its day.
    LeaveRequest::create([
        'business_id' => $this->business->id, 'employee_id' => $this->karim->id,
        'leave_type_id' => $type->id, 'start_date' => '2026-06-15', 'end_date' => '2026-06-15',
        'days' => 1, 'reason' => 'Family wedding', 'applied_at' => now(),
    ]);

    // Never applied. If this labelled the 16th, an unauthorised absence would
    // read as approved leave on the report someone acts from.
    LeaveRequest::create([
        'business_id' => $this->business->id, 'employee_id' => $this->karim->id,
        'leave_type_id' => $type->id, 'start_date' => '2026-06-16', 'end_date' => '2026-06-16',
        'days' => 1, 'reason' => 'Asked late', 'applied_at' => null,
    ]);

    $rows = ($this->rows)(app(AbsenceAndLeaveReport::class), ['month' => '2026-06', 'kind' => 'all']);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['leave_type'])->toBe('Annual leave')
        ->and($rows[0]['is_paid'])->toBeTrue()
        ->and($rows[0]['reason'])->toBe('Family wedding')
        // Unauthorised absence: no type, and `is_paid` null rather than "No" —
        // an absence is not unpaid leave.
        ->and($rows[1]['leave_type'])->toBeNull()
        ->and($rows[1]['is_paid'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| #5 — Overtime
|--------------------------------------------------------------------------
*/

it('says why each stint of overtime was payable, credited or blocked', function () {
    $eligible = ($this->makeEmployee)('BDG-000010', 'Eligible');
    $ineligible = ($this->makeEmployee)('BDG-000011', 'Ineligible');

    SalaryStructure::create([
        'business_id' => $this->business->id, 'employee_id' => $eligible->id,
        'effective_from' => '2025-01-01', 'overtime_eligible' => true,
    ]);

    SalaryStructure::create([
        'business_id' => $this->business->id, 'employee_id' => $ineligible->id,
        'effective_from' => '2025-01-01', 'overtime_eligible' => false,
    ]);

    OvertimeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $eligible->id,
        'work_date' => '2026-06-15', 'hours' => 2, 'reason' => 'Shipment', 'applied_at' => now(),
    ]);

    OvertimeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $eligible->id,
        'work_date' => '2026-06-19', 'hours' => 8, 'is_holiday_work' => true,
        'compensatory_days' => 1, 'reason' => 'Friday work', 'applied_at' => now(),
    ]);

    OvertimeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $ineligible->id,
        'work_date' => '2026-06-15', 'hours' => 3, 'reason' => 'Helped out', 'applied_at' => now(),
    ]);

    $rows = ($this->rows)(app(OvertimeReport::class), ['month' => '2026-06', 'approved_only' => true]);

    expect(array_column($rows, 'outcome'))->toBe([
        'Payable',
        'Not eligible on that date (ADJ-01)',
        'Compensatory leave (ADJ-04)',
    ]);
});

it('excludes unapproved overtime by default and labels it when included', function () {
    OvertimeAssignment::create([
        'business_id' => $this->business->id, 'employee_id' => $this->karim->id,
        'work_date' => '2026-06-15', 'hours' => 2, 'reason' => 'Not signed off', 'applied_at' => null,
    ]);

    expect(($this->rows)(app(OvertimeReport::class), ['month' => '2026-06', 'approved_only' => true]))
        ->toHaveCount(0);

    $included = ($this->rows)(app(OvertimeReport::class), ['month' => '2026-06']);

    expect($included)->toHaveCount(1)
        ->and($included[0]['outcome'])->toBe('Not approved — unpaid')
        ->and($included[0]['is_approved'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| #6 — Device / import exception
|--------------------------------------------------------------------------
*/

it('unpacks each rejected import line into its own row, duplicates included on request', function () {
    AttendanceImportBatch::create([
        'business_id' => $this->business->id,
        'filename' => 'AllInOutData.xls', 'source' => 'csv_import',
        'device_identifier' => '1', 'total_rows' => 5,
        'imported_count' => 2, 'duplicate_count' => 1, 'failed_count' => 2,
        'report' => [
            ['row' => 4, 'status' => 'failed', 'reason' => 'No employee with that enroll ID', 'enroll_id' => '000010'],
            ['row' => 6, 'status' => 'duplicate', 'reason' => 'Already imported', 'enroll_id' => '000012'],
            ['row' => 9, 'status' => 'failed', 'reason' => 'Unreadable timestamp', 'enroll_id' => '104'],
        ],
    ]);

    $failures = ($this->rows)(app(ImportExceptionReport::class), ['outcome' => 'failed']);
    $everything = ($this->rows)(app(ImportExceptionReport::class), ['outcome' => 'all']);

    expect($failures)->toHaveCount(2)
        ->and(array_column($failures, 'line'))->toBe([4, 9])
        ->and($failures[0]['reason'])->toBe('No employee with that enroll ID')
        ->and($failures[0]['device_identifier'])->toBe('1')
        // A duplicate is an exception, not a failure — ATT-08 de-duplication
        // means a re-imported overlapping export is supposed to skip rows.
        ->and($everything)->toHaveCount(3)
        ->and($everything[1]['outcome'])->toBe('Duplicate');
});

/*
|--------------------------------------------------------------------------
| #7 — Office and department summary
|--------------------------------------------------------------------------
*/

it('files each day under the department held on that day, so a mid-month transfer splits', function () {
    $packaging = Department::create([
        'name' => 'Packaging', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    // Karim moves to Packaging on the 16th.
    EmployeeAssignment::where('employee_id', $this->karim->id)->update(['effective_to' => '2026-06-15']);
    EmployeeAssignment::create([
        'employee_id' => $this->karim->id, 'office_id' => $this->office->id,
        'department_id' => $packaging->id, 'is_primary' => true, 'effective_from' => '2026-06-16',
    ]);

    ($this->day)($this->karim, '2026-06-15');
    ($this->day)($this->karim, '2026-06-17');
    ($this->day)($this->karim, '2026-06-18', ['status' => AttendanceStatus::Late, 'late_minutes' => 10]);

    $rows = ($this->rows)(app(OrganisationSummaryReport::class), ['month' => '2026-06']);

    expect($rows)->toHaveCount(2);

    $byDepartment = collect($rows)->keyBy('department');

    // The month genuinely was spent in two places, and the two subtotals
    // reconcile to the three days the daily report would show.
    expect((int) $byDepartment['Production']['present_days'])->toBe(1)
        ->and((int) $byDepartment['Packaging']['present_days'])->toBe(2)
        ->and((int) $byDepartment['Packaging']['late_days'])->toBe(1)
        ->and((int) $byDepartment['Production']['employees'])->toBe(1)
        ->and((int) $byDepartment['Packaging']['employees'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| All six, through the framework
|--------------------------------------------------------------------------
*/

it('serves every new attendance report over HTTP in all four formats', function () {
    // ⚠ 3f's lesson, and W3's most important route test: downloads are verified
    // here and with curl, never in a browser — the Claude-in-Chrome extension
    // intercepts them and returns a bogus 204 that looks exactly like a bug.
    ($this->day)($this->karim, '2026-06-15', ['status' => AttendanceStatus::Late, 'late_minutes' => 20]);

    $reports = [
        'attendance_monthly_register' => ['month' => '2026-06'],
        'attendance_exceptions' => ['month' => '2026-06'],
        'attendance_absence_leave' => ['month' => '2026-06'],
        'attendance_overtime' => ['month' => '2026-06'],
        'attendance_import_exceptions' => [],
        'attendance_org_summary' => ['month' => '2026-06'],
    ];

    foreach ($reports as $key => $parameters) {
        foreach (['csv', 'xlsx', 'pdf', 'print'] as $format) {
            $query = http_build_query([...$parameters, 'format' => $format]);

            $this->actingAs($this->hr)
                ->get(route('hrm.reports.run', $key).'?'.$query)
                ->assertOk();
        }
    }
});
