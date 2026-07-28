<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Jobs\RenderReportJob;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\Definitions\DailyAttendanceReport;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRegistry;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportRunner;
use App\Modules\Hrm\Reporting\ReportScope;
use App\Modules\Hrm\Reporting\ReportValue;
use Illuminate\Support\LazyCollection;
use App\Modules\Hrm\Reporting\ReportColumnType;
use App\Modules\Hrm\Reporting\Writers\CsvWriter;
use App\Modules\Hrm\Reporting\Writers\PdfWriter;
use App\Modules\Hrm\Reporting\Writers\PrintHtmlWriter;
use App\Modules\Hrm\Reporting\Writers\XlsxWriter;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Report Co', 'slug' => 'report-co']);
    app(BusinessContext::class)->set($this->business->id);

    foreach (['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.salary.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage', 'hrm.salary.view']);

    // ⚠ ACL-02 / Correction C5. This Manager holds hrm.payroll.manage — granted
    // through the Roles UI by someone who never read ACL-02 — and must STILL
    // get no payroll report. That is the whole point of the rule living in the
    // registry rather than in the permission.
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()
        ->givePermissionTo(['hrm.attendance.manage', 'hrm.payroll.manage']);

    $this->hr = User::factory()->create();
    $this->hr->businesses()->attach($this->business->id);
    $this->hr->assignRole('Owner');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->office = Office::create(['name' => 'Head Office', 'is_active' => true]);
    $this->department = Department::create([
        'name' => 'Production', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    // ⚠ The manager is an employee too, and C10d needs them to be: their
    // attendance reports are scoped to the department on their own assignment,
    // so a manager the system cannot place is a manager it cannot scope. They
    // carry no attendance row of their own, so no row-count assertion moves.
    $this->managerEmployee = Employee::create([
        'employee_code' => 'BDG-000009',
        'first_name' => 'Nasrin', 'last_name' => 'Akter',
        'employment_type' => 'permanent', 'status' => 'active',
        'user_id' => $this->manager->id,
    ]);

    EmployeeAssignment::create([
        'employee_id' => $this->managerEmployee->id,
        'office_id' => $this->office->id,
        'department_id' => $this->department->id,
        'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $this->date = '2026-06-15';

    $this->employees = collect(['BDG-000001' => 'Karim', 'BDG-000002' => 'Rahim', 'BDG-000003' => 'Sumi'])
        ->map(function (string $first, string $code) {
            $employee = Employee::create([
                'employee_code' => $code,
                'first_name' => $first, 'last_name' => 'Ahmed',
                'employment_type' => 'permanent', 'status' => 'active',
            ]);

            EmployeeAssignment::create([
                'employee_id' => $employee->id,
                'office_id' => $this->office->id,
                'department_id' => $this->department->id,
                'is_primary' => true, 'effective_from' => '2025-01-01',
            ]);

            return $employee;
        });

    // Keyed by employee code, so the "one late arrival" case is selected by
    // code rather than by position.
    foreach ($this->employees as $code => $employee) {
        $late = $code === 'BDG-000001';

        DailyAttendance::create([
            'business_id' => $this->business->id,
            'employee_id' => $employee->id,
            'attendance_date' => $this->date,
            'status' => $late ? AttendanceStatus::Late : AttendanceStatus::Present,
            'first_in' => $this->date.' 09:'.($late ? '25' : '00').':00',
            'last_out' => $this->date.' 18:00:00',
            'worked_minutes' => 480,
            'late_minutes' => $late ? 25 : 0,
            'early_leave_minutes' => 0,
            'is_provisional' => false,
            'holds_payroll' => false,
        ]);
    }

    $this->runner = app(ReportRunner::class);
    $this->definition = app(DailyAttendanceReport::class);
    $this->params = new ReportParameters(['date' => $this->date]);
});

/*
|--------------------------------------------------------------------------
| The framework
|--------------------------------------------------------------------------
*/

it('streams rows and accumulates totals during the single pass', function () {
    $dataset = $this->runner->run($this->definition, $this->params, $this->hr);

    // Before iteration the accumulator is empty — this is why totalsRow() must
    // never be read before the rows have been walked.
    expect($dataset->totals->rowCount())->toBe(0);

    $rows = iterator_to_array($dataset->rows);

    expect($rows)->toHaveCount(3)
        ->and($dataset->totals->rowCount())->toBe(3)
        ->and($dataset->totals->get('worked_minutes'))->toBe(1440.0)
        ->and($dataset->totals->get('late_minutes'))->toBe(25.0);
});

it('orders rows stably so two runs produce the same file', function () {
    $first = iterator_to_array($this->runner->run($this->definition, $this->params, $this->hr)->rows);
    $second = iterator_to_array($this->runner->run($this->definition, $this->params, $this->hr)->rows);

    expect(array_column($first, 'employee_code'))
        ->toBe(['BDG-000001', 'BDG-000002', 'BDG-000003'])
        ->toBe(array_column($second, 'employee_code'));
});

it('resolves the office and department as at the report date, not as at today', function () {
    // Karim transferred out of Production last week. A report about 15 June
    // must still file him under Production, because that is where he worked
    // that day — using today's assignment would rewrite his history.
    $karim = $this->employees->first();
    EmployeeAssignment::where('employee_id', $karim->id)->update(['effective_to' => '2026-06-30']);

    $other = Department::create(['name' => 'Packaging', 'office_id' => $this->office->id, 'is_active' => true]);
    EmployeeAssignment::create([
        'employee_id' => $karim->id, 'office_id' => $this->office->id,
        'department_id' => $other->id, 'is_primary' => true, 'effective_from' => '2026-07-01',
    ]);

    $rows = iterator_to_array($this->runner->run($this->definition, $this->params, $this->hr)->rows);

    expect($rows[0]['department'])->toBe('Production');
});

it('reports whether the period was locked rather than refusing to run', function () {
    $meta = $this->definition->meta($this->params);

    expect($meta->periodLocked)->toBeFalse()
        ->and($meta->lockNote)->toContain('not inside a locked attendance period')
        ->and($meta->stamp())->toContain('period NOT locked');
});

/*
|--------------------------------------------------------------------------
| The writers — RPT-03's four formats
|--------------------------------------------------------------------------
*/

it('renders the same value identically across csv, xlsx and the print view', function () {
    // The drift this framework exists to prevent: one declaration, three
    // renderings, one value.
    $cases = [
        [1234.5, ReportColumnType::Money, '1234.50', 1234.5, '1,234.50'],
        [480, ReportColumnType::Duration, '8h', '8h', '8h'],
        [true, ReportColumnType::Boolean, 'Yes', 'Yes', 'Yes'],
    ];

    foreach ($cases as [$raw, $type, $csv, $sheet, $display]) {
        expect(ReportValue::forCsv($raw, $type))->toBe($csv)
            ->and(ReportValue::forSpreadsheet($raw, $type))->toBe($sheet)
            ->and(ReportValue::forDisplay($raw, $type))->toBe($display);
    }
});

it('writes a csv with a header row first and a labelled totals row', function () {
    $dataset = $this->runner->run($this->definition, $this->params, $this->hr);
    $path = (new CsvWriter)->write($dataset);
    $csv = file_get_contents($path);
    @unlink($path);

    $lines = array_values(array_filter(explode("\n", trim($csv))));

    // Row 1 is the header — nothing precedes it, or every importer breaks.
    expect($lines[0])->toContain('Code')->toContain('Employee')
        ->and($lines[1])->toContain('BDG-000001')
        // TOTAL is labelled and states its row count, so it can never be read
        // as a payee.
        ->and(end($lines))->toContain('TOTAL (3 rows)')
        // ⚠ The totals row is formatted by the SAME per-column formatter as
        // the body: 1440 minutes reads "24h" here because the column above it
        // reads "8h". A raw 1440 under a column of durations was the live-check
        // finding this assertion now pins.
        ->and(end($lines))->toContain('24h')
        ->and(end($lines))->toContain('25m');
});

it('writes a real xlsx that opens as a zip container', function () {
    $dataset = $this->runner->run($this->definition, $this->params, $this->hr);
    $path = (new XlsxWriter)->write($dataset);

    expect(filesize($path))->toBeGreaterThan(0);

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->locateName('xl/workbook.xml'))->not->toBeFalse();
    $zip->close();

    @unlink($path);
});

it('never lets report data become a live formula in a spreadsheet', function () {
    // A name, reason or note beginning with "=" is user-typed text. openspout's
    // Cell::fromValue() would turn it into a formula; the writer must not.
    $this->employees->first()->update(['first_name' => '=SUM(A1:A9)']);

    $dataset = $this->runner->run($this->definition, $this->params, $this->hr);
    $path = (new XlsxWriter)->write($dataset);

    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($path);

    // The text survives as text; no <f> formula element was emitted for it.
    expect($sheet)->toContain('SUM(A1:A9)')
        ->and($sheet)->not->toContain('<f>');
});

it('renders a pdf and a print view from the one template', function () {
    $pdfPath = (new PdfWriter)->write($this->runner->run($this->definition, $this->params, $this->hr));
    $pdf = file_get_contents($pdfPath);
    @unlink($pdfPath);

    $htmlPath = (new PrintHtmlWriter)->write($this->runner->run($this->definition, $this->params, $this->hr));
    $html = file_get_contents($htmlPath);
    @unlink($htmlPath);

    expect(substr($pdf, 0, 4))->toBe('%PDF')
        ->and($html)->toContain('Daily attendance')
        ->and($html)->toContain('BDG-000001')
        // Both carry the provenance stamp — the same string, from the same meta.
        ->and($html)->toContain('Generated');

    // The print view is not a download; sending it as a file would make it
    // unprintable, which is its only purpose.
    expect((new PrintHtmlWriter)->isDownload())->toBeFalse()
        ->and((new PdfWriter)->isDownload())->toBeTrue();
});

it('states plainly when no rows matched instead of rendering an empty table', function () {
    $empty = new ReportParameters(['date' => '2026-01-01']);
    $path = (new PrintHtmlWriter)->write($this->runner->run($this->definition, $empty, $this->hr));
    $html = file_get_contents($path);
    @unlink($path);

    expect($html)->toContain('No rows matched these filters');
});

/*
|--------------------------------------------------------------------------
| Access — Correction C10d, and ACL-02 which is not negotiable
|--------------------------------------------------------------------------
*/

it('lists only the reports a user may actually run', function () {
    $registry = app(ReportRegistry::class);

    expect($registry->availableTo($this->hr)->keys()->all())
        ->toContain('attendance_daily')
        ->toContain('payroll_salary_sheet');

    expect($registry->availableTo($this->staff)->all())->toBe([]);
});

it('withholds every payroll report from a department manager even when they hold the payroll permission', function () {
    // ACL-02 / Correction C5, asserted in 3a and re-asserted here. This is the
    // rule a Roles-UI grant must not be able to break.
    $registry = app(ReportRegistry::class);
    $available = $registry->availableTo($this->manager);

    expect($this->manager->can('hrm.payroll.manage'))->toBeTrue()
        ->and($available->keys()->all())->toContain('attendance_daily')
        ->and($available->keys()->all())->not->toContain('payroll_salary_sheet');
});

it('scopes a department manager\'s attendance report to their own department', function () {
    // Correction C10d's second rule. W3a shipped only the first (no payroll for
    // a department manager); this is the half that was missing, and the failure
    // it prevents is silent — every row on an unscoped report looks correct.
    $packaging = Department::create([
        'name' => 'Packaging', 'office_id' => $this->office->id, 'is_active' => true,
    ]);

    $outsider = Employee::create([
        'employee_code' => 'BDG-000050',
        'first_name' => 'Jahid', 'last_name' => 'Hasan',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $outsider->id, 'office_id' => $this->office->id,
        'department_id' => $packaging->id, 'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    DailyAttendance::create([
        'business_id' => $this->business->id, 'employee_id' => $outsider->id,
        'attendance_date' => $this->date, 'status' => AttendanceStatus::Present,
        'worked_minutes' => 480, 'is_provisional' => false, 'holds_payroll' => false,
    ]);

    // The manager asks for Packaging explicitly. The scope overwrites the
    // choice rather than merging with it — a filter a restricted user can still
    // edit is not an access control.
    $this->actingAs($this->manager)
        ->get(route('hrm.reports.run', 'attendance_daily').'?date='.$this->date
            .'&department_id='.$packaging->id.'&format=csv')
        ->assertOk()
        ->assertDownload();

    $rows = iterator_to_array(
        $this->runner->run(
            $this->definition,
            new ReportParameters(
                ReportScope::for($this->manager)->apply(['date' => $this->date, 'department_id' => $packaging->id]),
            ),
            $this->manager,
        )->rows,
    );

    expect(array_column($rows, 'employee_code'))
        ->toBe(['BDG-000001', 'BDG-000002', 'BDG-000003'])
        ->not->toContain('BDG-000050');

    // And the owner, unrestricted, still gets exactly what they asked for.
    expect(ReportScope::for($this->hr)->apply(['department_id' => $packaging->id]))
        ->toBe(['department_id' => $packaging->id]);
});

it('withholds attendance reports entirely from a manager whose department cannot be resolved', function () {
    // Fail closed. An unplaceable manager is the case where serving the report
    // unscoped would hand a line manager the whole business.
    $this->managerEmployee->assignments()->delete();

    $registry = app(ReportRegistry::class);

    expect($this->manager->can('hrm.attendance.manage'))->toBeTrue()
        ->and($registry->availableTo($this->manager)->all())->toBe([]);

    $this->actingAs($this->manager)
        ->get(route('hrm.reports.show', 'attendance_daily'))
        ->assertNotFound();
});

it('withholds an attendance report that declares no department parameter from a restricted manager', function () {
    // The other fail-closed case: a report added later that simply forgets the
    // parameter has nothing for the scope to bind to, so it must not be served
    // rather than served whole.
    $unscopable = new class implements ReportDefinition
    {
        public function key(): string
        {
            return 'attendance_unscopable';
        }

        public function name(): string
        {
            return 'Unscopable';
        }

        public function description(): string
        {
            return '';
        }

        public function group(): string
        {
            return 'Attendance';
        }

        public function permission(): string
        {
            return 'hrm.attendance.manage';
        }

        public function parameters(): array
        {
            return [ReportParameter::date('date', 'Date')];
        }

        public function columns(?ReportParameters $parameters = null): array
        {
            return [ReportColumn::text('x', 'X')];
        }

        public function totalColumns(?ReportParameters $parameters = null): array
        {
            return [];
        }

        public function rows(ReportParameters $parameters): LazyCollection
        {
            return LazyCollection::empty();
        }

        public function estimatedRows(ReportParameters $parameters): int
        {
            return 0;
        }

        public function countRows(ReportParameters $parameters): int
        {
            return 0;
        }

        public function meta(ReportParameters $parameters): ReportMeta
        {
            return new ReportMeta(title: 'Unscopable');
        }
    };

    $registry = app(ReportRegistry::class);
    $registry->register($unscopable);

    expect($registry->canRun($this->manager, $unscopable))->toBeFalse()
        // The owner is unrestricted, so the missing parameter costs them nothing.
        ->and($registry->canRun($this->hr, $unscopable))->toBeTrue();
});

it('renders the report list grouped, with the payroll group absent for a manager', function () {
    $this->actingAs($this->hr)
        ->get(route('hrm.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/reports/index')
            ->has('groups.Attendance')
            ->has('groups.Payroll'));

    $this->actingAs($this->manager)
        ->get(route('hrm.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('groups.Attendance')
            ->missing('groups.Payroll'));
});

it('renders the parameter screen with the definition driving the form', function () {
    $this->actingAs($this->hr)
        ->get(route('hrm.reports.show', 'attendance_daily'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hrm/reports/show')
            ->where('report.key', 'attendance_daily')
            // date + office + department + status, and the four RPT-03 formats.
            // `status` was added in RPT-04b so the present/absent/late tiles
            // could be backed by this report rather than re-deriving its query.
            ->has('parameters', 4)
            ->has('formats', 4)
            ->has('columns'));
});

it('404s rather than 403s a report the user may not run', function () {
    // A key that does not exist and a key they may not run give the same
    // answer, so enumerating keys reveals nothing.
    $this->actingAs($this->manager)
        ->get(route('hrm.reports.show', 'payroll_salary_sheet'))
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->get(route('hrm.reports.show', 'no_such_report'))
        ->assertNotFound();
});

it('blocks a staff user from the reports screen entirely', function () {
    $this->actingAs($this->staff)
        ->get(route('hrm.reports.show', 'attendance_daily'))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Over HTTP — the 3f lesson: verify downloads at the route, never in a browser
|--------------------------------------------------------------------------
*/

it('serves each format over HTTP with the right content type', function () {
    $url = fn (string $format) => route('hrm.reports.run', [
        'report' => 'attendance_daily', 'date' => $this->date, 'format' => $format,
    ]);

    $csv = $this->actingAs($this->hr)->get($url('csv'));
    $csv->assertOk();
    expect($csv->headers->get('content-disposition'))->toContain('attachment')
        ->and($csv->headers->get('content-disposition'))->toContain('attendance-daily-');

    $xlsx = $this->actingAs($this->hr)->get($url('xlsx'));
    $xlsx->assertOk();
    expect($xlsx->headers->get('content-disposition'))->toContain('.xlsx');

    $pdf = $this->actingAs($this->hr)->get($url('pdf'));
    $pdf->assertOk();
    expect($pdf->headers->get('content-disposition'))->toContain('.pdf');

    // The print view is served inline as HTML, never as an attachment.
    $print = $this->actingAs($this->hr)->get($url('print'));
    $print->assertOk();
    expect($print->headers->get('content-type'))->toContain('text/html')
        // Cast, because the header is absent entirely on an inline response —
        // which is the correct outcome, not a missing assertion.
        ->and((string) $print->headers->get('content-disposition'))->not->toContain('attachment');
});

it('refuses a run with invalid parameters instead of returning an empty file', function () {
    $this->actingAs($this->hr)
        ->get(route('hrm.reports.run', [
            'report' => 'attendance_daily', 'date' => 'not-a-date', 'format' => 'csv',
        ]))
        ->assertSessionHasErrors('date');
});

it('records who ran which report, for the SEC-06 read audit', function () {
    $this->actingAs($this->hr)->get(route('hrm.reports.run', [
        'report' => 'attendance_daily', 'date' => $this->date, 'format' => 'csv',
    ]))->assertOk();

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'hrm.report',
        'causer_id' => $this->hr->id,
        'description' => 'Ran report: Daily attendance',
    ]);
});

/*
|--------------------------------------------------------------------------
| Correction C10e — queued over the threshold
|--------------------------------------------------------------------------
*/

it('queues a report that is estimated over the threshold instead of serving it', function () {
    Queue::fake();
    config()->set('hrm.reporting.queue_threshold', 2);

    $this->actingAs($this->hr)
        ->get(route('hrm.reports.run', [
            'report' => 'attendance_daily', 'date' => $this->date, 'format' => 'csv',
        ]))
        ->assertRedirect();

    Queue::assertPushed(RenderReportJob::class);
});

it('serves directly when under the threshold', function () {
    Queue::fake();
    config()->set('hrm.reporting.queue_threshold', 5000);

    $this->actingAs($this->hr)->get(route('hrm.reports.run', [
        'report' => 'attendance_daily', 'date' => $this->date, 'format' => 'csv',
    ]))->assertOk();

    Queue::assertNothingPushed();
});

it('estimates the row count without producing a row', function () {
    expect($this->definition->estimatedRows($this->params))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| The framework's own promise: a definition stays thin
|--------------------------------------------------------------------------
*/

it('has every registered report declaring a permission, columns and a group', function () {
    app(ReportRegistry::class)->all()->each(function (ReportDefinition $definition) {
        expect($definition->key())->not->toBeEmpty()
            ->and($definition->permission())->toStartWith('hrm.')
            ->and($definition->group())->toBeIn(['Attendance', 'Payroll'])
            ->and($definition->columns())->not->toBeEmpty();

        foreach ($definition->columns() as $column) {
            expect($column)->toBeInstanceOf(ReportColumn::class);
        }
    });
});
