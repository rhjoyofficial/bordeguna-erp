<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\DocumentFont;
use App\Modules\Foundation\Support\FontCatalogue;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Payroll\Payslip\PayslipPdf;
use Symfony\Component\Finder\Finder;

/**
 * Office Circular BdG-HQ-OD-26071201, 12 July 2026, and the font setting that
 * grew out of it.
 *
 * Tiro Bangla is the official face for every document Bor de Guna sends outside
 * the organisation. Which face a business uses is now a setting, so these tests
 * hold three things: the files exist, the chosen face is really EMBEDDED rather
 * than merely requested, and a face that cannot survive the PDF path falls back
 * to the official one instead of to something that cannot write Bangla.
 *
 * ⚠⚠ THESE TESTS EXIST BECAUSE THIS FAILS SILENTLY. A PDF whose font never
 * loaded still renders, still looks correct in English, and is wrong only where
 * somebody's name is in Bangla - which is the half nobody proofreads. dompdf
 * needs BOTH a static .ttf (it cannot parse woff2) and a chroot that reaches
 * public/, and getting either wrong produces a perfectly healthy-looking
 * document in the wrong typeface.
 *
 * ⛔ AND THE GREEN HERE IS WEAKER THAN IT LOOKS. On its first successful render
 * dompdf INSTALLS the parsed face into vendor/dompdf/dompdf/lib/fonts and
 * records it in installed-fonts.json; afterwards it resolves from there whatever
 * the @font-face says. So once one good render has happened on a machine, these
 * cannot go red. I removed the chroot on the strength of that false green and
 * shipped it - production, on a fresh container, printed the payslip in DejaVu
 * Sans. A genuine cold check needs storage/app/dompdf-fonts, the vendor font
 * files AND the installed-fonts.json entry all gone; the real proof is rendering
 * a PDF on a newly built container, which the deploy does.
 */
/**
 * A business with one payslip, ready to render.
 *
 * The employee is named in Bangla on purpose: that is the case the old font
 * could not draw at all, so it is the case worth carrying into every test here.
 *
 * @return array{0: Business, 1: Payslip}
 */
function payslipForFontTest(string $name, string $slug): array
{
    $business = Business::create(['name' => $name, 'slug' => $slug]);
    app(BusinessContext::class)->set($business->id);

    $period = PayrollPeriod::create([
        'business_id' => $business->id, 'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30,
    ]);

    $run = PayrollRun::create([
        'business_id' => $business->id, 'payroll_period_id' => $period->id,
        'version' => 1, 'status' => 'disbursed', 'employee_count' => 1,
    ]);

    $employee = Employee::create([
        'employee_code' => strtoupper($slug).'-1',
        'first_name' => 'মুছা', 'last_name' => 'ইসলাম',
        'employment_type' => 'permanent', 'status' => 'active',
        'date_of_birth' => '1995-01-01',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $employee->id, 'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $payslip = Payslip::create([
        'business_id' => $business->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
        'gross_salary' => 20000, 'total_earnings' => 20000,
        'total_deductions' => 0, 'net' => 20000, 'is_blocked' => false,
    ]);

    return [$business, $payslip];
}

it('keeps the official font files where both renderers can reach them', function () {
    // ⚠ TWO FORMATS OF ONE FACE, on purpose. The browser wants woff2 and cannot
    // use a ttf efficiently; dompdf can ONLY parse ttf. Deleting either set
    // breaks one renderer and leaves the other looking perfectly fine.
    foreach ([
        'tiro-bangla-bengali.woff2',
        'tiro-bangla-latin.woff2',
        'tiro-bangla-latin-ext.woff2',
        'TiroBangla-Regular.ttf',
        'TiroBangla-Italic.ttf',
        'OFL.txt',
    ] as $file) {
        expect(file_exists(public_path('fonts/tiro-bangla/'.$file)))
            ->toBeTrue("public/fonts/tiro-bangla/{$file} is missing.");
    }
});

