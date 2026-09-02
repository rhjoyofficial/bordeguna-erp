<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Reporting\Definitions\SalarySheetReport;
use App\Modules\Hrm\Reporting\ReportDataset;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Hrm\Reporting\ReportRunner;
use App\Modules\Hrm\Reporting\ReportTotals;
use Illuminate\Support\Facades\View;
use Illuminate\Support\LazyCollection;

/**
 * Joy, 2 September 2026: "company wanted that prepared by, checked by and
 * approved by auto fill. cause how added will prepared by, who review will be
 * checked by also who approve will be approve by, if anywhere data miss ...
 * will be their empty."
 *
 * ⚠ The original design left all three blank on principle - "printing a name
 * would be the document asserting who approved it before anybody had". Joy's
 * rule keeps that principle intact: a name prints ONLY when the underlying
 * document actually records that the act happened (a payroll run knows who
 * calculated, reviewed and approved it); everything else stays a blank line.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Signs Co', 'slug' => 'signs-co']);
    app(BusinessContext::class)->set($this->business->id);
});

function renderReport(ReportMeta $meta): string
{
    return View::make('hrm.report', [
        'dataset' => new ReportDataset(
            columns: [],
            rows: LazyCollection::empty(),
            meta: $meta,
            totals: new ReportTotals,
        ),
        'print' => false,
    ])->render();
}

it('prints the three names when the meta knows them', function () {
    $html = renderReport(new ReportMeta(
        title: 'Salary sheet',
        businessName: 'Signs Co',
        preparedBy: 'Musa Islam',
        checkedBy: 'Shuborna Mostafa',
        approvedBy: 'Chowdhury Mohammad Moin',
    ));

    expect($html)->toContain('Musa Islam')
        ->and($html)->toContain('Shuborna Mostafa')
        ->and($html)->toContain('Chowdhury Mohammad Moin');
});

it('keeps the blank line where a name is not on record', function () {
    $html = renderReport(new ReportMeta(title: 'Trial balance', businessName: 'Signs Co'));

    // The generic hint, three times - exactly the pre-existing behaviour.
    expect(substr_count($html, 'Name &amp; designation'))->toBe(3);
});

it('mixes: a known preparer prints, an unrecorded approver stays blank', function () {
    $html = renderReport(new ReportMeta(
        title: 'Salary sheet',
        businessName: 'Signs Co',
        preparedBy: 'Musa Islam',
    ));

    expect($html)->toContain('Musa Islam')
        ->and(substr_count($html, 'Name &amp; designation'))->toBe(2);
});

it('fills the salary sheet from the run: calculator prepared, reviewer checked, approver approved', function () {
    $calculator = User::factory()->create(['name' => 'Musa Islam']);
    $reviewer = User::factory()->create(['name' => 'Shuborna Mostafa']);
    $approver = User::factory()->create(['name' => 'Chowdhury Mohammad Moin']);

    $period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23',
        'divisor_days' => 30,
    ]);

    $run = PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => 'approved',
        'calculated_by' => $calculator->id,
        'reviewed_by' => $reviewer->id,
        'approved_by' => $approver->id,
    ]);

    $meta = app(SalarySheetReport::class)->meta(new ReportParameters(['run_id' => $run->id]));

    expect($meta->preparedBy)->toBe('Musa Islam')
        ->and($meta->checkedBy)->toBe('Shuborna Mostafa')
        ->and($meta->approvedBy)->toBe('Chowdhury Mohammad Moin');
});

it('leaves unreached stages empty on a run that is only calculated', function () {
    $calculator = User::factory()->create(['name' => 'Musa Islam']);

    $period = PayrollPeriod::create([
        'business_id' => $this->business->id,
        'year' => 2026, 'month' => 9,
        'start_date' => '2026-08-24', 'end_date' => '2026-09-23',
        'divisor_days' => 30,
    ]);

    $run = PayrollRun::create([
        'business_id' => $this->business->id,
        'payroll_period_id' => $period->id,
        'version' => 1,
        'status' => 'calculated',
        'calculated_by' => $calculator->id,
    ]);

    $meta = app(SalarySheetReport::class)->meta(new ReportParameters(['run_id' => $run->id]));

    expect($meta->preparedBy)->toBe('Musa Islam')
        ->and($meta->checkedBy)->toBeNull()
        ->and($meta->approvedBy)->toBeNull();
});

it('carries the signatories through the runner instead of dropping them like the note once was', function () {
    // The runner rebuilds ReportMeta field by field; the standing-note bug
    // (note declared, never copied, never printed) must not repeat here.
    $source = file_get_contents((new ReflectionMethod(ReportRunner::class, 'meta'))->getFileName());

    expect($source)->toContain('preparedBy: $declared->preparedBy')
        ->and($source)->toContain('checkedBy: $declared->checkedBy')
        ->and($source)->toContain('approvedBy: $declared->approvedBy');
});
