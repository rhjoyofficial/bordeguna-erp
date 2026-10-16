<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\CompleteStockCount;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stock counts / cycle counting (C-CNT-1..9): the count is a QUESTIONNAIRE,
 * its variances are ordinary InventoryAdjustments through the same action the
 * manual form calls. The count itself must never move stock.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Count Co', 'slug' => 'count-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.reports.view', 'inventory.stock.adjust', 'inventory.stock.request', 'inventory.stock.approve'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-A', 'name' => 'Widget', 'type' => ProductType::RawMaterial,
    ]);
    $this->other = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-B', 'name' => 'Gadget', 'type' => ProductType::FinishedGood,
    ]);

    $this->reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Count variance',
        'is_active' => true,
    ]);

    $this->userWith = function (array $permissions) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->givePermissionTo($permissions);

        return $user;
    };

    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    $this->manager = ($this->userWith)(['inventory.reports.view', 'inventory.stock.adjust']);
    $this->requester = ($this->userWith)(['inventory.reports.view', 'inventory.stock.request']);

    $this->stockIn = fn (Product $product, string $qty, string $cost) => app(RecordStockIn::class)->handle(
        product: $product,
        warehouse: $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->manager,
    );

    $this->onHand = fn (Product $product) => (float) StockBatch::query()
        ->where('product_id', $product->id)
        ->where('warehouse_id', $this->warehouse->id)
        ->sum('current_quantity');

    // Everything the manager raises self-approves, so variances post at once.
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000000']);
});

it('starting a count snapshots expected quantities and moves nothing', function () {
    ($this->stockIn)($this->product, '10', '100');
    $movementsBefore = StockMovement::count();

    ($this->as)($this->manager)
        ->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $count = StockCount::firstOrFail();

    expect($count->status->value)->toBe('draft')
        ->and($count->count_number)->toStartWith('CNT-')
        ->and($count->lines)->toHaveCount(1)
        ->and((float) $count->lines->first()->expected_quantity)->toBe(10.0)
        ->and($count->lines->first()->counted_quantity)->toBeNull()
        ->and(StockMovement::count())->toBe($movementsBefore)
        ->and(InventoryAdjustment::count())->toBe(0);
});

it('completion adjusts only the variance lines, sourced back to the count', function () {
    ($this->stockIn)($this->product, '10', '100');
    ($this->stockIn)($this->other, '4', '50');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();

    $short = $count->lines->firstWhere('product_id', $this->product->id);
    $exact = $count->lines->firstWhere('product_id', $this->other->id);

    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$short->id}", ['counted_quantity' => '7']);
    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$exact->id}", ['counted_quantity' => '4']);

    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $adjustments = InventoryAdjustment::all();

    // One adjustment for the shortfall, none for the exact line (C-CNT-3's
    // sibling: matching stock is a finding, not a correction).
    expect($adjustments)->toHaveCount(1)
        ->and($adjustments->first()->type)->toBe(InventoryAdjustmentType::StockCountVariance)
        ->and($adjustments->first()->direction)->toBe(AdjustmentDirection::Decrease)
        ->and((float) $adjustments->first()->quantity)->toBe(3.0)
        ->and($adjustments->first()->source_type)->toBe(StockCountLine::class)
        ->and($adjustments->first()->source_id)->toBe($short->id)
        ->and(($this->onHand)($this->product))->toBe(7.0)
        ->and($count->refresh()->status->value)->toBe('completed');
});

it('sizes the variance against LIVE stock at completion, never the snapshot', function () {
    ($this->stockIn)($this->product, '10', '100');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();
    $line = $count->lines->first();

    // Stock legitimately leaves mid-count: 2 damaged, approved and posted.
    app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Damaged,
        direction: AdjustmentDirection::Decrease,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '2',
        reason: $this->reason,
        createdBy: $this->manager,
    );

    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => '7']);

    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
        ->assertSessionHasNoErrors();

    $variance = InventoryAdjustment::where('type', InventoryAdjustmentType::StockCountVariance)->firstOrFail();

    // C-CNT-2: live was 8, counted 7 - the correction is 1, NOT the 3 the
    // stale snapshot would claim, and stock lands exactly on the counted figure.
    expect((float) $variance->quantity)->toBe(1.0)
        ->and($variance->direction)->toBe(AdjustmentDirection::Decrease)
        ->and(($this->onHand)($this->product))->toBe(7.0);
});

