<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\ProposeExpiredStockWriteOffs;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Audit #14 and its follow-up INV-001, 14 Oct 2026.
 *
 * #14: expired stock counted as available, so the shop and the order desk
 * offered goods FIFO refuses to hand over. Sellable now means unexpired; the
 * book (and its valuation) still carries expired stock until it is written
 * off, and the stock list says how much of a figure is expired.
 *
 * INV-001: a count is sized against the book INCLUDING expired batches, but a
 * decrease used to be taken by FIFO, which skips them. Shelf 4 expired + 6 good
 * after the expired goods were thrown away: a count of 0 was refused, a count
 * of 6 wrote off good stock and left the expired batch for the sweep to write
 * off a second time.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Expiry Count Co', 'slug' => 'expiry-count-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['inventory.reports.view', 'inventory.stock.adjust', 'inventory.stock.request', 'inventory.stock.approve'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'PICKLE', 'name' => 'Pickle jar', 'type' => ProductType::FinishedGood,
    ]);
    $this->reason = Reason::create([
        'business_id' => $this->business->id, 'context' => 'inventory.stock_adjustment',
        'name' => 'Count variance', 'is_active' => true,
    ]);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->givePermissionTo(['inventory.reports.view', 'inventory.stock.adjust']);
    $this->as = fn () => $this->actingAs($this->manager)->withHeader('X-Business-Id', (string) $this->business->id);

    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000000']);

    Carbon::setTestNow('2026-10-01 09:00:00');
    $in = fn (string $qty, string $cost, string $expiry) => app(RecordStockIn::class)->handle(
        product: $this->product, warehouse: $this->warehouse, quantity: $qty, unitCost: $cost,
        performedBy: $this->manager, expiryDate: $expiry,
    );
    $this->old = $in('4', '50', '2026-10-10')->batch_id;
    $this->new = $in('6', '100', '2027-03-01')->batch_id;

    // The old batch passes its date: book 10, of which 4 expired.
    Carbon::setTestNow('2026-10-14 09:00:00');

    $this->count = function (string $counted) {
        ($this->as)()->post('/inventory/stock-counts', ['warehouse_id' => $this->warehouse->id]);
        $count = StockCount::latest('id')->firstOrFail();
        $line = $count->lines->firstWhere('product_id', $this->product->id);
        ($this->as)()->patch("/inventory/stock-counts/{$count->id}/lines/{$line->id}", ['counted_quantity' => $counted]);

        return ($this->as)()->post("/inventory/stock-counts/{$count->id}/complete", ['reason_id' => $this->reason->id]);
    };

    $this->held = fn (int $batchId) => (float) StockBatch::whereKey($batchId)->value('current_quantity');
});

afterEach(fn () => Carbon::setTestNow());

it('counts expired stock on the book but not as sellable', function () {
    expect($this->product->currentStockQuantity())->toBe('10')
        ->and($this->product->sellableStockQuantity())->toBe('6')
        ->and($this->product->expiredStockQuantity())->toBe('4');

    // Through the WHOLE of its expiry date a batch is still good (consumable's rule).
    Carbon::setTestNow('2026-10-10 23:00:00');
    expect($this->product->sellableStockQuantity())->toBe('10')
        ->and($this->product->expiredStockQuantity())->toBe('0');
});

it('shows how much of a stock line is expired', function () {
    ($this->as)()->get('/inventory/stock')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stock.data.0.quantity', fn ($q) => (float) $q === 10.0)
            ->where('stock.data.0.expired_quantity', fn ($q) => (float) $q === 4.0));
});

it('INV-001: a count of 6 takes the 4 missing units from the EXPIRED batch, and the sweep proposes nothing', function () {
    ($this->count)('6')->assertSessionHasNoErrors();

    expect(($this->held)($this->old))->toBe(0.0)
        ->and(($this->held)($this->new))->toBe(6.0);

    $adjustment = InventoryAdjustment::latest('id')->firstOrFail();
    $movement = $adjustment->movements()->sole();

    // At the expired batch's own cost, not the good batch's.
    expect((int) $movement->batch_id)->toBe($this->old)
        ->and((float) $movement->unit_cost)->toBe(50.0)
        ->and((float) $adjustment->estimated_value)->toBe(200.0);

    app(ProposeExpiredStockWriteOffs::class)->handle();

    expect(InventoryAdjustment::count())->toBe(1);
});

it('INV-001: a count of 0 is no longer refused, it empties the expired batch first and then FIFO', function () {
    ($this->count)('0')->assertSessionHasNoErrors();

    expect(($this->held)($this->old))->toBe(0.0)
        ->and(($this->held)($this->new))->toBe(0.0)
        ->and(StockCount::latest('id')->first()->status->value)->toBe('completed');

    $movements = InventoryAdjustment::latest('id')->firstOrFail()->movements()->orderBy('id')->get();

    expect($movements)->toHaveCount(2)
        ->and((int) $movements[0]->batch_id)->toBe($this->old)
        ->and((float) $movements[0]->quantity)->toBe(4.0)
        ->and((int) $movements[1]->batch_id)->toBe($this->new)
        ->and((float) $movements[1]->quantity)->toBe(6.0);
});
