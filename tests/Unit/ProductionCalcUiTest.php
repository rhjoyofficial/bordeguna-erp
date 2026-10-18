<?php

use App\Modules\Inventory\Support\BomSuggestion;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * PR3 (18 Oct 2026): the run form's two-way fill and live cost, RUN through
 * tests/js/production-calc.harness.ts (esbuild bundles the real
 * lib/recipe-fill.ts, node runs it), and held against the server's own
 * BomSuggestion so the screen never fills what the server would standardise
 * differently.
 */
/** @param list<array<string, mixed>> $calls */
function productionCalcUi(array $calls): array
{
    $root = dirname(__DIR__, 2);
    $node = (new ExecutableFinder)->find('node');

    if ($node === null || ! is_dir($root.'/node_modules/esbuild')) {
        test()->markTestSkipped('node and node_modules (esbuild) are needed to run the production UI harness.');
    }

    $process = new Process([$node, 'tests/js/run-ts.mjs', 'tests/js/production-calc.harness.ts'], $root, null, json_encode($calls), 120);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

$bulk = ['product_id' => 1, 'quantity_per_output' => '0.4', 'wastage_percent' => '2'];
$jar = ['product_id' => 2, 'quantity_per_output' => '1', 'wastage_percent' => '0'];

it('fills components from the output as the server would standardise them, whole jars rounded up', function () use ($bulk, $jar) {
    [$bulk49, $jar49, $jarCarton] = productionCalcUi([
        ['op' => 'suggest', 'line' => $bulk, 'recipeOutput' => '1', 'output' => 49, 'whole' => false],
        ['op' => 'suggest', 'line' => $jar, 'recipeOutput' => '1', 'output' => 49, 'whole' => true],
        // One jar per 4 packs: 10 packs need 2.5, so 3 whole jars.
        ['op' => 'suggest', 'line' => $jar, 'recipeOutput' => '4', 'output' => 10, 'whole' => true],
    ]);

    expect((float) $bulk49)->toBe((float) BomSuggestion::forOutput('0.4', '49', '1', '2'))
        ->and($jar49)->toBe('49')
        ->and((float) $jarCarton)->toBe((float) BomSuggestion::forOutput('1', '10', '4', '0', wholeUnits: true));
});

it('works out the output one component supports, rounded down, whole packs when counted', function () use ($bulk, $jar) {
    expect(productionCalcUi([
        // 10 kg of bulk at 0.408 kg a pack: 24.5098 packs, 24 whole.
        ['op' => 'output', 'line' => $bulk, 'recipeOutput' => '1', 'component' => 10, 'whole' => true],
        ['op' => 'output', 'line' => $bulk, 'recipeOutput' => '1', 'component' => 10, 'whole' => false],
        ['op' => 'output', 'line' => $jar, 'recipeOutput' => '1', 'component' => 25, 'whole' => true],
        ['op' => 'output', 'line' => $bulk, 'recipeOutput' => '1', 'component' => 0, 'whole' => true],
    ]))->toBe(['24', '24.5098', '25', '']);
});

it('converts a line typed in grams to the kg stock unit and back', function () {
    expect(productionCalcUi([
        ['op' => 'toStock', 'quantity' => '4080', 'factor' => '0.00100000'],
        ['op' => 'fromStock', 'quantity' => 4.08, 'factor' => '0.00100000'],
        ['op' => 'fromStock', 'quantity' => 0, 'factor' => '1'],
    ]))->toBe([4.08, '4080', '']);
});

it('prices materials from the batches FIFO would take, and says when the shelf is short', function () {
    $layers = [['quantity' => '3.0000', 'unit_cost' => '300.0000'], ['quantity' => '5.0000', 'unit_cost' => '320.0000']];

    expect(productionCalcUi([
        ['op' => 'fifo', 'layers' => $layers, 'quantity' => 4.08],
        ['op' => 'fifo', 'layers' => $layers, 'quantity' => 10],
        ['op' => 'fifo', 'layers' => [], 'quantity' => 1],
    ]))->toBe([
        // 3 x 300 + 1.08 x 320 = 1,245.60.
        ['paisa' => 124560, 'short' => false],
        // 3 x 300 + 5 x 320 + 2 more at the last cost (320) = 3,140.00.
        ['paisa' => 314000, 'short' => true],
        ['paisa' => 0, 'short' => true],
    ]);
});
