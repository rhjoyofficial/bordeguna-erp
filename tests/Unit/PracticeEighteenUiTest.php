<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The owner's practice run, 18 Oct 2026: the UI rules RUN through
 * tests/js/practice-18.harness.ts (esbuild bundles the real resources/js
 * modules, node runs them), plus source checks for wiring a harness cannot
 * reach.
 */
/** @param list<array<string, mixed>> $calls */
function practiceEighteenUi(array $calls): array
{
    $root = dirname(__DIR__, 2);
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the practice UI harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/practice-18.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function practiceEighteenSource(string $path): string
{
    return file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$path);
}

it('gives a fresh history entry the first page before Inertia starts, and leaves a real state alone (P8)', function () {
    [$fresh, $kept, $noPage] = practiceEighteenUi([
        ['op' => 'seed', 'state' => null, 'page' => ['component' => 'inventory/production-runs/create']],
        ['op' => 'seed', 'state' => ['page' => ['component' => 'x']], 'page' => ['component' => 'y']],
        ['op' => 'seed', 'state' => null, 'page' => null],
    ]);

    expect($fresh)->toBe(['seeded' => true, 'writes' => [['page' => ['component' => 'inventory/production-runs/create']]]])
        ->and($kept)->toBe(['seeded' => false, 'writes' => []])
        ->and($noPage)->toBe(['seeded' => false, 'writes' => []]);

    expect(practiceEighteenSource('app.tsx'))->toContain("installHistoryStateGuard();\n\ncreateInertiaApp(");
});

it('puts a row whose label is exactly the typed text on top, and otherwise keeps every match equal (P4)', function () {
    expect(practiceEighteenUi([
        ['op' => 'score', 'row' => 'Bulk Salts 12', 'label' => 'Bulk Salts', 'search' => 'Salts'],
        ['op' => 'score', 'row' => 'Salts 14', 'label' => 'Salts', 'search' => 'Salts'],
        ['op' => 'score', 'row' => 'Salts 14', 'label' => 'Salts', 'search' => '  salts '],
        ['op' => 'score', 'row' => 'Packaging Jars 3', 'label' => 'Packaging Jars', 'search' => 'salt'],
        ['op' => 'score', 'row' => 'Salts 14', 'label' => 'Salts', 'search' => 'sal'],
    ]))->toBe([1, 2, 2, 0, 1]);
});

it('keeps only digits and one dot in a number box, Bangla digits as Latin (P11)', function () {
    expect(practiceEighteenUi([
        ['op' => 'decimal', 'value' => 'a20'],
        ['op' => 'decimal', 'value' => '2.5.1'],
        ['op' => 'decimal', 'value' => '1,000'],
        ['op' => 'decimal', 'value' => "\u{09E8}\u{09E6}"],
        ['op' => 'decimal', 'value' => ''],
    ]))->toBe(['20', '2.51', '1000', '20', '']);

    $form = practiceEighteenSource('pages/inventory/production-runs/create.tsx');
    expect(substr_count($form, 'inputMode="decimal"'))->toBe(4)
        ->and(substr_count($form, 'decimalInput(e.target.value)'))->toBe(4);
});

it('fills the recipe lines whenever the output or its quantity changes until a line is edited by hand (P9)', function () {
    expect(practiceEighteenUi([
        ['op' => 'recipe', 'hasRecipe' => true, 'quantity' => '', 'touched' => false],
        ['op' => 'recipe', 'hasRecipe' => true, 'quantity' => '20', 'touched' => false],
        ['op' => 'recipe', 'hasRecipe' => true, 'quantity' => '20', 'touched' => true],
        ['op' => 'recipe', 'hasRecipe' => false, 'quantity' => '20', 'touched' => false],
        ['op' => 'recipe', 'hasRecipe' => true, 'quantity' => '0', 'touched' => false],
    ]))->toBe(['products-only', 'fill', 'leave', 'leave', 'products-only']);

    $form = practiceEighteenSource('pages/inventory/production-runs/create.tsx');
    expect($form)->toContain('recipeStep({ hasRecipe: true, quantity: data.output_quantity, touched: linesTouched.current })')
        ->and($form)->not->toContain('lastSuggested');
});

it('offers the chosen brand and unbranded products, keeps a line chosen before, and warns on it', function () {
    $products = [['id' => 1, 'brand_id' => 10], ['id' => 2, 'brand_id' => 20], ['id' => 3, 'brand_id' => null]];

    expect(practiceEighteenUi([
        ['op' => 'brandFilter', 'products' => $products, 'brandId' => '20'],
        ['op' => 'brandFilter', 'products' => $products, 'brandId' => ''],
        ['op' => 'brandFilter', 'products' => $products, 'brandId' => '20', 'keep' => '1'],
        ['op' => 'mismatch', 'product' => $products[0], 'brandId' => '20'],
        ['op' => 'mismatch', 'product' => $products[1], 'brandId' => '20'],
        ['op' => 'mismatch', 'product' => $products[2], 'brandId' => '20'],
        ['op' => 'mismatch', 'product' => $products[0], 'brandId' => ''],
    ]))->toBe([[2, 3], [1, 2, 3], [1, 2, 3], 10, null, null, null]);

    $page = practiceEighteenSource('pages/sales/orders/index.tsx');
    expect($page)->toContain('productsForBrand(products, brandId, line.product_id)')
        ->and($page)->toContain("t('orders.other_brand'");
});

it('shows plain numbers in the invoice boxes and grouped money in its summary', function () {
    expect(practiceEighteenUi([
        ['op' => 'plain', 'value' => '3.0000'],
        ['op' => 'plain', 'value' => '801.0000'],
        ['op' => 'money', 'value' => 2503],
    ]))->toBe(['3', '801', '2,503.00']);

    $page = practiceEighteenSource('pages/sales/invoices/create.tsx');
    expect($page)->not->toContain('.toFixed(2)')
        ->and($page)->toContain('goods {formatMoney(subtotal)}')
        ->and($page)->toContain('plainNumber(line.quantity_uninvoiced)')
        ->and($page)->toContain('plainNumber(line.unit_price)');
});

it('shows a draft withdrawal line its reference total and difference, like a posted one (P20)', function () {
    $page = practiceEighteenSource('pages/accounting/owner-withdrawals/index.tsx');

    // The totals come from the server (exact money, OwnerPersonalWithdrawalRulesTest
    // checks the figures); the page shows them and never multiplies floats.
    expect($page)->toContain('formatMoney(estimate.reference)')
        ->and($page)->toContain('formatMoney(estimate.difference)')
        ->and($page)->not->toContain('draftReference(');
});
