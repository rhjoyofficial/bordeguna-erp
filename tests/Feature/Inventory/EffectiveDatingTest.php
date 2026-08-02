<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * Slice 1 of the Stock Count and Backdating architecture: when a thing HAPPENED,
 * as opposed to when we were told about it.
 *
 * ⚠⚠ Every quantity in Inventory was stamped only with `created_at`, which means
 * "when the row was written". Nothing recorded when the event occurred in the
 * warehouse. While those are the same, everything works; they stop being the
 * same the moment a delivery arrives on Saturday and is entered on Monday.
 *
 * ⚠ HRM has already paid for this exact assumption. Schedule assignments were
 * dated from the setup day while the backfill covered earlier dates, so for those
 * days no shift resolved and lateness was judged against nothing. No error, no
 * warning, just wrong, and found only when real data arrived.
 *
 * ⚠⚠ The trap that makes this more than a column: BACKDATING CHANGES WHAT FIFO
 * MEANS. Order by `created_at` and a batch entered today but received last month
 * sorts last, so first-in-first-out silently starts consuming the newer cost
 * layer. The ordering has to follow `received_at`.
 *
 * See STOCK-COUNT-AND-BACKDATING-ARCHITECTURE.md §2.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Dating Co', 'slug' => 'dating-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-DATE',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    Carbon::setTestNow('2026-08-02 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('defaults the effective date to now, so nothing has to think about it', function () {
    $movement = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
    );

    expect($movement->occurred_at->toDateTimeString())->toBe('2026-08-02 09:00:00')
        ->and(StockBatch::find($movement->batch_id)->received_at->toDateTimeString())->toBe('2026-08-02 09:00:00');
});

it('records goods that arrived on Saturday but were entered on Monday', function () {
    $movement = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-07-25 14:30:00',
    );

    // ⚠ Both dates are kept, deliberately. One answers "what did we believe on
    // the 3rd", the other "what was actually on the shelf on the 3rd", and an
    // audit needs both. Collapsing them destroys the first question forever.
    expect($movement->occurred_at->toDateTimeString())->toBe('2026-07-25 14:30:00')
        ->and($movement->created_at->toDateTimeString())->toBe('2026-08-02 09:00:00');
});

it('consumes by when stock ARRIVED, not by when it was typed in', function () {
    // ⚠⚠ THE TRAP. Entered second, but it arrived first, so FIFO must take it
    // first. Ordering by created_at would consume the other one and charge the
    // wrong cost layer to the accounts.
    $enteredFirst = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '200',
        performedBy: $this->user,
        occurredAt: '2026-08-01 10:00:00',
    );

    $arrivedFirst = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-07-20 10:00:00',
    );

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        performedBy: $this->user,
    );

    expect((float) StockBatch::find($arrivedFirst->batch_id)->current_quantity)->toBe(0.0)
        ->and((float) StockBatch::find($enteredFirst->batch_id)->current_quantity)->toBe(5.0);

    // The assertion that reaches the accounts.
    $out = StockMovement::where('direction', 'out')->sole();
    expect((float) $out->unit_cost)->toBe(100.0);
});

it('refuses a movement dated in the future', function () {
    // Future-dated stock is availability that does not exist yet.
    expect(fn () => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-08-03 09:00:00',
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a withdrawal dated before the stock it consumes had arrived', function () {
    // Not a typo to be sorted into place: an impossible sequence. Taking stock
    // out on the 1st that arrived on the 5th did not happen.
    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-08-01 10:00:00',
    );

    expect(fn () => app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        performedBy: $this->user,
        occurredAt: '2026-07-28 10:00:00',
    ))->toThrow(InvalidArgumentException::class);

    // Refused entirely: nothing was consumed on the way to failing.
    expect((float) StockBatch::sum('current_quantity'))->toBe(10.0);
});

it('keeps the id floor underneath the arrival date', function () {
    // Two batches that arrived at the same moment still need a total order, or
    // the tie decides the cost layer. Same lesson as I5 and as W2 before it.
    $sql = StockBatch::query()->consumable()->toSql();

    expect($sql)->toContain('"stock_batches"."received_at" asc')
        ->and($sql)->toContain('"stock_batches"."id" asc');
});

it('backfills existing rows so nothing already recorded changes meaning', function () {
    // The migration copies created_at into the new columns. A row written before
    // this feature existed must keep saying exactly what it always said.
    $movement = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
    );

    expect($movement->occurred_at->toDateTimeString())->toBe($movement->created_at->toDateTimeString());
});
