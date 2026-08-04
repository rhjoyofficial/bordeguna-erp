<?php

/**
 * ⚠⚠ EVERY STOCK MOVEMENT MUST COME FROM A BUSINESS DOCUMENT THAT KNOWS ITS OWN
 * ACCOUNTING CONSEQUENCE.
 *
 * `RecordStockIn` and `RecordStockOut` write stock movements and post NOTHING -
 * by design, because they are Inventory's shared primitive. The document that
 * calls them is what decides the journal. So a caller that is not one of the
 * five sanctioned documents is, by construction, stock moving with no ledger
 * entry behind it.
 *
 * The five, and what each posts:
 *   Goods receipt        Dr Stock on Hand / Cr Goods Received Not Invoiced
 *   Delivery             Dr Goods Delivered Not Invoiced / Cr Stock on Hand
 *   Inventory adjustment Dr the loss account / Cr Stock on Hand
 *   Stock transfer       ⚠ nothing, correctly - no value changes
 *   Opening stock        Dr Stock on Hand / Cr Opening Balance Equity
 *
 * ⚠ This test is what stops a sixth path appearing next quarter. Closing the
 * holes without it is a one-time cleanup rather than a rule.
 *
 * See INVENTORY-ENTRY-POINTS-DESIGN.md §4.
 */
$callersOf = function (string $action): array {
    $root = dirname(__DIR__, 3).'/app';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relative = ltrim(substr($path, strpos($path, '/app/') + 5), '/');

        // The primitives themselves are not callers of themselves.
        if (str_contains($relative, "Actions/{$action}.php")) {
            continue;
        }

        // ⚠ Comments stripped before scanning. A docblock explaining this rule
        // names the very thing it forbids, and a scan that reads prose reports
        // the documentation of a rule as a breach of it. Third application of
        // that lesson on this project.
        $code = '';

        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        if (str_contains($code, $action)) {
            $found[] = $relative;
        }
    }

    sort($found);

    return $found;
};

/**
 * ⚠⚠ THE KNOWN HOLES, LISTED SO THEY CANNOT BE FORGOTTEN. C-FLOW-4, found by
 * the Sales hand-check on 2026-08-04 and live in production today.
 *
 * This test is GREEN with them present on purpose: it pins the scope of the
 * problem exactly, so a NEW bypass fails the build immediately while the known
 * ones are worked through. ▶ Each entry is deleted from this list as its hole is
 * closed, and the list must reach empty.
 */
$knownHoles = [
    // C-FLOW-4a and 4b: the generic receipt and usage screens. To be DELETED,
    // not taught to post - stock leaving for any reason becomes an inventory
    // adjustment (C-FLOW-5, Joy 2026-08-04).
    'Modules/Inventory/Http/Controllers/StockController.php',
    // C-FLOW-4c: a second adjustment action that does not post, beside
    // CreateInventoryAdjustment -> ApproveInventoryAdjustment which does.
    'Modules/Inventory/Actions/ApplyStockAdjustment.php',
];

/** The documents allowed to move stock, each posting its own journal. */
$sanctioned = [
    // Purchasing.
    'Modules/Purchasing/Actions/RecordGoodsReceipt.php',
    'Modules/Purchasing/Actions/RecordPurchaseReturn.php',
    // ⚠ Reverses the specific receipts a supplier delivered; its journal is
    // posted by the purchase return that calls it.
    'Modules/Inventory/Actions/ReverseStockReceipt.php',
    // Sales.
    'Modules/Sales/Actions/RecordDelivery.php',
    // Inventory's own approved workflow.
    'Modules/Inventory/Actions/ApproveInventoryAdjustment.php',
    // ⚠ Demo data for demo businesses, not a business path. Explicitly allowed
    // so the rule stays about production behaviour rather than fixtures.
    'Modules/Inventory/database/seeders/InventoryDemoDataSeeder.php',
];

it('lets only sanctioned documents put stock in', function () use ($callersOf, $knownHoles, $sanctioned) {
    $allowed = array_merge($sanctioned, $knownHoles);
    $unexpected = array_diff($callersOf('RecordStockIn'), $allowed);

    expect($unexpected)->toBeEmpty(
        "A new path is putting stock into the warehouse without posting a journal:\n  "
        .implode("\n  ", $unexpected)
        ."\n\nEvery stock movement must come from a goods receipt, a delivery, an inventory "
        ."adjustment, a transfer or the opening stock document. See INVENTORY-ENTRY-POINTS-DESIGN.md."
    );
});

it('lets only sanctioned documents take stock out', function () use ($callersOf, $knownHoles, $sanctioned) {
    $allowed = array_merge($sanctioned, $knownHoles);
    $unexpected = array_diff($callersOf('RecordStockOut'), $allowed);

    expect($unexpected)->toBeEmpty(
        "A new path is taking stock out of the warehouse without posting a journal:\n  "
        .implode("\n  ", $unexpected)
        ."\n\n⚠⚠ This is the worse direction: value leaves the balance sheet with nothing on the "
        ."profit and loss, and the trial balance still balances."
    );
});

// ⚠ The list must shrink to nothing. If a hole is closed and its entry is left
// behind, this fails and says so - otherwise the allowlist silently grows
// permission for a path that no longer needs it.
it('has no stale entries in the known-holes list', function () use ($callersOf, $knownHoles) {
    $callers = array_merge($callersOf('RecordStockIn'), $callersOf('RecordStockOut'));

    // ⚠ Not `toContain($hole, $message)` - Pest reads a second argument as
    // ANOTHER value to look for, so the message itself became the assertion and
    // the test failed for a reason that had nothing to do with the rule.
    foreach ($knownHoles as $hole) {
        expect(in_array($hole, $callers, true))->toBeTrue(
            "{$hole} no longer calls the stock primitives, so it should be removed from the "
            .'known-holes list in this test. C-FLOW-4 is closed when that list is empty.'
        );
    }
});

// ▶ The finish line, stated as a test that is SUPPOSED to fail until the work is
// done. Skipped rather than failing so the suite stays honest about being green,
// and the skip message is the reminder.
it('has closed every known hole', function () use ($knownHoles) {
    expect($knownHoles)->toBeEmpty();
})->skip(fn () => true, 'C-FLOW-4 is still open: 2 paths move stock with no journal. See INVENTORY-ENTRY-POINTS-DESIGN.md.');
