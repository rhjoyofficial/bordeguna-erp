<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * C-INV-E1 and C-INV-E2: what an Inventory refusal looks like from the browser.
 *
 * ⚠⚠ Opening stock is the single most important write of the whole cutover, and
 * it carried BOTH failure shapes at once. Its period lock and its account
 * lookup were uncaught, so they were blank 500s (C-INV-E1); and three of its
 * ValidationExceptions were attached to keys the screen does not render, which
 * is worse than a 500 because the form simply appears to do nothing (C-INV-E2).
 *
 * ⚠ `lines.{i}.product` vs the rendered `lines.{i}.product_id`, and
 * `lines.{i}.unitCost` vs the rendered `lines.{i}.unit_cost`. One character of
 * naming between a refusal that explains itself and a button that does nothing.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Refusal Inventory', 'slug' => 'refusal-inventory']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Mirpur', 'code' => 'MDPUR', 'is_default' => true,
    ]);
    $this->other = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Adabor', 'code' => 'ADABOR',
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->service = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-DELIVERY',
        'name' => 'Delivery service',
        'type' => ProductType::Service,
    ]);

    $this->reason = Reason::create([
        'business_id' => $this->business->id,
        'name' => 'Stock count variance',
        'context' => 'inventory.stock_adjustment',
        'is_active' => true,
    ]);
    $this->transferReason = Reason::create([
        'business_id' => $this->business->id,
        'name' => 'Branch replenishment',
        'context' => 'inventory.stock_transfer',
        'is_active' => true,
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);

    Carbon::setTestNow('2026-09-05 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

// ▶▶ THE CUTOVER WRITE. 31 August, the one apply that matters.
it('shows the service-product refusal on the line the screen actually renders', function () {
    $response = ($this->asOwner)()->post(route('inventory.opening-stock.store'), [
        'warehouse_id' => $this->warehouse->id,
        'as_at' => '2026-08-31',
        'lines' => [
            ['product_id' => $this->service->id, 'quantity' => '5', 'unit_cost' => '100'],
        ],
    ]);

    // The screen renders `lines.0.product_id`. Attached to `lines.0.product`
    // the message existed and was never once displayed.
    $response->assertSessionHasErrors('lines.0.product_id');

    expect(OpeningStock::count())->toBe(0);
});

it('shows the negative-cost refusal on the line the screen actually renders', function () {
    $response = ($this->asOwner)()->post(route('inventory.opening-stock.store'), [
        'warehouse_id' => $this->warehouse->id,
        'as_at' => '2026-08-31',
        'lines' => [
            ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '-1'],
        ],
    ]);

    $response->assertSessionHasErrors('lines.0.unit_cost');

    expect(OpeningStock::count())->toBe(0);
});

it('tells the accountant the month is closed instead of failing blank on opening stock', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-08-31'), $this->owner);

    $response = ($this->asOwner)()->post(route('inventory.opening-stock.store'), [
        'warehouse_id' => $this->warehouse->id,
        'as_at' => '2026-08-31',
        'lines' => [
            ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '100'],
        ],
    ]);

    $response->assertSessionHasErrors('as_at');

    expect(OpeningStock::count())->toBe(0);
});

it('refuses a future-dated opening stock with a readable message', function () {
    $response = ($this->asOwner)()->post(route('inventory.opening-stock.store'), [
        'warehouse_id' => $this->warehouse->id,
        'as_at' => '2026-12-31',
        'lines' => [
            ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '100'],
        ],
    ]);

    $response->assertSessionHasErrors('as_at');

    expect(OpeningStock::count())->toBe(0);
});

it('tells the storekeeper the month is closed instead of failing blank on an adjustment', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-08-31'), $this->owner);

    $response = ($this->asOwner)()->post(route('inventory.stock.adjustments.store'), [
        'type' => 'stock_count_variance',
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'direction' => 'increase',
        'quantity' => '5',
        'unit_cost' => '100',
        'reason_id' => $this->reason->id,
        'effective_date' => '2026-08-15',
    ]);

    $response->assertSessionHasErrors('stock');

    expect(InventoryAdjustment::count())->toBe(0);
});

it('tells the storekeeper the stock is short instead of failing blank on a transfer', function () {
    $response = ($this->asOwner)()->post(route('inventory.stock.transfers.store'), [
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'target_warehouse_id' => $this->other->id,
        'quantity' => '999',
        'reason_id' => $this->transferReason->id,
    ]);

    $response->assertSessionHasErrors('stock');

    expect(StockTransfer::count())->toBe(0);
});

// ⚠ A bare button on the transfers screen, so it flashes.
it('flashes an error when a transfer is received twice', function () {
    app(RecordStockIn::class)->handle(
        $this->product, $this->warehouse, '10', '100', $this->owner, occurredAt: '2026-09-01 09:00:00'
    );

    $transfer = app(DispatchStockTransfer::class)->handle(
        product: $this->product,
        source: $this->warehouse,
        target: $this->other,
        quantity: '5',
        reason: $this->transferReason,
        performedBy: $this->owner,
    );

    ($this->asOwner)()->post(route('inventory.stock.transfers.receive', $transfer))->assertRedirect();

    $response = ($this->asOwner)()->post(route('inventory.stock.transfers.receive', $transfer->fresh()));

    $response->assertRedirect();
    expect(session('error'))->toContain('already been received');
});

// ⚠ The control. The answers do not change, only whether they can be read.
it('still records a good opening stock exactly as before', function () {
    ($this->asOwner)()->post(route('inventory.opening-stock.store'), [
        'warehouse_id' => $this->warehouse->id,
        'as_at' => '2026-08-31',
        'lines' => [
            ['product_id' => $this->product->id, 'quantity' => '12', 'unit_cost' => '480'],
        ],
    ])->assertRedirect();

    expect(OpeningStock::count())->toBe(1)
        ->and((float) $this->product->fresh()->currentStockQuantity())->toBe(12.0);
});
