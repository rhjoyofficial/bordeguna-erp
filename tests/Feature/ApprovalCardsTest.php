<?php

use App\Models\User;
use App\Modules\Crm\Models\MessageCampaign;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockRequest;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\ApprovalCards;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy, 31 Aug 2026: "something comes for approval, if I do not get enough
 * details information, why I approve or reject it?" Every inbox card must
 * carry the FACTS a decision needs - amounts, quantities, the stock it
 * touches, the money it spends, the requester's own words - and a link to the
 * full record. This file pins the decision-grade payload for the types where
 * a thin summary is most dangerous.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Decide Co', 'slug' => 'decide-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.stock.adjust'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-A', 'name' => 'Widget', 'type' => ProductType::RawMaterial,
    ]);
    $this->reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Damaged in handling',
        'is_active' => true,
    ]);

    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
    );

    $this->cardFor = function ($approvable) {
        $request = $approvable->approvalRequest()->firstOrFail()->load('requestedBy');
        $request->setRelation('approvable', $approvable);

        return app(ApprovalCards::class)->present($request);
    };

    $this->detail = fn (array $card, string $label) => collect($card['details'])
        ->firstWhere('label', $label)['value'] ?? null;
});

it('shows an inventory adjustment with its value, the stock it touches, and the reason', function () {
    $adjustment = app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Damaged,
        direction: AdjustmentDirection::Decrease,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '2',
        reason: $this->reason,
        createdBy: $this->user,
        note: 'Two crushed in the van',
    );

    $card = ($this->cardFor)($adjustment->load('product', 'warehouse', 'reason'));

    // The approver decides on consequences: what it is worth, what the book
    // holds right now, and why the requester says it happened.
    expect(($this->detail)($card, 'Estimated value'))->toContain('200')
        ->and(($this->detail)($card, 'On hand now'))->toContain('10')
        ->and(($this->detail)($card, 'Reason'))->toBe('Damaged in handling')
        ->and($card['reason'])->toBe('Two crushed in the van')
        ->and($card['view_url'])->not->toBeNull()
        ->and($card['details'])->not->toBeEmpty();
});

it('shows a stock request with the on-hand quantity beside the asked quantity', function () {
    // A stock request is ONLY a transfer since adjustment slice 3.
    $cold = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Cold Storage', 'code' => 'COLD']);

    $stockRequest = StockRequest::create([
        'business_id' => $this->business->id,
        'type' => 'transfer',
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'target_warehouse_id' => $cold->id,
        'quantity' => '4',
        'reason_id' => $this->reason->id,
        'note' => 'Cold room needs stock for tomorrow',
    ]);
    $stockRequest->submitForApproval($this->user);

    $card = ($this->cardFor)($stockRequest->load('product', 'warehouse', 'targetWarehouse', 'reason'));

    expect(($this->detail)($card, 'Quantity'))->toContain('4')
        ->and(($this->detail)($card, 'On hand now'))->toContain('10')
        ->and(($this->detail)($card, 'What'))->toContain('Cold Storage')
        ->and($card['reason'])->toBe('Cold room needs stock for tomorrow')
        ->and($card['details'])->not->toBeEmpty();
});

it('shows a purchase order with its lines, total and expected date', function () {
    $supplier = \App\Modules\Purchasing\Models\Supplier::create([
        'business_id' => $this->business->id, 'name' => 'Razzak Oil Mill', 'code' => 'RAZZAK',
    ]);

    $po = PurchaseOrder::create([
        'business_id' => $this->business->id,
        'supplier_id' => $supplier->id,
        'warehouse_id' => $this->warehouse->id,
        'po_number' => 'PO-TEST-0001',
        'status' => 'open',
        'expected_date' => '2026-09-15',
        'note' => 'September oil restock',
        'created_by' => $this->user->id,
    ]);
    $po->lines()->create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'quantity_ordered' => '100',
        'unit_cost' => '180',
    ]);
    $po->submitForApproval($this->user);

    $card = ($this->cardFor)($po->load('supplier', 'lines.product'));

    // Saying yes to a PO is saying yes to spending: the lines and the total
    // must be on the card, not behind a click.
    expect($card['lines'])->toHaveCount(1)
        ->and($card['lines'][0]['description'])->toContain('Widget')
        ->and($card['lines'][0]['amount'])->toContain('18,000')
        ->and(($this->detail)($card, 'Order total'))->toContain('18,000')
        ->and(($this->detail)($card, 'Expected'))->toContain('2026-09-15')
        ->and($card['reason'])->toBe('September oil restock')
        ->and($card['view_url'])->toContain('purchase-orders');
});

it('shows a sales order with its lines and order total', function () {
    $customer = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Dhaka Dealer', 'code' => 'DHK-01',
    ]);

    $order = SalesOrder::create([
        'business_id' => $this->business->id,
        'customer_id' => $customer->id,
        'warehouse_id' => $this->warehouse->id,
        'order_number' => 'SO-TEST-0001',
        'status' => 'open',
        'ordered_at' => now(),
        'created_by' => $this->user->id,
    ]);
    $order->lines()->create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'quantity_ordered' => '5',
        'unit_price' => '250',
    ]);
    $order->submitForApproval($this->user);

    $card = ($this->cardFor)($order->load('customer', 'warehouse', 'lines.product'));

    expect($card['lines'])->toHaveCount(1)
        ->and($card['lines'][0]['amount'])->toContain('1,250')
        ->and(($this->detail)($card, 'Order total'))->toContain('1,250')
        ->and(($this->detail)($card, 'Customer'))->toBe('Dhaka Dealer')
        ->and($card['view_url'])->toContain('sales/orders');
});

it('shows a message campaign with its cost and what is actually being sent', function () {
    $campaign = MessageCampaign::create([
        'business_id' => $this->business->id,
        'channel' => 'sms',
        'name' => 'Eid greetings',
        'body' => 'Eid Mubarak from Bor de Guna! Fresh mustard oil now in stock.',
        'audience_type' => 'customers',
        'status' => 'pending_approval',
        'estimated_recipients' => 120,
        'estimated_parts' => 1,
        'estimated_cost' => '84.00',
        'created_by' => $this->user->id,
    ]);
    $campaign->submitForApproval($this->user);

    $card = ($this->cardFor)($campaign);

    // The only approval that spends money DIRECTLY, and the one where the
    // words being sent to 120 phones are the whole decision.
    expect(($this->detail)($card, 'Estimated cost'))->toContain('84')
        ->and(($this->detail)($card, 'Recipients'))->toContain('120')
        ->and($card['reason'])->toContain('Eid Mubarak')
        ->and($card['details'])->not->toBeEmpty();
});

it('never returns a card without the details key, even for the fallback', function () {
    // The guard test enforces that every approvable has a real case; this
    // pins the SHAPE so the screen can rely on it for every type.
    $adjustment = app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Damaged,
        direction: AdjustmentDirection::Decrease,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '1',
        reason: $this->reason,
        createdBy: $this->user,
    );

    $card = ($this->cardFor)($adjustment);

    expect($card)->toHaveKeys(['id', 'type', 'summary', 'details', 'reason', 'lines', 'view_url', 'approve_url', 'reject_url', 'requested_by', 'requested_at', 'stage']);
});
