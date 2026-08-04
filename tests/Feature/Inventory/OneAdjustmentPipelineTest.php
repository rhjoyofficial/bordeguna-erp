<?php

/**
 * The two rules that make Joy's principle enforceable rather than advisory:
 *
 *   "Everything that changes inventory quantity or value must go through the
 *    same movement ledger and approval pipeline. I never want multiple code
 *    paths that can modify inventory independently."
 *
 * ⚠⚠ Both are STRUCTURAL tests, and deliberately so. Neither property can be
 * observed by exercising the app: a second entry point works perfectly until
 * somebody uses it, and a `match` on adjustment type behaves correctly right up
 * until a seventh type is added. Same mechanism as the `TenantScope` test in W1,
 * and for the same reason - a rule nobody can enforce is a comment.
 *
 * See INVENTORY-ADJUSTMENT-WORKFLOW-ARCHITECTURE.md §3 and §8.
 */

/**
 * @return array<int, string> file => contents, for every PHP file under Inventory and Purchasing
 */
function moduleSources(): array
{
    $sources = [];

    foreach (['Inventory', 'Purchasing'] as $module) {
        $base = app_path("Modules/{$module}");

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

        foreach ($files as $file) {
            // ⚠ Migrations are schema, not code paths. One of them CREATES the
            // adjustments table and therefore names it, which is not a caller.
            if ($file->isFile() && $file->getExtension() === 'php' && ! str_contains($file->getPathname(), 'migrations')) {
                $sources[str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }
    }

    return $sources;
}

it('has exactly one code path that turns an adjustment into stock movements', function () {
    // ⚠⚠ ApplyStockAdjustment was DELETED on 2026-08-04 (C-FLOW-4c). It had
    // already stopped being an entry point - nothing called it - but it still
    // held the stock primitives, and an orphaned action that can move stock
    // without posting is exactly how a bypass reappears: somebody finds it and
    // uses it.
    //
    // ℹ This test therefore finds nothing today. It is kept as a REGRESSION
    // guard: if the class is ever recreated and imported anywhere, this fails.
    // The broader rule now lives in StockEntryPointsTest.
    $allowed = [
        'Modules/Inventory/Actions/ApproveInventoryAdjustment.php',
    ];

    $offenders = [];

    foreach (moduleSources() as $path => $contents) {
        if (in_array(str_replace('\\', '/', $path), array_map(fn ($p) => str_replace('\\', '/', $p), $allowed), true)) {
            continue;
        }

        // ⚠ Look for the IMPORT, not for the name. The first version matched any
        // mention and flagged StockController for a comment explaining that it
        // no longer calls the engine - a structural test that fires on prose
        // teaches people to weaken it. A class cannot be used without being
        // imported or fully qualified, and both forms contain this string.
        if (str_contains($contents, 'Actions\ApplyStockAdjustment')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'These call the adjustment engine directly instead of going through the workflow: '.implode(', ', $offenders));
});

it('never branches on adjustment type inside the engine', function () {
    // ⚠⚠ Joy's requirement is that approval, audit and movement generation are
    // identical regardless of type. The only way that stays true as types are
    // added is to make the engine unable to ask.
    //
    // ℹ This project has already been bitten by the opposite: adding a case to a
    // shared enum broke every exhaustive `match` that consumed it. An engine
    // that never matches on type cannot break when a seventh arrives.
    $engine = [
        'Modules/Inventory/Actions/CreateInventoryAdjustment.php',
        'Modules/Inventory/Actions/ApproveInventoryAdjustment.php',
    ];

    $offenders = [];

    foreach ($engine as $relative) {
        $contents = file_get_contents(app_path($relative));

        // A `match` whose subject mentions the type, in any spelling.
        if (preg_match('/match\s*\(\s*\$\w*(->)?type/i', $contents)) {
            $offenders[] = $relative;
        }

        if (str_contains($contents, 'InventoryAdjustmentType::') && ! str_contains($contents, 'use App\Modules\Inventory\Enums\InventoryAdjustmentType;')) {
            $offenders[] = $relative.' (compares against a type case)';
        }
    }

    expect($offenders)->toBe([], 'The engine branches on adjustment type: '.implode(', ', $offenders));
});

it('routes every stock-changing action through the effective-date guard', function () {
    // Slices 1 and 2 put the future check and the period lock in one place. An
    // action that writes movements without passing through it is a path where a
    // closed period can still be written into.
    $writers = [
        'Modules/Inventory/Actions/RecordStockIn.php',
        'Modules/Inventory/Actions/RecordStockOut.php',
        'Modules/Inventory/Actions/ReverseStockReceipt.php',
        'Modules/Inventory/Actions/DispatchStockTransfer.php',
        'Modules/Inventory/Actions/ReceiveStockTransfer.php',
    ];

    $offenders = [];

    foreach ($writers as $relative) {
        if (! str_contains(file_get_contents(app_path($relative)), 'EffectiveDate::resolve')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'These write stock without resolving an effective date: '.implode(', ', $offenders));
});