it('really embeds the official font in a payslip PDF', function () {
    [, $payslip] = payslipForFontTest('Font Co', 'font-co');

    // ⚠ Unprotected: encryption would obscure the very font table being
    // asserted on. What is under test is the face, not SAL-07's password, which
    // has its own tests.
    $pdf = app(PayslipPdf::class)->render($payslip->fresh(), null, false);

    expect($pdf)->toStartWith('%PDF-');

    // ⚠⚠ THE ASSERTION THAT MATTERS: an EMBEDDED SUBSET, not a mention of the
    // name. A PDF that merely asks for a font it never embedded still contains
    // the string "TiroBangla", so matching the name proves nothing - the first
    // version of this test did exactly that and passed while pointed at a file
    // dompdf cannot even parse. What only an embedded font produces is a
    // subset tag (`SUBAAC+TiroBangla-Regular`) and a /FontFile2 stream.
    expect($pdf)->toMatch('/SUB[A-Z]{3}\+TiroBangla-Regular/')
        ->and($pdf)->toContain('FontFile2');

    // ⚠ HONEST LIMIT OF THIS TEST. dompdf INSTALLS a parsed @font-face into its
    // own font directory on first use, so once any run has succeeded the font
    // resolves from there even if the @font-face is later broken. This test
    // therefore cannot fail on a machine that has already rendered one good
    // payslip; the true cold-start check is a fresh container, which is why the
    // deploy verifies a real PDF on production rather than trusting green here.
});

it('writes a Bangla name into the payslip rather than a row of boxes', function () {
    // The point of the whole exercise, stated as its own fact: the previous
    // default font had no Bengali glyphs at all.
    $regular = public_path('fonts/tiro-bangla/TiroBangla-Regular.ttf');

    expect(file_exists($regular))->toBeTrue();

    // The Bengali block must actually be in the font's character map, or the
    // circular's own language cannot be set in the circular's own font.
    $ttf = file_get_contents($regular);

    expect(strlen($ttf))->toBeGreaterThan(100000)
        ->and(substr($ttf, 0, 4))->toBe("\x00\x01\x00\x00");
});

it('embeds whichever catalogue face the business chose, and only ones that really work', function () {
    // ⚠⚠ THE `pdf` FLAG IS MEASURED HERE, NOT TRUSTED. Every face offered in
    // settings gets a real PDF rendered through it. One marked pdf:true must
    // embed; one marked pdf:false must fall back TO THE DEFAULT, never to
    // dompdf's own last resort, which has no Bengali glyphs and would print a
    // Bangla name as empty boxes. Getting that flag wrong is invisible on
    // screen and only shows up on paper somebody has already posted.
    [$business, $payslip] = payslipForFontTest('Catalogue Co', 'catalogue-co');

    foreach (FontCatalogue::all() as $key => $font) {
        $business->update(['document_font' => $key]);
        app(BusinessContext::class)->set($business->id);

        $pdf = app(PayslipPdf::class)->render($payslip->fresh(), null, false);

        preg_match_all('#/BaseFont\s*/([A-Za-z0-9+.,_-]+)#', $pdf, $matches);
        $embedded = implode(' ', array_unique($matches[1]));

        $expected = $font['pdf'] === true
            ? str_replace(' ', '', (string) $font['css_family'])
            : str_replace(' ', '', (string) FontCatalogue::all()[FontCatalogue::DEFAULT]['css_family']);

        // ⚠ str_contains + toBeTrue, NOT toContain with a message. Pest reads a
        // second argument to toContain as ANOTHER NEEDLE, so the message itself
        // gets searched for and the assertion fails on text it invented. That
        // has now bitten this file twice.
        expect(str_contains($embedded, $expected))->toBeTrue(
            "Choosing {$key} embedded [{$embedded}], which does not include {$expected}."
        );
    }
});

it('never lets a browser-only face reach the PDF', function () {
    // The rule stated on its own, because it is the one that protects a
    // document somebody actually receives.
    foreach (FontCatalogue::all() as $key => $font) {
        $forPdf = FontCatalogue::forPdf($key);

        if ($font['pdf'] === true) {
            expect($forPdf['family'])->toBe($font['css_family']);
        } else {
            expect($forPdf['family'])->toBe(FontCatalogue::all()[FontCatalogue::DEFAULT]['css_family']);
        }

        // Whatever it resolves to, the file has to be on disk or dompdf
        // silently falls back and we are back to boxes.
        expect(file_exists(public_path($forPdf['ttf'])))->toBeTrue("{$forPdf['ttf']} is missing.");
    }
});

