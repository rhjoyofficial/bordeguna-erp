<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The shared UI findings of the owner's practice run (17 Oct 2026), RUN through
 * tests/js/practice-ui.harness.ts (esbuild bundles the real resources/js
 * modules, node runs them), plus a few source checks for wiring a harness
 * cannot reach.
 *
 * F11/F26: Escape, a click outside or the X asks "Discard what you typed?" when
 * the popup's form is dirty, and closes at once when it is clean.
 * F12: popups are top-aligned, so a growing form does not move under the cursor.
 * F13/F19/F22/F27: one way to write a quantity, an amount and a stored code.
 * F18: a date always carries its year.
 */
function practiceUiRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @param list<array<string, mixed>> $calls */
function practiceUi(array $calls): array
{
    $root = practiceUiRoot();
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the practice UI harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/practice-ui.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function practiceUiSource(string $path): string
{
    return file_get_contents(practiceUiRoot().'/resources/js/'.$path);
}

it('counts typing in a field, a picked option and Enter in a picker list as an edit', function () {
    $dialog = ['tag' => 'div', 'attrs' => ['role' => 'dialog']];
    $picker = ['tag' => 'div', 'attrs' => ['cmdk-root' => ''], 'parent' => $dialog];

    $results = practiceUi([
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'input', 'attrs' => ['type' => 'text'], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'input', 'attrs' => [], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'textarea', 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'select', 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'input', 'attrs' => ['type' => 'date'], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'pick', 'target' => ['tag' => 'div', 'attrs' => ['cmdk-item' => '', 'role' => 'option'], 'parent' => $picker]],
        ['op' => 'edited', 'kind' => 'pick', 'target' => ['tag' => 'span', 'parent' => ['tag' => 'button', 'attrs' => ['role' => 'checkbox'], 'parent' => $dialog]]],
        ['op' => 'edited', 'kind' => 'enter', 'target' => ['tag' => 'input', 'attrs' => ['cmdk-input' => ''], 'parent' => $picker]],
    ]);

    expect($results)->each->toBeTrue();
});

it('does not count a picker search box, a plain button, a hidden input or a marked field as an edit', function () {
    $dialog = ['tag' => 'div', 'attrs' => ['role' => 'dialog']];

    $results = practiceUi([
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'input', 'attrs' => ['cmdk-input' => ''], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'input', 'attrs' => ['type' => 'search'], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'input', 'attrs' => ['type' => 'hidden'], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => ['tag' => 'input', 'attrs' => ['type' => 'text'], 'parent' => ['tag' => 'div', 'attrs' => ['data-discard-ignore' => ''], 'parent' => $dialog]]],
        ['op' => 'edited', 'kind' => 'pick', 'target' => ['tag' => 'button', 'attrs' => ['type' => 'button'], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'enter', 'target' => ['tag' => 'input', 'attrs' => ['type' => 'text'], 'parent' => $dialog]],
        ['op' => 'edited', 'kind' => 'change', 'target' => null],
    ]);

    expect($results)->each->toBeFalse();
});

it('asks before closing a dirty form only, and lets the form\'s own dirty flag win', function () {
    [$clean, $edited, $formDirty, $formCleanAfterEdit, $formDirtyUntouched, $optedOut] = practiceUi([
        ['op' => 'confirm', 'edited' => false],
        ['op' => 'confirm', 'edited' => true],
        ['op' => 'confirm', 'dirty' => true, 'edited' => true],
        // Typed, then typed it back: Inertia says clean, so it closes.
        ['op' => 'confirm', 'dirty' => false, 'edited' => true],
        // A line added with a button is dirty though no field was typed in.
        ['op' => 'confirm', 'dirty' => true, 'edited' => false],
        ['op' => 'confirm', 'dirty' => true, 'edited' => true, 'confirmDiscard' => false],
    ]);

    expect($clean)->toBeFalse()
        ->and($edited)->toBeTrue()
        ->and($formDirty)->toBeTrue()
        ->and($formCleanAfterEdit)->toBeFalse()
        ->and($formDirtyUntouched)->toBeTrue()
        ->and($optedOut)->toBeFalse();
});

