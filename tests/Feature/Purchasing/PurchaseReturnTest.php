<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordPurchaseReturn;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Returns Co', 'slug' => 'returns-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-1',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);

    $allPermissions = ['purchasing.po.create', 'purchasing.po.approve', 'purchasing.receipt.record'];
    foreach ($allPermissions as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
    }
    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo($allPermissions);
    Role::where('business_id', $this->business->id)->where('name', 'Staff')->first()->givePermissionTo(['purchasing.po.create']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function receivedPurchaseOrder(Business $business, Supplier $supplier, Warehouse $warehouse, User $createdBy, Product $product, string $quantity, string $unitCost): PurchaseOrder
{
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $supplier,
        warehouse: $warehouse,
        lines: [['product' => $product, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        createdBy: $createdBy,
    );
    $order->approvalRequest->approve($createdBy);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => $quantity]], $createdBy);

    return $order->fresh(['lines', 'approvalRequest']);
}

it('reverses stock and tracks quantity_returned when returning part of what was received', function () {
    $order = receivedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, $this->product, '20', '15');
    $line = $order->lines->first();
    expect($this->product->fresh()->currentStockQuantity())->toBe('20');

    $return = app(RecordPurchaseReturn::class)->handle($order, [['line' => $line, 'quantity' => '8']], $this->manager, 'Damaged in shipment');

    expect($return->lines)->toHaveCount(1);
    expect((string) $return->lines->first()->quantity_returned)->toBe('8.0000');
    expect((string) $line->fresh()->quantity_returned)->toBe('8.0000');
    expect($this->product->fresh()->currentStockQuantity())->toBe('12');
});

it('refuses to return more than the returnable quantity on a line', function () {
    $order = receivedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, $this->product, '10', '15');
    $line = $order->lines->first();

    app(RecordPurchaseReturn::class)->handle($order, [['line' => $line, 'quantity' => '11']], $this->manager);
})->throws(InvalidArgumentException::class);

it('refuses to return anything against a cancelled purchase order', function () {
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '15']],
        createdBy: $this->manager,
    );
    $order->approvalRequest->approve($this->manager);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $order->update(['status' => PurchaseOrderStatus::Cancelled]);

    app(RecordPurchaseReturn::class)->handle($order, [['line' => $order->lines->first(), 'quantity' => '1']], $this->manager);
})->throws(RuntimeException::class);

it('blocks cancelling a purchase order that still has received stock outstanding', function () {
    $order = receivedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, $this->product, '10', '15');

    app(CancelPurchaseOrder::class)->handle($order);
})->throws(RuntimeException::class);

it('allows cancelling a purchase order once everything received has been returned', function () {
    $order = receivedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, $this->product, '10', '15');
    $line = $order->lines->first();

    app(RecordPurchaseReturn::class)->handle($order, [['line' => $line, 'quantity' => '10']], $this->manager);

    $cancelled = app(CancelPurchaseOrder::class)->handle($order->fresh(['lines', 'approvalRequest']));

    expect($cancelled->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('lets an approver record a return through the real HTTP route', function () {
    $order = receivedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, $this->product, '10', '15');
    $line = $order->lines->first();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.returns.store', $order), [
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '4']],
            'note' => 'Wrong item',
        ])
        ->assertRedirect();

    expect((string) $line->fresh()->quantity_returned)->toBe('4.0000');
});

it('blocks a Staff user without purchasing.po.approve from recording a return', function () {
    $order = receivedPurchaseOrder($this->business, $this->supplier, $this->warehouse, $this->manager, $this->product, '10', '15');
    $line = $order->lines->first();

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.returns.store', $order), [
            'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => '4']],
        ])
        ->assertForbidden();
});
