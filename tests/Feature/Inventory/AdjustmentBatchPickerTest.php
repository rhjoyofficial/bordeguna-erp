<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryReasonsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B6, the batch-picker part (24 Oct 2026; R71): "select
 * the specific Batch Number that spoiled. The ERP immediately fetches the
 * exact purchase cost of that specific batch ... not a generic average."
 *
 * Two deliveries of the same seed at two prices, the dearer one spoils: the
 * write-off names that batch, takes the stock out of it alone, and posts the
 * loss at ITS cost. The loss head stays today's (5200, Q14 still open).
 * Posted exactly as the "What happened to the stock?" popup posts it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');

    $this->business = Business::create(['name' => 'Seed Co', 'slug' => 'seed-co-b6', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app()->call([new InventoryReasonsSeeder, 'run']);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    app(BusinessContext::class)->set($this->business->id);
    TenantScope::enter($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->userAs = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->owner = ($this->userAs)('Owner');
    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);
    $this->other = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mirpur', 'code' => 'MIR']);
    $this->mustard = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id,
        'sku' => 'RM-MST-SEED', 'name' => 'Mustard seed', 'type' => ProductType::RawMaterial,
    ]);

    // Supplier A at 100 a kg on the 20th, supplier B at 110 on the 22nd.
    app(RecordStockIn::class)->handle($this->mustard, $this->warehouse, '50', '100', $this->owner, occurredAt: '2026-10-20 09:00:00');
    app(RecordStockIn::class)->handle($this->mustard, $this->warehouse, '50', '110', $this->owner, occurredAt: '2026-10-22 09:00:00');
    $this->batchA = StockBatch::where('unit_cost', '100')->sole();
    $this->batchB = StockBatch::where('unit_cost', '110')->sole();

    $this->damaged = Reason::query()->forContext('inventory.stock_adjustment')->where('name', 'Damaged')->firstOrFail();

    $this->writeOff = fn (array $overrides = []) => ($this->as)($this->owner)
        ->from(route('inventory.stock.index'))
        ->post(route('inventory.stock.adjustments.store'), array_merge([
            'effective_date' => '2026-10-24',
            'reason_id' => $this->damaged->id,
            'product_id' => $this->mustard->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => '10',
            'stock_batch_id' => $this->batchB->id,
        ], $overrides));

    $this->approveAll = function () {
        InventoryAdjustment::query()->get()->each(function (InventoryAdjustment $adjustment) {
            if ($adjustment->approvalRequest?->isPending()) {
                app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->owner, 'Seen the spoiled sacks myself.');
            }
        });
    };

    $this->sumOn = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

it('writes off the chosen batch at its own cost, not the oldest one', function () {
    ($this->writeOff)()->assertSessionHasNoErrors()->assertRedirect(route('inventory.stock.index'));
    ($this->approveAll)();

    $adjustment = InventoryAdjustment::sole();

    expect((int) $adjustment->stock_batch_id)->toBe($this->batchB->id)
        // FIFO would have taken batch A at 100; the named batch B leaves at 110.
        ->and((float) $this->batchA->fresh()->current_quantity)->toBe(50.0)
        ->and((float) $this->batchB->fresh()->current_quantity)->toBe(40.0)
        ->and($adjustment->fresh('movements')->movements->pluck('batch_id')->unique()->values()->all())->toBe([$this->batchB->id])
        ->and((float) $adjustment->movements->sole()->unit_cost)->toBe(110.0);

    // 10 kg at 110 = 1,100 out of stock and onto today's loss head.
    $journal = Journal::where('source_type', $adjustment->getMorphClass())->where('source_id', $adjustment->id)->sole();

    expect($journal->balance()->isZero())->toBeTrue()
        ->and(($this->sumOn)('inventory_write_off'))->toBe(110000);

    // Debits equal credits everywhere, and stock value agrees with the batches.
    expect(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();
});

it('keeps the old way when no batch is chosen: oldest first', function () {
    ($this->writeOff)(['stock_batch_id' => null])->assertSessionHasNoErrors();
    ($this->approveAll)();

    expect((float) $this->batchA->fresh()->current_quantity)->toBe(40.0)
        ->and((float) $this->batchB->fresh()->current_quantity)->toBe(50.0)
        ->and(($this->sumOn)('inventory_write_off'))->toBe(100000);
});

it('refuses more than the chosen batch holds, in a sentence', function () {
    ($this->writeOff)(['quantity' => '60'])->assertSessionHasErrors(['stock_batch_id' => 'Batch '.$this->batchB->batch_number.' holds only 50. Enter that much or less, or choose another batch.']);

    expect(InventoryAdjustment::count())->toBe(0);
});

it('refuses a batch of another warehouse or another product', function () {
    app(RecordStockIn::class)->handle($this->mustard, $this->other, '5', '90', $this->owner, occurredAt: '2026-10-21 09:00:00');
    $elsewhereBatch = StockBatch::where('warehouse_id', $this->other->id)->sole();

    ($this->writeOff)(['stock_batch_id' => $elsewhereBatch->id])->assertSessionHasErrors('stock_batch_id');

    expect(InventoryAdjustment::count())->toBe(0);
});

it('refuses a batch on stock that turned up', function () {
    ($this->writeOff)([
        'reason_id' => Reason::query()->forContext('inventory.stock_adjustment')->where('name', 'Physical count correction')->firstOrFail()->id,
        'direction' => 'increase',
        'unit_cost' => '100',
    ])->assertSessionHasErrors('stock_batch_id');

    expect(InventoryAdjustment::count())->toBe(0);
});

it('refuses a day that has not come yet, as every correction does', function () {
    // The future check and the period lock ride in on EffectiveDate, unchanged.
    ($this->writeOff)(['effective_date' => '2026-11-30'])->assertSessionHasErrors();

    expect(InventoryAdjustment::count())->toBe(0);
});

it('lists the batches to choose from, soonest expiry first, each with its own cost', function () {
    $this->batchA->update(['expiry_date' => '2026-12-31']);
    $this->batchB->update(['expiry_date' => '2026-11-30']);

    $body = ($this->as)($this->owner)
        ->getJson(route('inventory.stock.batch-options', ['product_id' => $this->mustard->id, 'warehouse_id' => $this->warehouse->id]))
        ->assertOk()
        ->json('batches');

    expect(collect($body)->pluck('id')->all())->toBe([$this->batchB->id, $this->batchA->id])
        ->and($body[0])->toMatchArray(['current_quantity' => '50', 'unit_cost' => '110.00', 'expiry_date' => '2026-11-30', 'expired' => false, 'on_hold' => false]);
});

it('does not list batches to somebody who may not raise a correction', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    ($this->as)($nobody)
        ->getJson(route('inventory.stock.batch-options', ['product_id' => $this->mustard->id, 'warehouse_id' => $this->warehouse->id]))
        ->assertForbidden();
});
