<?php

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * ⭐ 18 Oct 2026 (Joy, D-E; practice P16): the product form of a market
 * product shows no MRP box, no markup rules and no group price table, only
 * the "set as today's price" note; no screen suggests a price. RUN through
 * tests/js/market-pricing.harness.ts, plus source checks for the wiring.
 */
function marketUiRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @param list<array<string, mixed>> $calls */
function marketUi(array $calls): array
{
    $root = marketUiRoot();
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the market pricing harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/market-pricing.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function marketUiSource(string $path): string
{
    return file_get_contents(marketUiRoot().'/resources/js/'.$path);
}

it('hides the MRP box, the rules and the group price table for a market product, and shows the note', function () {
    [$market, $fixed, $legacy] = marketUi([
        ['op' => 'sections', 'mode' => 'market'],
        ['op' => 'sections', 'mode' => 'fixed_mrp'],
        ['op' => 'sections', 'mode' => null],
    ]);

    expect($market)->toBe(['mrp' => false, 'rules' => false, 'groupPrices' => false, 'todaysPriceNote' => true])
        ->and($fixed)->toBe(['mrp' => true, 'rules' => false, 'groupPrices' => true, 'todaysPriceNote' => false])
        ->and($legacy)->toBe($fixed);

    // ⚠ The form must USE the rule, and no form may still carry the rule boxes.
    $form = marketUiSource('components/inventory/product-dialog.tsx');
    expect($form)->toContain('pricingSectionsFor(form.pricing_mode)')
        ->toContain('sections.mrp &&')
        ->toContain('groups !== null && sections.groupPrices')
        ->toContain("t('market.price_set_where')")
        ->not->toContain("t('market.rules')")
        ->and(marketUiSource('components/inventory/category-dialog.tsx'))->not->toContain('category-markup');
});

it('calls a price under the batch cost a markdown, and suggests nothing', function () {
    expect(marketUi([
        ['op' => 'standing', 'price' => '120', 'cost' => '123.45'],
        ['op' => 'standing', 'price' => '123.45', 'cost' => '123.45'],
        ['op' => 'standing', 'price' => '200', 'cost' => '123.45'],
        ['op' => 'standing', 'price' => '', 'cost' => '123.45'],
        ['op' => 'standing', 'price' => '150', 'cost' => null],
    ]))->toBe(['below_cost', 'ok', 'ok', 'blank', 'blank']);

    foreach (['components/sales/market-price-dialog.tsx', 'pages/purchasing/purchase-orders/show.tsx', 'pages/sales/prices/index.tsx'] as $path) {
        expect(marketUiSource($path))->not->toContain('suggested');
    }

    $dictionary = marketUiSource('lib/i18n/market-pricing.ts');
    expect($dictionary)->not->toContain("'market.suggested'")
        ->toContain("'market.markdown_warning'")
        ->toContain('Below the batch cost of Tk {cost}: a markdown. Reason required.');
});
