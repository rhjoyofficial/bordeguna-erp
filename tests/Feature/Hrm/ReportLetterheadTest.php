<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Reporting\ReportDataset;
use App\Modules\Hrm\Reporting\ReportLetterhead;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportRunner;
use App\Modules\Hrm\Reporting\ReportTotals;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\LazyCollection;

/**
 * Joy, 1 September 2026: "all the report top middle add the business logo,
 * Bor de Guna text font as said. Under the bottom of the last page of the
 * report will have three part, Prepared By, Checked By and Approve By in one
 * row where will person signatures."
 *
 * ⚠⚠ The logo must reach the page as a DATA URI. It lives on the private disk
 * behind an authenticated, business-scoped route, and dompdf carries no
 * session: a `src="/attachments/..."` would print a broken image on every PDF
 * while the browser print view looked perfect. Only one of those two ever gets
 * checked before a document leaves the building.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->business = Business::create(['name' => 'Letterhead Co', 'slug' => 'letterhead-co']);
    app(BusinessContext::class)->set($this->business->id);
});

it('inlines the business logo rather than linking to it', function () {
    $this->business->attachments()->create([
        'business_id' => $this->business->id,
        'collection' => 'logo',
        'disk' => 'local',
        'path' => Storage::disk('local')->putFile('logos', UploadedFile::fake()->image('logo.png', 40, 40)),
        'original_name' => 'logo.png',
        'mime_type' => 'image/png',
        'size' => 100,
    ]);

    $logo = ReportLetterhead::logoFor($this->business->id);

    expect($logo)->toStartWith('data:image/png;base64,');
});

it('prints without a logo rather than failing when there is none', function () {
    // A business that never uploaded one still gets its reports. Nobody is
    // helped by a payroll register that refuses to open because of an image.
    expect(ReportLetterhead::logoFor($this->business->id))->toBeNull()
        ->and(ReportLetterhead::logoFor(null))->toBeNull();
});

it('signs off with three people in one row, on the last page only', function () {
    $html = View::make('hrm.report', [
        'dataset' => new ReportDataset(
            columns: [],
            rows: LazyCollection::empty(),
            meta: new ReportMeta(title: 'Anything', businessName: 'Letterhead Co'),
            totals: new ReportTotals,
        ),
        'print' => false,
    ])->render();

    expect($html)->toContain('Prepared by')
        ->and($html)->toContain('Checked by')
        ->and($html)->toContain('Approved by');

    // ⚠⚠ THE ASSERTION THAT MATTERS. The provenance stamp is `position: fixed`
    // so dompdf repeats it on every page. The signature block must NOT be:
    // three signature lines on page 1 of 9 invite a signature on page 1 of 9.
    // It sits in normal flow, which is what puts it at the foot of the last
    // page and nowhere else.
    $signatures = substr($html, strpos($html, '.signatures {'), 300);

    expect($signatures)->not->toContain('position: fixed');
});

it('carries a report own standing note onto the printed page', function () {
    // ⚠⚠ FOUND WHILE ADDING THE LETTERHEAD. `ReportRunner::meta()` rebuilds the
    // meta field by field and never copied `note`, so the one report that
    // declares one - the parallel-run reconciliation, whose note states that a
    // 1 BDT difference is a rounding ceiling and NOT a tolerance band - printed
    // without it. Its own docblock says the caveat exists because "a caveat that
    // lives only in the class docblock is a caveat the signatories never see",
    // and it was living in the class docblock.
    $reflection = new ReflectionMethod(ReportRunner::class, 'meta');

    expect(file_get_contents($reflection->getFileName()))->toContain('note: $declared->note');
});