it('skips an uncounted line entirely', function () {
    ($this->stockIn)($this->product, '10', '100');
    ($this->stockIn)($this->other, '4', '50');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();
    $counted = $count->lines->firstWhere('product_id', $this->product->id);

    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$counted->id}", ['counted_quantity' => '10']);

    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
        ->assertSessionHasNoErrors();

    // C-CNT-3: a blank means "nobody looked", never "found zero".
    expect(InventoryAdjustment::count())->toBe(0)
        ->and(($this->onHand)($this->other))->toBe(4.0);
});

it('records found stock as an increase carrying the human-entered cost', function () {
    ($this->stockIn)($this->product, '10', '100');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();

    // The book says Gadget is absent; the counter found three (C-CNT-8).
    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$count->id}/lines", ['product_id' => $this->other->id])
        ->assertSessionHasNoErrors();

    $found = $count->lines()->where('product_id', $this->other->id)->firstOrFail();
    expect((float) $found->expected_quantity)->toBe(0.0);

    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$found->id}", [
        'counted_quantity' => '3', 'unit_cost' => '55',
    ]);

    // A second complete attempt without counting SKU-A: fill it to avoid noise.
    $first = $count->lines()->where('product_id', $this->product->id)->firstOrFail();
    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$first->id}", ['counted_quantity' => '10']);

    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
        ->assertSessionHasNoErrors();

    $increase = InventoryAdjustment::where('direction', AdjustmentDirection::Increase)->firstOrFail();

    expect((float) $increase->quantity)->toBe(3.0)
        ->and((float) $increase->unit_cost)->toBe(55.0)
        ->and(($this->onHand)($this->other))->toBe(3.0);
});

it('refuses completion when a found-stock increase has no unit cost', function () {
    ($this->stockIn)($this->product, '10', '100');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();
    $line = $count->lines->first();

    // Counted MORE than the book with no cost given: C-CNT-4 refuses, because
    // an increase creates value out of nothing and the cost is never invented.
    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => '12']);

    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
        ->assertSessionHasErrors('count');

    expect($count->refresh()->status->value)->toBe('draft')
        ->and(InventoryAdjustment::count())->toBe(0)
        ->and(($this->onHand)($this->product))->toBe(10.0);
});

it('lets a requester complete, with the variance waiting for approval', function () {
    ($this->stockIn)($this->product, '10', '100');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();
    $line = $count->lines->first();

    ($this->as)($this->requester)->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => '9']);

    ($this->as)($this->requester)
        ->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
        ->assertSessionHasNoErrors();

    $variance = InventoryAdjustment::firstOrFail();

    // C-CNT-6: raised, recorded, NOT posted - stock untouched until somebody
    // with the power approves, exactly like a hand-typed request.
    expect($variance->approvalRequest)->not->toBeNull()
        ->and($variance->movements)->toHaveCount(0)
        ->and(($this->onHand)($this->product))->toBe(10.0);
});

it('refuses entry on a completed count and cancels only a draft', function () {
    ($this->stockIn)($this->product, '10', '100');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();
    $line = $count->lines->first();

    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => '10']);
    ($this->as)($this->manager)->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id]);

    ($this->as)($this->manager)
        ->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => '5'])
        ->assertSessionHasErrors('count');

    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$count->id}/cancel")
        ->assertSessionHasErrors('count');

    // A fresh draft cancels, stays as a record, and moves nothing.
    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $draft = StockCount::orderByDesc('id')->firstOrFail();

    ($this->as)($this->manager)
        ->post("/inventory/stock-counts/{$draft->id}/cancel")
        ->assertSessionHasNoErrors();

    expect($draft->refresh()->status->value)->toBe('cancelled');
});

it('renders the screens for permitted users and refuses others', function () {
    ($this->stockIn)($this->product, '10', '100');
    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();

    ($this->as)($this->manager)->get('/inventory/stock-counts')->assertOk();
    ($this->as)($this->manager)->get("/inventory/stock-counts/{$count->id}")->assertOk();
    ($this->as)($this->manager)->get("/inventory/stock-counts/{$count->id}/sheet")->assertOk();

    $stranger = ($this->userWith)([]);
    ($this->as)($stranger)->get('/inventory/stock-counts')->assertForbidden();
    ($this->as)($stranger)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id])->assertForbidden();

    // The viewer may look but not start a count (C-CNT-6).
    $viewer = ($this->userWith)(['inventory.reports.view']);
    ($this->as)($viewer)->get('/inventory/stock-counts')->assertOk();
    ($this->as)($viewer)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id])->assertForbidden();
});

it('keeps expected quantities off the blind sheet', function () {
    ($this->stockIn)($this->product, '10', '100');
    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();

    // C-CNT-7: the sheet page receives product identity only - a counter who
    // can see the book number writes the book number.
    ($this->as)($this->manager)
        ->get("/inventory/stock-counts/{$count->id}/sheet")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/stock-counts/sheet')
            ->where('lines.0.product', 'Widget')
            ->missing('lines.0.expected_quantity'));
});

