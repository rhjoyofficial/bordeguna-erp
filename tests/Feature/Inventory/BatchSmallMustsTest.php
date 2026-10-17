<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\HoldStockBatch;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Traceability, the small musts (17 Oct 2026):
 * - S10: a stock transfer keeps the batch's made-on date and MRP (they were
 *   dropped on the destination batch), and remembers which batch it came from;
 * - S8: the Expiry screen answers "what expires in 30 / 60 / 90 days / all".
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Small Musts Co', 'slug' => 'small-musts-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    Permission::findOrCreate('inventory.reports.view', 'web');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $this->savar = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->shop = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SHP']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'LLD-GHE-PURE-500G', 'name' => 'LaLaDia Pure Ghee 500g', 'type' => ProductType::FinishedGood,
    ]);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->givePermissionTo('inventory.reports.view');

    $this->reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Restock', 'is_active' => true]);

    $this->in = fn (string $number, string $expiry, ?string $made = null, ?string $mrp = null) => StockBatch::findOrFail(app(RecordStockIn::class)->handle(
        product: $this->product, warehouse: $this->savar, quantity: '10', unitCost: '762', performedBy: $this->user,
        batchNumber: $number, expiryDate: $expiry, occurredAt: '2026-10-17 09:00:00', manufacturedDate: $made, mrp: $mrp,
    )->batch_id);

    $this->move = function (string $qty) {
        $transfer = app(DispatchStockTransfer::class)->handle($this->product, $this->savar, $this->shop, $qty, $this->reason, $this->user);
        app(ReceiveStockTransfer::class)->handle($transfer, $this->user);

        return StockBatch::where('warehouse_id', $this->shop->id)->latest('id')->firstOrFail();
    };

    $this->as = fn () => $this->actingAs($this->user)->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

it('keeps the made-on date and the MRP on a transferred batch, and remembers its source', function () {
    $source = ($this->in)('PRN-2026-27-0001', '2027-03-31', '2026-10-17', '950');

    $arrived = ($this->move)('4');

    expect($arrived->batch_number)->toBe('PRN-2026-27-0001')
        ->and($arrived->expiry_date->toDateString())->toBe('2027-03-31')
        ->and($arrived->manufactured_date?->toDateString())->toBe('2026-10-17')
        ->and((float) $arrived->mrp)->toBe(950.0)
        ->and((int) $arrived->source_batch_id)->toBe($source->id);
});

it('stock still on the road when its batch is put on hold arrives on hold', function () {
    $source = ($this->in)('PRN-2026-27-0002', '2027-03-31', '2026-10-17', '950');
    $transfer = app(DispatchStockTransfer::class)->handle($this->product, $this->savar, $this->shop, '3', $this->reason, $this->user);

    app(HoldStockBatch::class)->hold($source, $this->user, 'Customer complaint');
    app(ReceiveStockTransfer::class)->handle($transfer, $this->user);

    $arrived = StockBatch::where('warehouse_id', $this->shop->id)->sole();

    expect($arrived->isHeld())->toBeTrue()
        ->and($arrived->hold_reason)->toContain('Customer complaint');
});

it('lists what expires within 30, 60 or 90 days, or every dated batch', function () {
    $in20 = ($this->in)('B-20', '2026-11-06');
    $in50 = ($this->in)('B-50', '2026-12-06');
    $in80 = ($this->in)('B-80', '2027-01-05');
    $in200 = ($this->in)('B-200', '2027-05-05');

    $ids = fn (string $query) => collect(($this->as)()->get('/inventory/expiry'.$query)->assertOk()->viewData('page')['props']['expiring'])->pluck('id')->all();

    expect($ids('?within=30'))->toBe([$in20->id])
        ->and($ids('?within=60'))->toBe([$in20->id, $in50->id])
        ->and($ids('?within=90'))->toBe([$in20->id, $in50->id, $in80->id])
        ->and($ids('?within=all'))->toBe([$in20->id, $in50->id, $in80->id, $in200->id])
        // No choice: each product's own window, 90 days by default, as before.
        ->and($ids(''))->toBe([$in20->id, $in50->id, $in80->id]);

    ($this->as)()->get('/inventory/expiry?within=60')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('within', '60'));

    // Anything else is ignored rather than trusted.
    ($this->as)()->get('/inventory/expiry?within=7')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('within', ''));
});
