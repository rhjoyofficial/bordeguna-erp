<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Approvals Co', 'slug' => 'approvals-co']);
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
    $this->reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment', 'name' => 'Damaged']);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Acme Supplies']);

    $allPermissions = [
        'inventory.stock.request', 'inventory.stock.approve',
        'purchasing.po.create', 'purchasing.po.approve',
    ];

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Staff'] as $roleName) {
        Role::firstOrCreate(['name' => $roleName, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }
    foreach ($allPermissions as $permissionName) {
        Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Owner')->first()->givePermissionTo($allPermissions);
    Role::where('business_id', $this->business->id)->where('name', 'Staff')->first()->givePermissionTo([
        'inventory.stock.request', 'purchasing.po.create',
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
});

function submitPendingRequests(Tests\TestCase $test): void
{
    $test->actingAs($test->staff)
        ->withSession(['current_business_id' => $test->business->id])
        ->post(route('inventory.stock-requests.store'), [
            'type' => 'adjustment',
            'product_id' => $test->product->id,
            'warehouse_id' => $test->warehouse->id,
            'direction' => AdjustmentDirection::Increase->value,
            'quantity' => '5',
            'reason_id' => $test->reason->id,
            'unit_cost' => '10',
        ])
        ->assertRedirect();

    $test->actingAs($test->staff)
        ->withSession(['current_business_id' => $test->business->id])
        ->post(route('purchasing.purchase-orders.store'), [
            'supplier_id' => $test->supplier->id,
            'warehouse_id' => $test->warehouse->id,
            'lines' => [
                ['product_id' => $test->product->id, 'quantity' => '10', 'unit_cost' => '5'],
            ],
        ])
        ->assertRedirect();
}

it('lists pending stock requests and purchase orders together for an approver', function () {
    submitPendingRequests($this);

    $response = $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('approvals/index')
        ->where('counts.stock_requests', 1)
        ->where('counts.purchase_orders', 1)
        ->has('requests', 2)
    );
});

it('approves a stock request from the inbox using its approve_url', function () {
    submitPendingRequests($this);
    $stockRequest = StockRequest::sole();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.approve', $stockRequest))
        ->assertRedirect();

    expect($stockRequest->fresh()->approvalRequest->status->value)->toBe('approved');
});

it('rejects a purchase order from the inbox with a review note', function () {
    submitPendingRequests($this);
    $order = PurchaseOrder::sole();

    $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.reject', $order), ['review_note' => 'Budget exceeded'])
        ->assertRedirect();

    expect($order->fresh()->approvalRequest->status->value)->toBe('rejected');
    expect($order->fresh()->approvalRequest->review_note)->toBe('Budget exceeded');
});

it('blocks a user with neither approve permission from the inbox', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.index'))
        ->assertForbidden();
});

it('shares the pending count and visibility flag only for users who can review approvals', function () {
    submitPendingRequests($this);

    $ownerResponse = $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('dashboard'));
    $ownerResponse->assertInertia(fn ($page) => $page
        ->where('pendingApprovalsCount', 2)
        ->where('canReviewApprovals', true)
    );

    $staffResponse = $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('dashboard'));
    $staffResponse->assertInertia(fn ($page) => $page
        ->where('pendingApprovalsCount', 0)
        ->where('canReviewApprovals', false)
    );
});