it('refuses a second completion made from a stale copy of the count', function () {
    // ⚠⚠ The double-click: both requests loaded the count as a draft. The
    // second must not raise every variance again.
    ($this->stockIn)($this->product, '10', '100');

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();
    $line = $count->lines->firstWhere('product_id', $this->product->id);
    ($this->as)($this->manager)->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => '7']);

    $stale = $count->fresh();

    app(CompleteStockCount::class)->handle($count->fresh(), $this->reason, $this->manager);

    expect(fn () => app(CompleteStockCount::class)->handle($stale, $this->reason, $this->manager))
        ->toThrow(DomainException::class);

    expect(InventoryAdjustment::count())->toBe(1)
        ->and(($this->onHand)($this->product))->toBe(7.0);
});

it('does not raise the same shortfall twice when a second count runs before the first is approved (INV-002)', function () {
    ($this->stockIn)($this->product, '10', '100');
    $approver = ($this->userWith)(['inventory.reports.view', 'inventory.stock.approve', 'inventory.stock.adjust']);

    $countOnce = function (string $counted) {
        ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
        $count = StockCount::orderByDesc('id')->firstOrFail();
        $line = $count->lines->first();
        ($this->as)($this->requester)->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => $counted]);
        ($this->as)($this->requester)->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
            ->assertSessionHasNoErrors();

        return $count->fresh('lines');
    };

    $countOnce('6');
    $second = $countOnce('6');

    // The second count expected what the book will say after approval, and raised nothing.
    expect((float) $second->lines->first()->expected_quantity)->toBe(6.0)
        ->and(InventoryAdjustment::count())->toBe(1);

    ($this->as)($approver)->post('/inventory/stock/adjustments/'.InventoryAdjustment::firstOrFail()->id.'/approve')->assertSessionHasNoErrors();

    expect(($this->onHand)($this->product))->toBe(6.0);
});

it('tells the approvers once when a count leaves corrections waiting, and not the counter (INV-006)', function () {
    Notification::fake();
    ($this->stockIn)($this->product, '10', '100');
    ($this->stockIn)($this->other, '4', '50');
    $approver = ($this->userWith)(['inventory.reports.view', 'inventory.stock.approve']);

    ($this->as)($this->manager)->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
    $count = StockCount::firstOrFail();
    foreach ($count->lines as $line) {
        ($this->as)($this->requester)->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => '1']);
    }
    ($this->as)($this->requester)->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id])
        ->assertSessionHasNoErrors();

    expect(InventoryAdjustment::count())->toBe(2);
    Notification::assertSentToTimes($approver, PendingApprovalNotification::class, 1);
    Notification::assertNotSentTo($this->requester, PendingApprovalNotification::class);
});

it('tells the approvers when a single correction waits, and nobody when it approves itself (INV-006)', function () {
    Notification::fake();
    ($this->stockIn)($this->product, '10', '100');
    $approver = ($this->userWith)(['inventory.reports.view', 'inventory.stock.approve']);

    $raise = fn (User $by) => app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Damaged, direction: AdjustmentDirection::Decrease,
        product: $this->product, warehouse: $this->warehouse, quantity: '1', reason: $this->reason, createdBy: $by,
    );

    $raise($this->manager); // under the threshold: approves itself
    Notification::assertNothingSent();

    $raise($this->requester); // may not adjust: waits
    Notification::assertSentToTimes($approver, PendingApprovalNotification::class, 1);
});

// ⚠ Bug hunt 16 Oct 2026 (02 L5): two counts started at once computed the same
// number and the second hit the unique index as a 500. The other start is
// simulated by taking the number between the computing and the writing.
it('takes the next number when another count claimed this one a moment earlier', function () {
    ($this->stockIn)($this->product, '10', '100');
    $raced = null;
    StockCount::creating(function (StockCount $count) use (&$raced) {
        if ($raced === null) {
            $raced = $count->count_number;
            DB::table('stock_counts')->insert([
                'business_id' => $count->business_id, 'warehouse_id' => $count->warehouse_id, 'count_number' => $count->count_number,
                'status' => 'draft', 'created_by' => $count->created_by, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    ($this->as)($this->manager)
        ->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The other writer's row lives in its own transaction in real life; here
    // it rolls back with the failed attempt, which is beside the point: the
    // count was recorded, under the next number, and nobody saw a 500.
    expect(StockCount::count())->toBe(1)
        ->and(StockCount::sole()->count_number)->not->toBe($raced);
});
