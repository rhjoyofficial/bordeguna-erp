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
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Purchasing Co', 'slug' => 'purchasing-co']);
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
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);

    $allPermissions = [
        'purchasing.suppliers.manage',
        'purchasing.po.create',
        'purchasing.po.approve',
        'purchasing.receipt.record',
        'purchasing.payment.update',
        'purchasing.reports.view',
    ];

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    foreach ($allPermissions as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo($allPermissions);
    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()->givePermissionTo($allPermissions);
    Role::where('business_id', $this->business->id)->where('name', 'Staff')->first()->givePermissionTo([
        'purchasing.po.create', 'purchasing.receipt.record', 'purchasing.reports.view',
    ]);

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');
});

it('lets Staff create a purchase order that stays pending until a Manager approves it', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '5'],
            ],
        ])
        ->assertRedirect();

    $order = PurchaseOrder::sole();
    expect($order->approvalRequest->status->value)->toBe('pending');
    expect($order->status)->toBe(PurchaseOrderStatus::Open);
    expect($order->po_number)->toBe('PO-000001');

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.approve', $order))
        ->assertRedirect();

    expect($order->fresh()->approvalRequest->status->value)->toBe('approved');
});

it('auto-approves a purchase order created by a Manager', function () {
    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '5'],
            ],
        ])
        ->assertRedirect();

    $order = PurchaseOrder::sole();
    expect($order->approvalRequest->status->value)->toBe('approved');
});

it('leaves a purchase order rejected and stores the review note', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '5'],
            ],
        ]);

    $order = PurchaseOrder::sole();

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.reject', $order), ['review_note' => 'wrong supplier'])
        ->assertRedirect();

    expect($order->fresh()->approvalRequest->status->value)->toBe('rejected');
    expect($order->fresh()->approvalRequest->review_note)->toBe('wrong supplier');
});

it('rejects a purchase order create request from a user without purchasing.po.create', function () {
    $noPermissionUser = User::factory()->create();
    $noPermissionUser->businesses()->attach($this->business->id);

    $this->actingAs($noPermissionUser)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '5']],
        ])
        ->assertForbidden();

    expect(PurchaseOrder::count())->toBe(0);
});

it('cancels an untouched open purchase order', function () {
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '5']],
        createdBy: $this->manager,
    );

    app(CancelPurchaseOrder::class)->handle($order);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('refuses to cancel a purchase order that already has receipts against it', function () {
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '5']],
        createdBy: $this->manager,
    );
    $order->update(['status' => PurchaseOrderStatus::PartiallyReceived]);
    $order->lines->first()->update(['quantity_received' => '4']);

    app(CancelPurchaseOrder::class)->handle($order->fresh(['lines']));
})->throws(RuntimeException::class);

it('runs a purchase order through the seeded approval workflow', function () {
    // Seed the business's default workflows, then confirm a real PO now runs
    // through the configurable engine (workflow attached, single stage) and
    // still approves exactly as before — the "migrate without rewrites" proof.
    app(\App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder::class)->run();

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '5']],
        createdBy: $this->staff, // Staff can't approve → stays pending
    );

    $request = $order->approvalRequest;
    expect($request->workflow_id)->not->toBeNull();
    expect($request->workflow->key)->toBe('purchase_order');
    expect($request->current_stage)->toBe(1);
    expect($request->status->value)->toBe('pending');

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.approve', $order))
        ->assertRedirect();

    expect($order->fresh()->approvalRequest->status->value)->toBe('approved');
    expect($order->approvalRequest->decisions()->count())->toBe(1);
});
