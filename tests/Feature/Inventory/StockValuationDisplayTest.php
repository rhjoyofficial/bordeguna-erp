<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Spatie\Permission\PermissionRegistrar;

/**
 * I7 - the same defect, fixed once and left standing one screen over.
 *
 * On 2026-07-29 the main dashboard displayed `Stock value 19200.00000000`,
 * because SUM() over decimal columns stringifies at full scale and the value
 * went straight to the page. It was fixed in DashboardController.
 *
 * `InventoryDashboardController` ran the identical query and passed the raw
 * value through untouched, so the bug was still shipping on the Inventory
 * dashboard months later.
 *
 * ⚠ The lesson is not the fix, it is that fixing an INSTANCE is not fixing a
 * PATTERN. Any aggregate over a decimal column that reaches a user needs
 * explicit formatting, and this is the fourth appearance in this project.
 *
 * See INVENTORY-READINESS-REVIEW.md §4 I7.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Value Co', 'slug' => 'value-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-VAL',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);

    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->user->businesses()->attach($this->business->id);
});

it('shows stock value as money rather than at full decimal scale', function () {
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '160',
        unitCost: '120',
        performedBy: $this->user,
    );

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('total_stock_value', '19,200.00'));
});

it('shows a zero rather than an empty screen when there is no stock at all', function () {
    // ⚠ The `?? '0'` fallback existed but was never formatted either, so an
    // empty warehouse rendered a bare "0" where every other figure carried
    // decimals. Small, but it is the same omission.
    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('total_stock_value', '0.00'));
});
