<?php

use App\Modules\Inventory\database\seeders\InventoryDemoDataSeeder;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use Database\Seeders\DatabaseSeeder;

/**
 * ⛔⛔ Demo data must never reach production.
 *
 * deploy/README.md tells an operator to run `db:seed --force` on the live box to
 * pick up newly added permissions, and DatabaseSeeder calls the demo seeder. The
 * seeder invents a catalogue and then records real STOCK-IN MOVEMENTS for it, and
 * it would succeed rather than fail safe, because the businesses it looks up all
 * exist on production.
 *
 * ⚠ Production's stock ledger is deliberately empty while the real opening count
 * is prepared, and the first movement it ever takes has to be a real one.
 */
it('refuses to put demo data into production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(app()->isProduction())->toBeTrue();

    // ⚠ Called directly rather than through $this->seed(), which would stop at
    // Laravel's own "application in production" confirmation. That prompt is a
    // second safety net and not the one under test here: an operator running
    // `db:seed --force` answers it by definition, which is exactly the path this
    // guard exists to survive.
    app()->call([app(InventoryDemoDataSeeder::class), 'run']);

    expect(Product::withoutGlobalScopes()->count())->toBe(0)
        ->and(StockMovement::withoutGlobalScopes()->count())->toBe(0);
});

it('still seeds demo data outside production, because that is what it is for', function () {
    // ⚠ The guard has to be narrow. A developer running db:seed locally, and
    // every test that leans on the demo catalogue, must be completely unaffected
    // - a guard that switched the seeder off everywhere would be a different
    // kind of defect.
    expect(app()->isProduction())->toBeFalse();

    $this->seed(DatabaseSeeder::class);

    expect(Product::withoutGlobalScopes()->where('sku', 'LLD-RICE-01')->exists())->toBeTrue()
        ->and(StockMovement::withoutGlobalScopes()->count())->toBeGreaterThan(0);
});
