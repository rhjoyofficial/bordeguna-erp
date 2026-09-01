<?php

/**
 * ⚠⚠⚠ The defect this file exists to prevent cost a cutover morning.
 *
 * A Radix modal Dialog sets `pointer-events: none` on `document.body` while it
 * is open, exempting only its own content. A Popover, Select or DropdownMenu
 * renders through a Portal into `body` - OUTSIDE the dialog - so opened from
 * inside a dialog it inherits `pointer-events: none`. The list appears, the
 * highlight follows the mouse, and every click passes straight THROUGH it to
 * the field underneath. Nothing can be chosen and nothing says why.
 *
 * That killed every picker inside a dialog: 17 screens, among them the loan
 * request form, the payroll adjustment form and the disbursement form. Joy hit
 * it on 1 September 2026 entering three salary advances - "i can not also
 * select manually" - and it was confirmed in his own browser: body
 * `pointer-events: none`, the popover portalled to BODY, computed
 * `pointer-events: none` on the content, and a real click landing on the input
 * beneath the list.
 *
 * A descendant may re-enable hit testing an ancestor switched off, so one class
 * on the portalled content is the entire fix.
 *
 * ⚠ This is a SOURCE guard, not a browser test. It cannot prove the click
 * works; it can only stop the class silently disappearing in a tidy-up, which
 * is exactly how it would come back. Dialog and Sheet are deliberately not
 * listed: they ARE the modal, and Radix exempts their own content already.
 *
 * ⚠⚠ It reads the CONTENT ELEMENT, not the file. The first version of this
 * test searched the whole file and passed while the class was absent from the
 * component - the explanation above was enough to satisfy it. A guard a comment
 * can satisfy guards nothing.
 */
it('keeps every portalled overlay clickable from inside a modal dialog', function (string $file) {
    $source = file_get_contents(resource_path("js/components/ui/{$file}"));

    expect($source)->toContain('Primitive.Portal');

    // From the opening of the portalled Content tag to the end of its props:
    // the only place a className can reach the element Radix renders. Radix owns
    // the positioning wrapper around it and takes no className, so a class
    // anywhere else compiles, ships, and fixes nothing.
    $start = strpos($source, 'Primitive.Content');
    $element = substr($source, $start, strpos($source, '{...props}', $start) - $start);

    expect($element)->toContain('pointer-events-auto');
})->with(['popover.tsx', 'select.tsx', 'dropdown-menu.tsx']);