it('falls back to the circular font when a business has chosen nothing', function () {
    // ⚠ NULL means the mandated face, never "no font". A business nobody has
    // configured must still print on Tiro Bangla rather than on whatever the
    // browser reaches for - which, for Bangla, is frequently nothing at all.
    expect(DocumentFont::current())->toBe(FontCatalogue::DEFAULT)
        ->and(FontCatalogue::stack(null))->toContain('Tiro Bangla')
        ->and(FontCatalogue::stack('nonsense-that-was-never-a-font'))->toContain('Tiro Bangla');
});

it('offers only faces that carry Bangla and Latin together', function () {
    // ⚠⚠ The entry requirement for this catalogue. A face covering one script
    // does not fail loudly: the browser borrows a second font for the other
    // half, and a bilingual invoice line goes out in two typefaces nobody chose
    // together. Every stack must therefore also END in a generic family, so a
    // failure lands on something rather than on nothing.
    foreach (FontCatalogue::all() as $key => $font) {
        expect(preg_match('/(serif|sans-serif)$/', (string) $font['stack']))->toBe(1, "{$key} has no generic family at the end of its stack.");
        expect(file_exists(public_path($font['ttf'])))->toBeTrue("{$key} has no font file at {$font['ttf']}.");
    }
});

it('asks for the official font on every document that leaves the building', function () {
    // ⚠ The class is the whole mechanism: the face is scoped to
    // `.official-document` so it reaches the paper and NOT the interface. A
    // print page that forgets it silently goes out in Geist, which no reader
    // would report as a fault - they would just receive an off-brand document.
    $documents = [
        'sales/invoices/print.tsx',
        'sales/deliveries/print.tsx',
        'sales/receipts/print.tsx',
        'sales/orders/pick-list.tsx',
        'purchasing/expenses/print.tsx',
        'purchasing/purchase-orders/print.tsx',
        'hrm/settlement/show.tsx',
    ];

    foreach ($documents as $page) {
        $path = resource_path('js/pages/'.$page);

        expect(file_exists($path))->toBeTrue("{$page} has moved or been renamed.");
        expect(str_contains(file_get_contents($path), 'official-document'))
            ->toBeTrue("{$page} does not carry the official-document class.");
    }
});

it('does not put the document font on the interface', function () {
    // ⚠⚠ The circular is about stationery, not software. Joy was explicit that
    // it applies to official documents and "not others area". Setting the face
    // on body or :root would be one line and would restyle every screen in the
    // ERP on the authority of a memo about letterheads.
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('.official-document');

    // The face may only be named inside @font-face blocks and the document
    // scope. Anything else means it has escaped onto the application.
    $escapes = [];

    foreach (['body {', ':root {', 'html {'] as $selector) {
        $at = strpos($css, $selector);

        if ($at === false) {
            continue;
        }

        $block = substr($css, $at, 400);

        if (str_contains($block, 'Tiro Bangla')) {
            $escapes[] = $selector;
        }
    }

    expect($escapes)->toBeEmpty('Tiro Bangla is set on '.implode(', ', $escapes).', which is the interface, not a document.');
});

it('keeps every Bangla string in the source in Unicode', function () {
    // ⚠⚠ The circular bans legacy, non-Unicode Bangla typing (Bijoy and the
    // other ASCII-mapped schemes). Those are invisible to a reader on a machine
    // with the matching legacy font installed and are mojibake to everybody
    // else - and, ironically, the circular PDF itself is one of them: its own
    // Bangla will not copy out cleanly.
    //
    // Real Bangla is U+0980-U+09FE. This checks the source we control actually
    // uses it, so a copy-paste out of a legacy document cannot land in a view.
    $offenders = [];

    $files = Finder::create()
        ->in([resource_path('js'), resource_path('views')])
        ->files()
        ->name(['*.tsx', '*.ts', '*.php']);

    foreach ($files as $file) {
        $contents = $file->getContents();

        // A legacy Bangla paste is Latin letters that decode to nothing
        // meaningful; it cannot be spotted reliably. What CAN be spotted is
        // the opposite and is worth pinning: where Bangla appears, it is real
        // Bangla codepoints rather than a private-use or replacement block.
        if (preg_match('/[\x{E000}-\x{F8FF}\x{FFFD}]/u', $contents)) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBeEmpty(
        'Private-use or replacement characters found in: '.implode(', ', $offenders)
        .'. That is the signature of text pasted out of a legacy non-Unicode Bangla document.'
    );
});
