<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * The rebuilt inventory dashboard (INV-1, the DreamsERP anatomy). What these
 * pin: value is the axis everywhere quantities would be unit soup, empty
 * panels stay empty rather than inventing numbers, and the low-stock rule is
 * the same one the low-stocks screen reads.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-24 10:00:00');

    $this->business = Business::create(['name' => 'Dash Inv Co', 'slug' => 'dash-inv-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->second = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SVR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->dryFish = Category::create(['business_id' => $this->business->id, 'name' => 'Dry Fish', 'slug' => 'dry-fish']);

    $this->categorised = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'category_id' => $this->dryFish->id,
        'sku' => 'SKU-CAT', 'name' => 'Churi 125g', 'type' => ProductType::FinishedGood,
    ]);
    $this->uncategorised = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-UNC', 'name' => 'Bulk Salt', 'type' => ProductType::RawMaterial,
    ]);

    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->user->businesses()->attach($this->business->id);

    $this->visit = fn () => $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('inventory.dashboard'));
});

afterEach(fn () => Carbon::setTestNow());

it('distributes stock value by category, bucketing the uncategorised honestly', function () {
    app(RecordStockIn::class)->handle($this->categorised, $this->main, '10', '100', $this->user);
    app(RecordStockIn::class)->handle($this->uncategorised, $this->second, '4', '50', $this->user);

    ($this->visit)()
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('inventory/dashboard')
            ->where('total_stock_value', '1,200.00')
            ->where('in_stock_products', 2)
            ->has('categories', 2)
            ->where('categories.0.name', 'Dry Fish')
            ->where('categories.0.value', 1000)
            ->where('categories.1.name', 'Uncategorised')
            ->where('categories.1.value', 200)
            // Warehouse shares: value split 1000/200, panel ordered by name.
            ->has('warehouses', 2)
            ->where('warehouses.0.name', 'Main')
            ->where('warehouses.0.share', 83)
            ->where('warehouses.0.products', 1)
            ->where('warehouses.1.share', 17));
});

it('draws the trend with the current month live and older months at zero', function () {
    app(RecordStockIn::class)->handle($this->categorised, $this->main, '10', '100', $this->user);

    ($this->visit)()
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->has('trend', 6)
            ->where('trend.5.label', 'Aug')
            ->where('trend.5.in', 1000)
            ->where('trend.5.out', 0)
            // An empty month is a ZERO bar, not a missing bar.
            ->where('trend.0.in', 0)
            ->where('trend.0.out', 0)
            ->has('recent_movements', 1)
            ->where('recent_movements.0.direction', 'in'));
});

it('states its empty panels honestly when nothing has ever happened', function () {
    ($this->visit)()
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('total_stock_value', '0.00')
            ->where('in_stock_products', 0)
            ->has('categories', 0)
            ->has('suppliers', 0)
            ->has('recent_movements', 0)
            ->where('expiry.expired', 0)
            ->where('expiry.expiring', 0));
});

it('counts expired and expiring batches for the strip', function () {
    foreach ([['2026-08-20', 'B-EXPIRED'], ['2026-09-10', 'B-SOON']] as [$expiry, $number]) {
        StockBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $this->categorised->id,
            'warehouse_id' => $this->main->id,
            'batch_number' => $number, 'expiry_date' => $expiry,
            'unit_cost' => '100', 'conversion_factor' => '1',
            'initial_quantity' => '5', 'current_quantity' => '5',
            'status' => 'active', 'received_at' => now(),
        ]);
    }

    ($this->visit)()
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('expiry.expired', 1)
            ->where('expiry.expiring', 1));
});

it('lists a product at its threshold as low, agreeing with the low-stocks screen', function () {
    $this->categorised->update(['low_stock_threshold' => '10']);
    app(RecordStockIn::class)->handle($this->categorised, $this->main, '10', '100', $this->user);

    ($this->visit)()
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('low_stock_count', 1)
            ->where('low_stock_products.0.sku', 'SKU-CAT'));
});
