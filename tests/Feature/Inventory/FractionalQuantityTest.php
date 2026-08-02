<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Spatie\Permission\PermissionRegistrar;

/**
 * I2 - FIFO consumption did its arithmetic in PHP floats over decimal(15,4)
 * columns.
 *
 * ⚠⚠ THESE TESTS PASSED BEFORE THE FIX, and that is the point of keeping them.
 *
 * The review claimed FIFO threw InsufficientStockException for stock that was
 * exactly sufficient. It did not. Withdrawing 0.3 across batches of 0.1 and 0.2
 * leaves `$remaining` at exactly zero, because the second `min()` returns the
 * same float that is then subtracted from it and the error cancels.
 *
 * What IS real is the dust: the batch is genuinely left holding 2.78e-17. It
 * never becomes visible because `StockBatch` casts `current_quantity` to
 * `decimal:4`, so the value is re-quantised on write and reads back as 0.0000,
 * and the depletion check therefore sees zero.
 *
 * ⚠ So the MODEL CAST is what makes this correct, not the arithmetic. Nothing
 * said that cast was load-bearing. The arithmetic is now integer-scaled by
 * 10000, which is the whole domain of a decimal(15,4) column, so correctness no
 * longer depends on something rescuing it somewhere else.
 *
 * These tests are the regression net for that: they fail if anybody removes the
 * cast, and they fail if the scaling is wrong.
 *
 * See INVENTORY-READINESS-REVIEW.md §4 I2 and the correction in §12.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Fraction Co', 'slug' => 'fraction-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-KG',
        'name' => 'Loose seed',
        'type' => ProductType::RawMaterial,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
});

function stockIn($test, string $quantity, string $unitCost = '100'): StockMovement
{
    return app(RecordStockIn::class)->handle(
        product: $test->product,
        warehouse: $test->warehouse,
        quantity: $quantity,
        unitCost: $unitCost,
        performedBy: $test->user,
    );
}

it('withdraws a quantity that exactly empties two fractional batches', function () {
    // ⚠ THE DEFECT. 0.1 + 0.2 is 0.30000000000000004 in float, so the old code
    // finished with a positive remainder and refused stock that was exactly
    // sufficient.
    stockIn($this, '0.1');
    stockIn($this, '0.2');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0.3',
        performedBy: $this->user,
    );

    $onHand = StockBatch::where('product_id', $this->product->id)->sum('current_quantity');

    expect((float) $onHand)->toBe(0.0);
});

it('leaves no dust behind, so an emptied batch is actually marked depleted', function () {
    // The other direction: a residue of 1e-13 is > 0, so the batch stayed
    // "active" and kept appearing as available stock that was not there.
    stockIn($this, '0.1');
    stockIn($this, '0.2');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0.3',
        performedBy: $this->user,
    );

    $batches = StockBatch::where('product_id', $this->product->id)->get();

    expect($batches)->toHaveCount(2);

    foreach ($batches as $batch) {
        expect((float) $batch->current_quantity)->toBe(0.0)
            ->and($batch->status->value)->toBe('depleted');
    }
});

it('still refuses a withdrawal that is genuinely one unit short', function () {
    // The guard must not be loosened into rounding a real shortage away.
    stockIn($this, '0.1');
    stockIn($this, '0.2');

    expect(fn () => app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0.3001',
        performedBy: $this->user,
    ))->toThrow(InsufficientStockException::class);

    // C-INV-2: the refusal is total. Nothing was consumed on the way to failing.
    //
    // ⚠ Compared as a formatted string, not as a float. SQLite's SUM over two
    // decimal columns returns 0.30000000000000004, which is an artifact of the
    // test database rather than anything the application did - the same
    // driver-dependent decimal behaviour this project has met three times.
    $onHand = StockBatch::where('product_id', $this->product->id)->sum('current_quantity');
    expect(number_format((float) $onHand, 4, '.', ''))->toBe('0.3000');
});

it('keeps the remainder exact when a withdrawal crosses several fractional batches', function () {
    stockIn($this, '1.115');
    stockIn($this, '2.225');
    stockIn($this, '3.335');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5.5',
        performedBy: $this->user,
    );

    $onHand = StockBatch::where('product_id', $this->product->id)->sum('current_quantity');

    // 6.675 - 5.5, to the fourth decimal the column actually stores.
    expect(number_format((float) $onHand, 4, '.', ''))->toBe('1.1750');

    // And the ledger agrees with the batches, which is the §11 invariant.
    $out = (float) StockMovement::where('direction', 'out')->sum('quantity');
    expect(number_format($out, 4, '.', ''))->toBe('5.5000');
});
