<?php

/**
 * ⛔⛔ WHY THIS FILE EXISTS.
 *
 * Joy printed the EC-00004 reimbursement voucher to PDF on 7 September 2026 and
 * the sheet came out with the ERP's own breadcrumb bar and an "Add New" button
 * across the top of it. The cause was not a missing class on that one page:
 * THIS APPLICATION HAD NO `@media print` RULE AT ALL. Printing worked only as
 * far as the `print:hidden` utilities somebody had remembered to type, screen by
 * screen, and nobody had ever typed one on the shell that wraps every screen.
 *
 * ⚠ Eleven documents are printed out of this ERP. A rule that has to be
 * remembered per page is a rule that gets forgotten on the twelfth, and the
 * person who finds out is a customer holding the paper. So the rule lives in one
 * block, and this test is what keeps it there.
 *
 * ⚠ Deliberately a source scan, exactly like the em-dash guard. A test that
 * tried to decide "would this element print?" would be a second implementation
 * of a browser's layout engine and would rot the way the reachability test's
 * first version did.
 */
it('keeps the application chrome off a printed document', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // ⚠ `toContain` takes NEEDLES, not a message, so each rule is asserted on
    // its own with the reason attached to the boolean. A message passed as a
    // second argument would quietly become a second string to search for.
    $rules = [
        '@media print' => 'The @media print block is gone. Without it every printed document carries the top bar.',
        // The paper is A4 in this office, and Chrome's default on the machine
        // these documents are printed from is US Letter.
        'size: A4 portrait' => 'The page size is no longer declared, so printing falls back to whatever the browser guesses.',
        // ⛔ The shaded table headers and the OFFICE COPY / EMPLOYEE COPY
        // watermark are the document, not decoration. Chrome drops backgrounds
        // unless asked.
        'print-color-adjust: exact' => 'Backgrounds will be dropped, so the copy watermark and the ruled table headers vanish.',
        '[data-app-chrome]' => 'Nothing hides the shell any more.',
    ];

    foreach ($rules as $rule => $why) {
        expect(str_contains($css, $rule))->toBeTrue("Missing `{$rule}` in app.css. {$why}");
    }
});

/**
 * ⚠ The hook and the rule are two halves of one thing, and either half alone is
 * silent. This is the other half: the shell's furniture has to carry the
 * attribute the stylesheet looks for.
 */
it('marks every piece of shell furniture as chrome', function () {
    $furniture = [
        // The breadcrumb bar, the search box and the Add New button - the ones
        // that actually printed.
        'components/app-sidebar-header.tsx',
        // ⚠ The OUTER sidebar element, because it also owns the spacer that
        // reserves the navigation column's width. Hiding only the panel prints a
        // document indented by an empty column.
        'components/ui/sidebar.tsx',
        // The floating assistant button.
        'components/erp-assistant.tsx',
    ];

    $missing = [];

    foreach ($furniture as $file) {
        if (! str_contains(file_get_contents(resource_path('js/'.$file)), 'data-app-chrome')) {
            $missing[] = $file;
        }
    }

    expect($missing)->toBeEmpty(
        'These render on top of every printed document and no longer carry data-app-chrome: '.implode(', ', $missing),
    );
});
