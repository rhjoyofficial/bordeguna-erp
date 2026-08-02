<?php

use App\Models\User;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * Slice 2: the period lock (C-SC-2).
 *
 * Once a period is closed, no ordinary user may post or backdate inventory into
 * it. A backdated movement landing before a date somebody has already reported
 * on makes that report wrong, silently, and nothing else in the system would
 * ever say so.
 *
 * ⚠⚠ Only the Founder may unlock, it is TEMPORARY, and every unlock and relock
 * is audited. Joy's wording: "every unlock/relock must be fully audited". So the
 * unlock is an event with a name attached, not a setting somebody quietly flips
 * and forgets - which is the failure mode of every "maintenance mode" toggle
 * ever built.
 *
 * See STOCK-COUNT-AND-BACKDATING-ARCHITECTURE.md §7 C-SC-2.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Lock Co', 'slug' => 'lock-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-LOCK',
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

function lockThrough(string $date, User $by): AccountingPeriod
{
    return AccountingPeriod::closeThrough(Carbon::parse($date), $by, 'July close');
}

it('allows posting when no period has ever been closed', function () {
    $movement = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-07-15 10:00:00',
    );

    expect($movement->occurred_at->toDateTimeString())->toBe('2026-07-15 10:00:00');
});

it('refuses a movement dated inside a closed period', function () {
    lockThrough('2026-07-31', $this->user);

    expect(fn () => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-07-15 10:00:00',
    ))->toThrow(PeriodClosedException::class);

    expect(StockBatch::count())->toBe(0);
});

it('allows a movement dated after the closed period', function () {
    lockThrough('2026-07-31', $this->user);

    $movement = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-08-01 10:00:00',
    );

    expect($movement->occurred_at->toDateTimeString())->toBe('2026-08-01 10:00:00');
});

it('treats the closing date itself as closed', function () {
    // "Closed through 31 July" must include the 31st, or the last day of every
    // period stays quietly open and the lock is off by one for its whole life.
    lockThrough('2026-07-31', $this->user);

    expect(fn () => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-07-31 23:59:00',
    ))->toThrow(PeriodClosedException::class);
});

it('lets the Founder unlock, post, and relock, recording every step', function () {
    $founder = User::factory()->create(['is_super_admin' => true]);
    $founder->businesses()->attach($this->business->id);

    lockThrough('2026-07-31', $this->user);

    AccountingPeriod::current()->unlock($founder, 'Correcting a missed delivery');

    $movement = app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '10',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-07-15 10:00:00',
    );

    expect($movement->occurred_at->toDateTimeString())->toBe('2026-07-15 10:00:00');

    AccountingPeriod::current()->relock($founder);

    expect(fn () => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        unitCost: '100',
        performedBy: $this->user,
        occurredAt: '2026-07-16 10:00:00',
    ))->toThrow(PeriodClosedException::class);
});

it('audits the unlock and the relock, with who and why', function () {
    // ⚠ The whole reason an unlock is an event and not a boolean. A closed
    // period that was opened, written into and closed again leaves a trail
    // saying who opened it and what for.
    $founder = User::factory()->create(['is_super_admin' => true]);
    $founder->businesses()->attach($this->business->id);

    lockThrough('2026-07-31', $this->user);
    AccountingPeriod::current()->unlock($founder, 'Correcting a missed delivery');
    AccountingPeriod::current()->relock($founder);

    $entries = Activity::where('log_name', 'accounting.period')
        ->orderBy('id')
        ->get();

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->description)->toContain('Closed')
        ->and($entries[1]->description)->toContain('Unlocked')
        ->and($entries[1]->causer_id)->toBe($founder->id)
        ->and($entries[1]->properties['note'])->toBe('Correcting a missed delivery')
        ->and($entries[2]->description)->toContain('Relocked');
});

it('scopes the lock to one business, so closing one does not freeze another', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);

    lockThrough('2026-07-31', $this->user);

    app(BusinessContext::class)->set($other->id);

    expect(AccountingPeriod::current())->toBeNull();
});
