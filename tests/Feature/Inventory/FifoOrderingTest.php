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
 * I5 - the FIFO tiebreak was not a total order.
 *
 * `scopeConsumable()` ordered by expiry, then by `created_at`. That timestamp is
 * second-precision, so two batches of the same product created within one second
 * ordered NON-DETERMINISTICALLY - and which one is chosen decides which cost
 * layer is consumed, which is cost of goods sold.
 *
 * ⚠ Two batches in the same second is ordinary, not exotic: one goods receipt
 * carrying the same product on two lines does it, and so does any import.
 *
 * ⚠⚠ This is W2 one module over. An ordering that decides an outcome must be
 * total. There it was a display string choosing a tenant; here it is a
 * coin-flip choosing a price.
 *
 * See INVENTORY-READINESS-REVIEW.md §4 I5.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Fifo Co', 'slug' => 'fifo-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-FIFO',
        'name' => 'Widget',
        'type' => ProductType::RawMaterial,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

it('orders consumable batches by a column that cannot tie', function () {
    // ⚠⚠ A STRUCTURAL test, and it is the one that actually protects this.
    //
    // The behavioural tests below pass against the BROKEN version too, because
    // SQLite happens to return tied rows in rowid order. That is exactly what a
    // non-total ordering does: it is right by accident until an engine, a query
    // plan or an index change disagrees, and then it silently picks a different
    // cost layer. Nothing observable distinguishes "correct" from "lucky" here,
    // so asserting on behaviour alone would be self-deception.
    //
    // Same reasoning as the TenantScope structural test in W1: when the property
    // you need is "the mechanism is present", assert the mechanism.
    $sql = sqlText(StockBatch::query()->consumable()->toSql());

    expect($sql)->toContain('"stock_batches"."id" asc');
});

it('consumes the earlier batch when both were created in the same second', function () {
    // ⚠ Time frozen, so both batches carry an identical created_at. That is
    // exactly one goods receipt with the same product on two lines.
    Carbon::setTestNow('2026-08-02 10:00:00');

    $first = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '100',
        performedBy: $this->user,
    );

    $second = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '200',
        performedBy: $this->user,
    );

    expect(StockBatch::find($first->batch_id)->created_at->eq(StockBatch::find($second->batch_id)->created_at))
        ->toBeTrue('the two batches must share a timestamp or this test proves nothing');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        performedBy: $this->user,
    );

    // First in, first out. The cheaper layer is the one that arrived first, so
    // it is the one that must leave.
    expect((float) StockBatch::find($first->batch_id)->current_quantity)->toBe(0.0)
        ->and((float) StockBatch::find($second->batch_id)->current_quantity)->toBe(5.0);

    // ⚠ And the cost recorded is the FIRST layer's. This is the assertion that
    // matters, because this is what reaches the accounts.
    $out = StockMovement::where('direction', 'out')->sole();
    expect((float) $out->unit_cost)->toBe(100.0);

    Carbon::setTestNow();
});

it('still prefers an earlier expiry over an earlier arrival', function () {
    // The id floor must sit UNDERNEATH the expiry rule, not replace it.
    Carbon::setTestNow('2026-08-02 10:00:00');

    $arrivedFirst = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '100',
        performedBy: $this->user,
        expiryDate: '2027-12-31',
    );

    $expiresSooner = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '200',
        performedBy: $this->user,
        expiryDate: '2026-09-30',
    );

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        performedBy: $this->user,
    );

    expect((float) StockBatch::find($expiresSooner->batch_id)->current_quantity)->toBe(0.0)
        ->and((float) StockBatch::find($arrivedFirst->batch_id)->current_quantity)->toBe(5.0);

    Carbon::setTestNow();
});

it('orders a dated batch ahead of an undated one', function () {
    Carbon::setTestNow('2026-08-02 10:00:00');

    $undated = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '100',
        performedBy: $this->user,
    );

    $dated = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '200',
        performedBy: $this->user,
        expiryDate: '2027-01-01',
    );

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        performedBy: $this->user,
    );

    expect((float) StockBatch::find($dated->batch_id)->current_quantity)->toBe(0.0)
        ->and((float) StockBatch::find($undated->batch_id)->current_quantity)->toBe(5.0);

    Carbon::setTestNow();
});
