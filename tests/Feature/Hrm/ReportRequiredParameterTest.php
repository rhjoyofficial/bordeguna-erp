<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\Definitions\SalarySheetReport;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRunner;
use Illuminate\Support\LazyCollection;

/**
 * A required report parameter is required of every caller.
 *
 * ⚠⚠ WRITTEN AFTER THE CUTOVER REHEARSAL. Run against a copy of production, the
 * salary sheet with no `run_id` returned eighteen rows for nine employees: the
 * rejected August v2 still holds its nine payslips beside the live v3's nine,
 * and the sheet filters by run only when a run is given. Total on the page,
 * 489,830.66, against a real net pay of 239,048.66 - on a money document that
 * gets printed and signed.
 *
 * The screen builds its validation from these same declarations and was never
 * at risk. The rule now lives with the engine, so a job, a command or a future
 * screen cannot lose it.
 */
class StubRunReport implements ReportDefinition
{
    public function key(): string
    {
        return 'stub_needs_a_run';
    }

    public function name(): string
    {
        return 'Stub';
    }

    public function description(): string
    {
        return 'A definition that needs a payroll run.';
    }

    public function group(): string
    {
        return 'Payroll';
    }

    public function permission(): string
    {
        return 'hrm.payroll.view';
    }

    public function parameters(): array
    {
        return [ReportParameter::payrollRun(), ReportParameter::office()];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [ReportColumn::text('a', 'A', 10)];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make([['a' => 'row']]);
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return 1;
    }

    public function countRows(ReportParameters $parameters): int
    {
        return 1;
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        return new ReportMeta(title: 'Stub');
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return [];
    }
}

beforeEach(function () {
    $business = Business::create(['name' => 'Report Co', 'slug' => 'report-co']);
    app(BusinessContext::class)->set($business->id);
});

it('refuses to run a report that is missing a required parameter', function () {
    expect(fn () => app(ReportRunner::class)->run(new StubRunReport, new ReportParameters([])))
        ->toThrow(InvalidArgumentException::class, 'needs run_id');
});

it('runs once the required parameter is there, and ignores optional ones', function () {
    $dataset = app(ReportRunner::class)->run(new StubRunReport, new ReportParameters(['run_id' => 7]));

    expect($dataset->rows->count())->toBe(1);
});

it('still requires the run on the salary sheet itself', function () {
    // ⚠ Pinned on the real definition, not only the stub: this is the report
    // the rehearsal caught, and the guard is worth nothing if the declaration
    // it reads is ever relaxed.
    $runParameter = collect(app(SalarySheetReport::class)->parameters())
        ->firstWhere('key', 'run_id');

    expect($runParameter)->not->toBeNull()
        ->and($runParameter->required)->toBeTrue();
});
