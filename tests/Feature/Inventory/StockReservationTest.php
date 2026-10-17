<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;

/**
 * Stock reservations (Phase 2, 17 Oct 2026, ruling Q1): an approved document
 * holds the exact FIFO batch its price came from, FIFO consumption and the
 * on-hand figures subtract the open holds, and a release gives the batch
 * back. Shared by builders E (staff purchase) and F (owner withdrawal).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Hold Co', 'slug' => 'hold-co']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->salt = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'SALT', 'name' => 'Salt', 'type' => ProductType::FinishedGood]);

    $in = fn (string $qty, string $cost, string $number, string $on) => StockBatch::findOrFail(app(RecordStockIn::class)->handle(
        $this->salt, $this->warehouse, $qty, $cost, $this->user, batchNumber: $number, occurredAt: $on,
    )->batch_id);

    // FIFO order: A (older, 20.00) before B (newer, 30.00).
    $this->batchA = $in('10', '20.00', 'A', '2026-10-01 09:00:00');
    $this->batchB = $in('10', '30.00', 'B', '2026-10-05 09:00:00');

    // Any model will do as the holding document.
    $this->holder = $this->user;

    $this->reserve = fn (string $qty) => app(ReserveStock::class)->handle($this->salt, $this->warehouse, $qty, 'test', $this->holder, $this->user);
});

afterEach(fn () => Carbon::setTestNow());

it('reserves the FIFO head first and splits over the next batch only when the head runs out', function () {
    $single = ($this->reserve)('4');

    expect($single)->toHaveCount(1)
        ->and($single->first()->stock_batch_id)->toBe($this->batchA->id)
        ->and((string) $single->first()->quantity)->toBe('4.0000')
        ->and(StockReservation::heldOn($this->batchA->id))->toBe('4.0000');

    // 6 left free on A, so 8 more takes 6 from A and 2 from B.
    $split = ($this->reserve)('8');

    expect($split)->toHaveCount(2)
        ->and($split[0]->stock_batch_id)->toBe($this->batchA->id)
        ->and((string) $split[0]->quantity)->toBe('6.0000')
        ->and($split[1]->stock_batch_id)->toBe($this->batchB->id)
        ->and((string) $split[1]->quantity)->toBe('2.0000')
        ->and(StockReservation::heldOn($this->batchA->id))->toBe('10.0000')
        ->and(StockReservation::heldOn($this->batchB->id))->toBe('2.0000');

    // Nothing left the shelf: a hold is not a movement.
    expect((string) $this->batchA->fresh()->current_quantity)->toBe('10.0000');
});

it('refuses a hold the free stock cannot cover, net of holds already open, and writes nothing', function () {
    ($this->reserve)('15');

    expect(fn () => ($this->reserve)('6'))->toThrow(InsufficientStockException::class);

    expect(StockReservation::query()->live()->count())->toBe(2)
        ->and(StockReservation::heldOn($this->batchB->id))->toBe('5.0000');
});

it('keeps a sale off the held batch: FIFO consumption skips what is reserved', function () {
    ($this->reserve)('10');

    // All of A is held, so the next issue comes from B at 30.00.
    $movements = app(RecordStockOut::class)->handle($this->salt, $this->warehouse, '5', $this->user);

    expect($movements)->toHaveCount(1)
        ->and($movements->first()->batch_id)->toBe($this->batchB->id)
        ->and((string) $movements->first()->unit_cost)->toBe('30.0000')
        ->and((string) $this->batchA->fresh()->current_quantity)->toBe('10.0000')
        ->and((string) $this->batchB->fresh()->current_quantity)->toBe('5.0000');

    // 5 free on B only: 6 is refused although 15 sit on the shelf.
    expect(fn () => app(RecordStockOut::class)->handle($this->salt, $this->warehouse, '6', $this->user))
        ->toThrow(InsufficientStockException::class);
});

it('gives the batch back on release, keeping the row as evidence', function () {
    $held = ($this->reserve)('10');

    expect(app(ReleaseReservation::class)->handle($this->holder))->toBe(1)
        ->and(StockReservation::heldOn($this->batchA->id))->toBe('0.0000')
        ->and($held->first()->fresh()->released_at)->not->toBeNull()
        ->and(StockReservation::count())->toBe(1);

    // Free again: the next issue is from A.
    $movements = app(RecordStockOut::class)->handle($this->salt, $this->warehouse, '5', $this->user);

    expect($movements->first()->batch_id)->toBe($this->batchA->id);

    // Releasing twice is harmless: the second pass finds nothing live.
    ($this->reserve)('2');

    expect(app(ReleaseReservation::class)->handle($this->holder))->toBe(1)
        ->and(app(ReleaseReservation::class)->handle($this->holder))->toBe(0)
        ->and(StockReservation::query()->live()->count())->toBe(0);
});
