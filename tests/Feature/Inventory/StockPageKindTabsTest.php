<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\MaterialKind;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * 31 Oct 2026: the stock page lists finished goods, raw materials and packaging
 * apart (tabs with counts, one heading per kind when everything is shown), so a
 * jar or a label is never read as something we sell.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Kinds Co', 'slug' => 'kinds-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    Permission::findOrCreate('inventory.reports.view', 'web');

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->givePermissionTo('inventory.reports.view');

    // Created packaging first, so the order on the page is the kind order, not creation order.
    foreach ([
        ['JAR', 'Glass Jar 200g', ProductType::RawMaterial, MaterialKind::Packaging],
        ['OIL', 'Raw Mustard Oil', ProductType::RawMaterial, MaterialKind::Raw],
        ['PICKLE', 'Hilsa Pickle 200g', ProductType::FinishedGood, null],
    ] as [$sku, $name, $type, $kind]) {
        $product = Product::create([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
            'sku' => $sku, 'name' => $name, 'type' => $type, 'material_kind' => $kind,
        ]);
        app(RecordStockIn::class)->handle(product: $product, warehouse: $warehouse, quantity: '5', unitCost: '10', performedBy: $this->user);
    }

    $this->page = fn (string $query = '') => $this->actingAs($this->user)->withHeader('X-Business-Id', (string) $this->business->id)->get('/inventory/stock'.$query);
});

it('lists finished goods, then raw materials, then packaging, and counts each kind', function () {
    ($this->page)()->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('stock.data.0.product.sku', 'PICKLE')
        ->where('stock.data.1.product.sku', 'OIL')
        ->where('stock.data.2.product.sku', 'JAR')
        ->where('kind_counts', ['finished' => 1, 'raw' => 1, 'packaging' => 1]));
});

it('shows one kind on its tab', function (string $kind, string $sku) {
    ($this->page)('?kind='.$kind)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('stock.data', 1)
        ->where('stock.data.0.product.sku', $sku)
        ->where('filters.kind', $kind));
})->with([['finished', 'PICKLE'], ['raw', 'OIL'], ['packaging', 'JAR']]);

it('ignores a kind it does not know', function () {
    ($this->page)('?kind=nonsense')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('stock.data', 3)->where('filters.kind', ''));
});
