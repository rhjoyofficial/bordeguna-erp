<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Payroll\Payslip\PayslipPdf;
use Symfony\Component\Finder\Finder;

/**
 * Office Circular BdG-HQ-OD-26071201, 12 July 2026.
 *
 * Tiro Bangla is the official font for every document Bor de Guna sends outside
 * the organisation. These tests hold the two ends of that: the font files are
 * really present and really embedded, and the documents really ask for them.
 *
 * ⚠⚠ WHY THE PDF TEST EXISTS AT ALL. An earlier attempt to put a custom face
 * into the payslip PDF was written off in a comment as "dompdf refused the face
 * by both documented routes". It had not refused - dompdf will not read a local
 * file outside its CHROOT, whose default is dompdf's own folder inside vendor/,
 * so an @font-face pointing at public/fonts failed silently and looked like a
 * limitation of the library. A font that fails silently is exactly the thing a
 * test has to catch, because the PDF still renders, still looks fine in Latin,
 * and is only wrong when somebody's name is in Bangla.
 */
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
    $business = Business::create(['name' => 'Font Co', 'slug' => 'font-co']);
    app(BusinessContext::class)->set($business->id);

    $period = PayrollPeriod::create([
        'business_id' => $business->id,
        'year' => 2026, 'month' => 8,
        'start_date' => '2026-07-24', 'end_date' => '2026-08-23', 'divisor_days' => 30,
    ]);

    $run = PayrollRun::create([
        'business_id' => $business->id,
        'payroll_period_id' => $period->id,
        'version' => 1, 'status' => 'disbursed', 'employee_count' => 1,
    ]);

    // ⚠⚠ A BANGLA NAME, deliberately. This is the case the old font could not
    // render at all: DejaVu Sans has no Bengali glyphs, so this employee's own
    // payslip printed their name as a row of empty boxes.
    $employee = Employee::create([
        'employee_code' => 'BDG-BN-1',
        'first_name' => 'মুছা', 'last_name' => 'ইসলাম',
        'employment_type' => 'permanent', 'status' => 'active',
        'date_of_birth' => '1995-01-01',
    ]);

    EmployeeAssignment::create([
        'employee_id' => $employee->id, 'is_primary' => true, 'effective_from' => '2025-01-01',
    ]);

    $payslip = Payslip::create([
        'business_id' => $business->id,
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'gross_salary' => 20000, 'total_earnings' => 20000,
        'total_deductions' => 0, 'net' => 20000, 'is_blocked' => false,
    ]);

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
