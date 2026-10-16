<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

beforeEach(function () {
    $this->business = Business::create(['name' => 'Approvals Co', 'slug' => 'approvals-co']);
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->targetWarehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Second', 'code' => 'SECOND']);
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

    // ⚠ Stock has to exist now. The old fixture raised an INCREASE adjustment,
    // which needs none; a transfer moves real units and the source warehouse
    // must actually hold them.
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '50',
        unitCost: '10',
        performedBy: User::factory()->create(),
    );

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

function submitPendingRequests(TestCase $test): void
{
    $test->actingAs($test->staff)
        ->withSession(['current_business_id' => $test->business->id])
        ->post(route('inventory.stock-requests.store'), [
            // ⚠ A transfer, because a stock request is only ever a transfer
            // since slice 3. What this test cares about - that a pending request
            // reaches the approver's inbox - is the same either way.
            'type' => 'transfer',
            'product_id' => $test->product->id,
            'warehouse_id' => $test->warehouse->id,
            'target_warehouse_id' => $test->targetWarehouse->id,
            'quantity' => '5',
            'reason_id' => $test->reason->id,
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
        ->has('counts', 2) // one group per type present
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

it('shows settled requests in the history and filters them by outcome', function () {
    submitPendingRequests($this);
    $stockRequest = StockRequest::sole();
    $order = PurchaseOrder::sole();

    // One approved, one rejected.
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.approve', $stockRequest))->assertRedirect();
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.reject', $order), ['review_note' => 'Budget exceeded'])->assertRedirect();

    // The pending inbox is now empty...
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.index'))
        ->assertInertia(fn ($page) => $page->component('approvals/index')->has('requests', 0));

    // ...and both appear in the history with their outcomes.
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('approvals/history')
            ->where('active', 'all')
            ->has('requests', 2));

    // Filtered to approved: only the stock request, marked approved with a reviewer.
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.history', ['status' => 'approved']))
        ->assertInertia(fn ($page) => $page
            ->where('active', 'approved')
            ->has('requests', 1)
            ->where('requests.0.status', 'approved')
            ->where('requests.0.type', 'Stock request')
            ->where('requests.0.reviewed_by', $this->owner->name)
            ->where('requests.0.approve_url', null));
});

it('lets the requester see their own settled requests, an unrelated user none', function () {
    submitPendingRequests($this);
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.approve', StockRequest::sole()))->assertRedirect();
    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.reject', PurchaseOrder::sole()), ['review_note' => 'No'])->assertRedirect();

    // Staff raised both, so staff sees both in history even without approve rights.
    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('requests', 2));

    // A user who neither raised nor may review them sees an empty (403-gated) page.
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    $this->actingAs($outsider)->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.history'))
        ->assertForbidden();
});

it('still lists a settled request whose record has since been deleted, instead of a 500', function () {
    submitPendingRequests($this);
    $stockRequest = StockRequest::sole();

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.approve', $stockRequest))->assertRedirect();

    // The record vanishes; its settled approval request stays behind.
    DB::table('stock_requests')->where('id', $stockRequest->id)->delete();

    $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('approvals/history')->has('requests', 1)->where('requests.0.approve_url', null));
});

// Round 3 (16 Oct 2026): History is a tab beside Approvals with the SAME gate
// (canReviewApprovals). Somebody who reaches it only through a request waiting
// on them, and has decided nothing yet, gets the empty page, never a 403.
it('opens the empty history for a reviewer whose only way in is a request waiting on them', function () {
    $reviewer = User::factory()->create();
    $reviewer->businesses()->attach($this->business->id);

    // Neither a legacy approver nor anyone with a decided request: only "something is waiting on you".
    app()->instance(\App\Support\ApprovalInbox::class, new class extends \App\Support\ApprovalInbox
    {
        public function countFor(User $user): int
        {
            return 1;
        }
    });

    $this->actingAs($reviewer)->withSession(['current_business_id' => $this->business->id])
        ->get(route('approvals.history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('approvals/history')->has('requests', 0));
});
