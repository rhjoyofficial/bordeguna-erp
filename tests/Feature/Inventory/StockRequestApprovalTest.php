<?php

use App\Models\User;
use App\Modules\Foundation\Models\ApprovalWorkflow;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ApproveStockRequest;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventorySetting;
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
    $this->reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Damaged',
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

it('lets a Staff member raise a correction but never post one', function () {
    // ⚠ THE BOUNDARY MOVED, deliberately. It used to be "Staff may not touch the
    // adjustment endpoint at all", because that endpoint posted stock
    // immediately. Now the endpoint RAISES a correction and approval is what
    // posts, so refusing Staff outright would have quietly removed something
    // they could always do - submit a request for review.
    //
    // The boundary that actually matters is unchanged and is asserted here:
    // nothing Staff do moves stock on their own say-so.
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'type' => InventoryAdjustmentType::Damaged->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => '10',
            'reason_id' => $this->reason->id,
        ])
        ->assertRedirect();

    $adjustment = InventoryAdjustment::sole();

    expect($adjustment->approvalRequest->isPending())->toBeTrue()
        ->and($this->product->fresh()->currentStockQuantity())->toBe('100');

    // And they cannot approve their own.
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.approve', $adjustment))
        ->assertForbidden();

    expect($this->product->fresh()->currentStockQuantity())->toBe('100');
});

/**
 * ⚠ The five adjustment cases that used to live here moved to the ONE inventory
 * adjustment workflow in slice 3. They are rewritten below against the new
 * pipeline rather than deleted, because two of them cover things the engine
 * tests do not: the Staff-raises / Manager-approves permission boundary, and
 * the rejection path.
 *
 * The old "a Manager applies an adjustment directly without a request" is gone
 * on purpose - that WAS the second code path, and it left no record that a
 * decision had been made. Its replacement is the auto-approval case below.
 */
it('lets Staff raise a correction that stays pending until the approver posts it', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'type' => InventoryAdjustmentType::Damaged->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => AdjustmentDirection::Decrease->value,
            'quantity' => '20',
            'reason_id' => $this->reason->id,
        ])
        ->assertRedirect();

    $adjustment = InventoryAdjustment::sole();
    expect($adjustment->approvalRequest->status->value)->toBe('pending');
    // Nothing moves until somebody approves it.
    expect($this->product->fresh()->currentStockQuantity())->toBe('100');

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.approve', $adjustment))
        ->assertRedirect();

    expect($adjustment->fresh()->approvalRequest->status->value)->toBe('approved');
    expect($this->product->fresh()->currentStockQuantity())->toBe('80');
});

it('lands an approved increase as a new batch at the cost given', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'type' => InventoryAdjustmentType::StockCountVariance->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => AdjustmentDirection::Increase->value,
            'quantity' => '15',
            'reason_id' => $this->reason->id,
            'unit_cost' => '12.50',
        ])
        ->assertRedirect();

    $adjustment = InventoryAdjustment::sole();
    expect((string) $adjustment->unit_cost)->toBe('12.5000');

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.approve', $adjustment))
        ->assertRedirect();

    expect($this->product->fresh()->currentStockQuantity())->toBe('115');
});

it('refuses an increase with no unit cost', function () {
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'type' => InventoryAdjustmentType::StockCountVariance->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => AdjustmentDirection::Increase->value,
            'quantity' => '15',
            'reason_id' => $this->reason->id,
        ])
        ->assertInvalid(['unit_cost']);

    expect(InventoryAdjustment::count())->toBe(0);
});

it('leaves stock untouched when the approver rejects a correction', function () {
    // ⚠ Kept from the old suite because the engine tests do not cover rejection.
    // A rejected adjustment posts nothing, and there is no undo to write: the
    // movements were never created, since approval is what posts.
    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'type' => InventoryAdjustmentType::Damaged->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => AdjustmentDirection::Decrease->value,
            'quantity' => '20',
            'reason_id' => $this->reason->id,
        ]);

    $adjustment = InventoryAdjustment::sole();

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.reject', $adjustment), ['review_note' => 'recount first'])
        ->assertRedirect();

    expect($adjustment->fresh()->approvalRequest->status->value)->toBe('rejected')
        ->and($this->product->fresh()->currentStockQuantity())->toBe('100')
        ->and($adjustment->movements()->count())->toBe(0);
});

it('posts immediately when the value is under the configured threshold', function () {
    // The replacement for "a Manager applies an adjustment directly". It still
    // posts in one step for somebody who may adjust stock - but it now leaves a
    // record, an approval and an audit trail behind it.
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '100000']);

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.adjustments.store'), [
            'type' => InventoryAdjustmentType::ManualCorrection->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => '10',
            'reason_id' => $this->reason->id,
        ])
        ->assertRedirect();

    expect($this->product->fresh()->currentStockQuantity())->toBe('90');

    $adjustment = InventoryAdjustment::sole();
    expect($adjustment->approvalRequest->isComplete())->toBeTrue()
        ->and($adjustment->movements()->count())->toBeGreaterThan(0);
});

it('lets a Manager dispatch and receive a transfer request end to end', function () {
    $target = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Second', 'code' => 'SECOND']);
    $transferReason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_transfer',
        'name' => 'Rebalancing',
    ]);

    $this->actingAs($this->staff)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.store'), [
            'type' => StockRequestType::Transfer->value,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'target_warehouse_id' => $target->id,
            'quantity' => '20',
            'reason_id' => $transferReason->id,
        ])
        ->assertRedirect();

    $request = StockRequest::sole();

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.approve', $request))
        ->assertRedirect();

    $request->refresh();
    expect($request->approvalRequest->status->value)->toBe('approved');
    expect($request->resultingTransfer->status->value)->toBe('in_transit');
    // Approval only dispatches the transfer — destination stock doesn't move yet.
    expect($this->product->fresh()->currentStockQuantity())->toBe('80');

    $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock.transfers.receive', $request->resultingTransfer))
        ->assertRedirect();

    expect($request->resultingTransfer->fresh()->status->value)->toBe('received');
});

// ⚠ Bug hunt 16 Oct 2026 (02 L7): the transfer was dispatched before the
// decision, so the first of two stages already moved the goods.
it('dispatches a transfer request only when the last approval stage approves', function () {
    $workflow = ApprovalWorkflow::create([
        'business_id' => $this->business->id, 'key' => (new StockRequest)->approvalWorkflowKey(), 'name' => 'Two looks',
    ]);
    foreach ([1, 2] as $sequence) {
        $workflow->stages()->create(['sequence' => $sequence, 'name' => "Look {$sequence}", 'allowed_permissions' => ['inventory.stock.approve']]);
    }
    $target = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Second', 'code' => 'SECOND']);
    $transferReason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Rebalancing']);

    $this->actingAs($this->staff)->withSession(['current_business_id' => $this->business->id])
        ->post(route('inventory.stock-requests.store'), [
            'type' => StockRequestType::Transfer->value, 'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'target_warehouse_id' => $target->id, 'quantity' => '20', 'reason_id' => $transferReason->id,
        ])->assertRedirect();
    $request = StockRequest::sole();

    app(ApproveStockRequest::class)->handle($request, $this->manager);

    expect($request->fresh()->resulting_transfer_id)->toBeNull()
        ->and($request->fresh()->approvalRequest->isPending())->toBeTrue();

    app(ApproveStockRequest::class)->handle($request->fresh(), $this->manager);

    expect($request->fresh()->resulting_transfer_id)->not->toBeNull()
        ->and($request->fresh()->approvalRequest->isComplete())->toBeTrue();
});
