<?php

/**
 * Bug hunt 16 Oct 2026 (02 M5): Approve on the payroll inputs and increments
 * pages posted no note, so approving your own overtime, bonus, adjustment or
 * increment was refused (Rule #22) with no box to write the reason in. The
 * server side is covered in PayrollInputTest and SalaryRevisionTest; this pins
 * that the screens send the note.
 */
it('sends the approval note from every approve button on the payroll inputs and increments pages', function () {
    $component = file_get_contents(resource_path('js/components/approve-with-note.tsx'));

    expect($component)->toContain('{ note: note.trim() || null }')
        ->and($component)->toContain('errors.self_approval_reason');

    foreach (['js/pages/hrm/payroll/inputs.tsx', 'js/pages/hrm/salary/revisions.tsx'] as $page) {
        $source = file_get_contents(resource_path($page));

        expect($source)->toContain('<ApproveWithNote')
            ->and(preg_match('/router\.post\(\s*(approveUrl|route\(\'hrm\.revisions\.approve\'[^)]*\))\s*,\s*\{\s*\}/', $source))->toBe(0);
    }
});