it('guards Escape, the outside click and the X in the shared popup, and keeps it top-aligned', function () {
    $dialog = practiceUiSource('components/ui/dialog.tsx');

    expect($dialog)
        ->toContain('onEscapeKeyDown={(event) => {')
        ->toContain('onPointerDownOutside={(event) => {')
        ->toContain('Discard what you typed?')
        ->toContain('Keep editing')
        ->toContain('top-[4vh]')
        ->not->toContain('translate-y-[-50%]');

    // The two popups the practice run lost work in pass their form's own flag.
    expect(practiceUiSource('components/inventory/recipe-dialog.tsx'))->toContain('dirty={form.isDirty}')
        ->and(practiceUiSource('components/sales/customer-dialog.tsx'))->toContain('dirty={isDirty}');
});

it('writes a quantity without trailing zeros and an amount with two decimals and commas', function () {
    $results = practiceUi([
        ['op' => 'quantity', 'value' => '0.5000'],
        ['op' => 'quantity', 'value' => '1.0000'],
        ['op' => 'quantity', 'value' => '9000.0000'],
        ['op' => 'quantity', 'value' => '9.1800'],
        ['op' => 'quantity', 'value' => '0.1234'],
        ['op' => 'quantity', 'value' => null],
        ['op' => 'money', 'value' => '14960.00'],
        ['op' => 'money', 'value' => '4037.5'],
        ['op' => 'money', 'value' => '20000.0000'],
        ['op' => 'money', 'value' => '1,500.00'],
        ['op' => 'plain', 'value' => '5.0000'],
        ['op' => 'plain', 'value' => '2.2500'],
        ['op' => 'plain', 'value' => '20000.0000'],
        ['op' => 'plain', 'value' => null],
    ]);

    expect($results)->toBe([
        '0.5', '1', '9,000', '9.18', '0.1234', '-',
        '14,960.00', '4,037.50', '20,000.00', '1,500.00',
        '5', '2.25', '20000', '',
    ]);
});

it('writes stored codes as words and dates with their year', function () {
    $results = practiceUi([
        ['op' => 'label', 'code' => 'walk_in'],
        ['op' => 'label', 'code' => 'dealer'],
        ['op' => 'label', 'code' => 'walk_in', 'labels' => [['value' => 'walk_in', 'label' => 'Walk-in']]],
        ['op' => 'label', 'code' => null],
        ['op' => 'date', 'value' => '2027-09-30'],
        ['op' => 'date', 'value' => '2026-10-05 12:00:00'],
        ['op' => 'date', 'value' => null],
        ['op' => 'date', 'value' => 'soon'],
    ]);

    expect($results)->toBe(['Walk in', 'Dealer', 'Walk-in', '-', '30 Sep 2027', '5 Oct 2026', '-', 'soon']);
});

it('offers "New category" only when no category already has that name', function () {
    $labels = ['Packaging Jars', 'Packaging Labels'];

    expect(practiceUi([
        ['op' => 'offersCreate', 'typed' => 'Packaging Jars', 'labels' => $labels],
        ['op' => 'offersCreate', 'typed' => '  packaging jars ', 'labels' => $labels],
        ['op' => 'offersCreate', 'typed' => 'Packaging', 'labels' => $labels],
        ['op' => 'offersCreate', 'typed' => 'Packaging Closures', 'labels' => $labels],
        ['op' => 'offersCreate', 'typed' => '', 'labels' => $labels],
    ]))->toBe([false, false, true, true, true]);
});

it('fits the receive-goods and products tables at a 1280 px laptop and keeps the order form still', function () {
    $receive = practiceUiSource('pages/purchasing/purchase-orders/show.tsx');

    // F17: batch and both dates share one cell; the red hint goes once the batch is typed.
    expect($receive)->not->toContain('min-w-[52rem]')
        ->toContain('Batch and dates')
        ->toContain("expiryRequired && entry.batch_number.trim() === ''")
        // F18: made and expiry dates carry the year.
        ->toContain('expires {formatDate(line.expiry_date)}');

    // F9: the action buttons may stack, and the table asks for less width.
    $products = practiceUiSource('pages/inventory/products/index.tsx');
    expect($products)->toContain('inline-flex flex-wrap justify-end gap-1')
        ->not->toContain('minWidth="64rem"');

    // F30: the price explanation line is held open while the price is looked up.
    expect(practiceUiSource('pages/sales/orders/index.tsx'))->toContain("line.product_id !== '' && (");
});
