<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockLedger;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * Slice 4. The ledger becomes the single source of truth.
 *
 * ⚠⚠ Until now `stock_batches.current_quantity` and `stock_movements` were
 * written BY THE SAME HAND, independently: consumeFifo subtracted from the batch
 * and the caller wrote a movement beside it. Every reader trusted the batch, and
 * nothing ever read the ledger back. Two numbers produced side by side, and if
 * they ever differed nothing noticed and the cache won.
 *
 * Now the movement is written and the batch DERIVES itself from it. The cache is
 * a consequence, never an independent opinion.
 *
 * Joy: "If the cache and the ledger ever disagree, treat that as a consistency
 * defect, not as two equally valid sources."
 *
 * See LEDGER-AS-SOURCE-OF-TRUTH-ARCHITECTURE.md.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Ledger Truth Co', 'slug' => 'ledger-truth-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->second = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Second', 'code' => 'SECOND']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-TRUTH',
        'name' => 'Loose seed',
        'type' => ProductType::RawMaterial,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->receive = fn (string $qty, string $cost = '100', ?Warehouse $wh = null) => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $wh ?? $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->user,
    );
});

it('derives a batch quantity from its movements rather than from arithmetic', function () {
    $movement = ($this->receive)('10');
    $batch = StockBatch::find($movement->batch_id);

    expect((float) $batch->current_quantity)->toBe(10.0)
        ->and((float) StockLedger::balanceForBatch($batch))->toBe(10.0);

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '3.5',
        performedBy: $this->user,
    );

    expect((float) $batch->fresh()->current_quantity)->toBe(6.5);
});

it('marks a batch depleted because the ledger says zero, not because a code path said so', function () {
    // ⚠ Two different classes used to make this call independently and could
    // disagree. Depletion is now a fact about the balance.
    $movement = ($this->receive)('4');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '4',
        performedBy: $this->user,
    );

    $batch = StockBatch::find($movement->batch_id);

    expect((float) $batch->current_quantity)->toBe(0.0)
        ->and($batch->status->value)->toBe('depleted');
});

it('rebuilds every batch quantity from the ledger alone', function () {
    // ⚠ The requirement in its strongest form: if every cached number were lost,
    // the movements alone must be able to restore them.
    ($this->receive)('10');
    ($this->receive)('5', '120');

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '12',
        performedBy: $this->user,
    );

    $before = StockBatch::orderBy('id')->pluck('current_quantity', 'id')->map(fn ($q) => (float) $q)->all();

    // Corrupt the cache the way a bad deploy or a hand-edited row would.
    StockBatch::query()->update(['current_quantity' => '999.0000']);

    $corrected = StockLedger::rebuildAll();

    $after = StockBatch::orderBy('id')->pluck('current_quantity', 'id')->map(fn ($q) => (float) $q)->all();

    expect($corrected)->toBe(2)
        ->and($after)->toBe($before);
});

it('reports a healthy ledger as consistent at all three levels', function () {
    ($this->receive)('10');
    ($this->receive)('5', '120', $this->second);

    app(RecordStockOut::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '4',
        performedBy: $this->user,
    );

    expect(StockLedger::verify())->toBe([]);
});

it('catches a batch whose cache no longer matches its ledger', function () {
    // Level 1.
    $movement = ($this->receive)('10');

    StockBatch::whereKey($movement->batch_id)->update(['current_quantity' => '7.0000']);

    $problems = StockLedger::verify();

    // ⚠ ONE corruption surfaces at TWO levels, and that is correct rather than
    // noisy: the batch disagrees with its own ledger (level 1) and, because the
    // warehouse total is built from batches, the warehouse disagrees too
    // (level 2). A chain that reported only the first link would leave somebody
    // wondering whether the rest of it still held. Fixing level 1 fixes both.
    expect($problems)->not->toBe([])
        ->and(implode(' | ', $problems))->toContain('batch #'.$movement->batch_id)
        ->and(implode(' | ', $problems))->toContain('ledger says 10.0000')
        ->and(implode(' | ', $problems))->toContain('cache says 7.0000');
});

it('catches a movement filed against a different warehouse than its batch', function () {
    // ⚠ Level 2, and the reason it is not the same number twice: a movement
    // carries its OWN warehouse_id, independent of its batch's. A transfer that
    // wrote a leg against the wrong warehouse passes level 1 and fails here.
    $movement = ($this->receive)('10');

    StockMovement::whereKey($movement->id)->update(['warehouse_id' => $this->second->id]);

    $problems = StockLedger::verify();

    expect($problems)->not->toBe([])
        ->and(implode(' ', $problems))->toContain('warehouse');
});

it('checks the number a user actually sees, not only the stored one', function () {
    // Level 3 covers the presentation path - the one that has produced a defect
    // twice already (19200.00000000). Verifying the data but not the method
    // people read it through is not a complete chain.
    ($this->receive)('10');
    ($this->receive)('5', '120', $this->second);

    expect(StockLedger::verify())->toBe([])
        ->and($this->product->fresh()->currentStockQuantity())->toBe('15');
});

it('verifies every business when run cold, the way a console command is', function () {
    // ⚠⚠ THE TRAP, for the third time on this project and the second slice
    // running. A console command has no BusinessContext, every model here is
    // business-scoped, and BusinessScope fails closed on 1 = 0 - so a naive
    // verifier reports "all consistent" while examining NOTHING, which is the
    // worst possible failure for an integrity check.
    $movement = ($this->receive)('10');
    StockBatch::whereKey($movement->batch_id)->update(['current_quantity' => '3.0000']);

    TenantScope::leave();

    $problems = StockLedger::verifyAllBusinesses();

    expect($problems)->not->toBe([])
        ->and(implode(' ', $problems))->toContain('Ledger Truth Co');
});

it('exits non-zero from the console when the chain is broken, and does not self-heal', function () {
    // ⚠ C-LED-1. A verifier that quietly repairs what it finds erases the
    // evidence of whatever caused the drift, and the cause is worth more than
    // the symptom. It reports, it fails loudly, and it leaves the data alone.
    $movement = ($this->receive)('10');
    StockBatch::whereKey($movement->batch_id)->update(['current_quantity' => '3.0000']);

    TenantScope::leave();

    $this->artisan('inventory:verify-ledger')
        ->expectsOutputToContain('consistency problem')
        ->assertExitCode(1);

    // Untouched: the command reported, it did not repair.
    expect((float) StockBatch::withoutGlobalScopes()->find($movement->batch_id)->current_quantity)->toBe(3.0);
});

it('repairs only when explicitly asked, and records that it did', function () {
    $movement = ($this->receive)('10');
    StockBatch::whereKey($movement->batch_id)->update(['current_quantity' => '3.0000']);

    TenantScope::leave();

    $this->artisan('inventory:verify-ledger --rebuild')->assertExitCode(0);

    expect((float) StockBatch::withoutGlobalScopes()->find($movement->batch_id)->current_quantity)->toBe(10.0);

    // ⚠ A rebuild is not a stock edit - it makes a stale cache agree with
    // movements that were always there - but it is still recorded, because a
    // correction nobody can see is indistinguishable from a correction that
    // never happened.
    expect(Activity::where('log_name', 'inventory.ledger')->exists())->toBeTrue();

    TenantScope::leave();
    $this->artisan('inventory:verify-ledger')->assertExitCode(0);
});
