<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Exercises the approval workflow end-to-end through real HTTP requests
 * (not direct Action calls) so the permission gates on the routes are
 * actually verified, not just the underlying Action logic.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Interview Co', 'slug' => 'interview-co']);
    app(BusinessContext::class)->set($this->business->id);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-1',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    foreach (['inventory.catalog.manage', 'inventory.stock.record', 'inventory.stock.adjust', 'inventory.stock.request', 'inventory.stock.approve', 'inventory.reports.view'] as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Owner')
        ->first()->syncPermissions(['inventory.catalog.manage', 'inventory.stock.record', 'inventory.stock.adjust', 'inventory.stock.request', 'inventory.stock.approve', 'inventory.reports.view']);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')
        ->first()->syncPermissions(['inventory.catalog.manage', 'inventory.stock.record', 'inventory.stock.adjust', 'inventory.stock.request', 'inventory.stock.approve', 'inventory.reports.view']);
    Role::where('business_id', $this->business->id)->where('name', 'Staff')
        ->first()->syncPermissions(['inventory.stock.record', 'inventory.stock.request', 'inventory.reports.view']);

    $this->staff = makeUserWithRole($this->business, 'Staff');
    $this->manager = makeUserWithRole($this->business, 'Manager');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '10', $this->manager);
});

function makeUserWithRole(Business $business, string $roleName): User
{
    $user = User::factory()->create();
    $user->businesses()->attach($business->id);
    $user->assignRole($roleName);

    return $user;
}

it('lets a Staff member submit a stock request but not apply it directly', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => '10',
            'note' => 'trying to bypass the queue',
        ])
        ->assertForbidden();

    expect($this->product->fresh()->currentStockQuantity())->toBe('100');
});

it('lets Staff submit an adjustment request that stays pending until Manager approves it', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.store'), [
            'type' => StockRequestType::Adjustment->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => AdjustmentDirection::Decrease->value,
            'quantity' => '20',
            'reason_note' => 'found damaged units during count',
        ])
        ->assertRedirect();

    $request = StockRequest::sole();
    expect($request->status->value)->toBe('pending');
    // Stock must not move until a Manager approves the request.
    expect($this->product->fresh()->currentStockQuantity())->toBe('100');

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.approve', $request))
        ->assertRedirect();

    expect($request->fresh()->status->value)->toBe('approved');
    expect($this->product->fresh()->currentStockQuantity())->toBe('80');
});

it('leaves stock untouched when a Manager rejects a request', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.store'), [
            'type' => StockRequestType::Adjustment->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => AdjustmentDirection::Decrease->value,
            'quantity' => '20',
            'reason_note' => 'found damaged units during count',
        ]);

    $request = StockRequest::sole();

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.reject', $request), ['review_note' => 'recount first'])
        ->assertRedirect();

    expect($request->fresh()->status->value)->toBe('rejected');
    expect($this->product->fresh()->currentStockQuantity())->toBe('100');
});

it('lets a Manager apply an adjustment directly without a request', function () {
    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => '10',
            'note' => 'manager correction',
        ])
        ->assertRedirect();

    expect($this->product->fresh()->currentStockQuantity())->toBe('90');
    expect(StockRequest::count())->toBe(0);
});
