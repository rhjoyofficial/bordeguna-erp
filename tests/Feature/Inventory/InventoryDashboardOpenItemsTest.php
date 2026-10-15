<?php

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\SubmitStockRequest;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockRequestType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OpenItems;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Inventory overview's "Needs you" card (redesign, 15 Oct 2026): the open
 * documents the module already keeps, counted the way their own screens count
 * them. Each number is one OpenItems query, shared with the screen it links
 * to, so the card and the list behind it cannot disagree.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');

    $this->business = Business::create(['name' => 'Open Items Co', 'slug' => 'open-items-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->shop = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SHOP']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'GHEE-400', 'name' => 'Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);

    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->user->businesses()->attach($this->business->id);

    $this->visit = fn () => $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('inventory.dashboard'));
});

afterEach(fn () => Carbon::setTestNow());

it('reports every open item as zero when nothing is waiting', function () {
    ($this->visit)()
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/dashboard')
            ->where('open_items.stock_requests_waiting', 0)
            ->where('open_items.transfers_in_transit', 0)
            ->where('open_items.counts_in_progress', 0)
            ->where('open_items.draft_production_runs', 0)
            ->where('open_items.expired_batches_on_shelf', 0));
});

it('counts transfers still on the road, not the ones already received', function () {
    app(RecordStockIn::class)->handle($this->product, $this->main, '20', '100', $this->user);
    $reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Restock']);

    foreach (['in_transit', 'in_transit', 'received'] as $status) {
        StockTransfer::create([
            'business_id' => $this->business->id,
            'transfer_group_id' => (string) Str::uuid(),
            'product_id' => $this->product->id,
            'source_warehouse_id' => $this->main->id,
            'target_warehouse_id' => $this->shop->id,
            'quantity' => '1',
            'reason_id' => $reason->id,
            'status' => $status,
            'dispatched_by' => $this->user->id,
            'dispatched_at' => now(),
            'received_by' => $status === 'received' ? $this->user->id : null,
            'received_at' => $status === 'received' ? now() : null,
        ]);
    }

    expect(OpenItems::transfersInTransit())->toBe(2);

    ($this->visit)()->assertInertia(fn (AssertableInertia $page) => $page->where('open_items.transfers_in_transit', 2));
});

it('counts stock requests still waiting for an approver, the way the Requests tab lists them', function () {
    app(RecordStockIn::class)->handle($this->product, $this->main, '20', '100', $this->user);
    $reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Restock']);

    $submit = fn () => app(SubmitStockRequest::class)->handle(
        StockRequestType::Transfer, $this->product, $this->main, '2', $reason, $this->user, $this->shop,
    );

    $decided = $submit();
    $submit();
    $submit();

    // One decided: it leaves the queue, whichever way it went.
    $decided->approvalRequest->update(['status' => ApprovalStatus::Rejected]);

    expect(OpenItems::stockRequestsWaiting())->toBe(2);

    ($this->visit)()->assertInertia(fn (AssertableInertia $page) => $page->where('open_items.stock_requests_waiting', 2));
});

it('counts stock counts still in progress and production runs still drafts', function () {
    foreach (['draft', 'draft', 'completed', 'cancelled'] as $status) {
        StockCount::create([
            'business_id' => $this->business->id,
            'warehouse_id' => $this->main->id,
            'count_number' => 'CNT-'.fake()->unique()->numerify('####'),
            'status' => $status,
            'created_by' => $this->user->id,
        ]);
    }

    foreach (['draft', 'confirmed', 'reversed'] as $status) {
        ProductionRun::create([
            'business_id' => $this->business->id,
            'warehouse_id' => $this->main->id,
            'output_product_id' => $this->product->id,
            'run_number' => 'RUN-'.fake()->unique()->numerify('####'),
            'output_quantity' => '10',
            'status' => $status,
            'created_by' => $this->user->id,
            'occurred_at' => now(),
        ]);
    }

    expect(OpenItems::countsInProgress())->toBe(2)
        ->and(OpenItems::draftProductionRuns())->toBe(1);

    ($this->visit)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('open_items.counts_in_progress', 2)
        ->where('open_items.draft_production_runs', 1));
});

it('counts expired batches still on the shelf by the expiry screen\'s own rule', function () {
    // Expired yesterday, good through today, and expired but empty: one counts.
    foreach ([['2026-10-14', '5'], ['2026-10-15', '5'], ['2026-10-01', '0']] as [$expiry, $quantity]) {
        StockBatch::create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->main->id,
            'batch_number' => 'B-'.$expiry, 'expiry_date' => $expiry,
            'unit_cost' => '100', 'conversion_factor' => '1',
            'initial_quantity' => '5', 'current_quantity' => $quantity,
            'status' => 'active', 'received_at' => now(),
        ]);
    }

    expect(OpenItems::expiredBatchesOnShelf(Carbon::today()))->toBe(1);

    ($this->visit)()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('open_items.expired_batches_on_shelf', 1)
        // The strip and the card read the same collection.
        ->where('expiry.expired', 1));
});
