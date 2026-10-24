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
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryReasonsSeeder;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B7 (24 Oct 2026; Q7 = A, R103): the Not for sale
 * (quarantine) warehouse. Nothing sells, runs, issues or transfers from it;
 * reusable goods go back by Move back to stock (same batch, same cost, a
 * reason); the rest is written off as Damaged returns.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');

    $this->business = Business::create(['name' => 'Oil Co', 'slug' => 'oil-co-b7', 'fiscal_year_start_month' => 7]);
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

    $l = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $this->shop = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SHP', 'is_default' => true]);
    $this->quarantine = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Damaged / Quarantine', 'code' => 'QRN', 'not_for_sale' => true]);
    $this->oil = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $l->id,
        'sku' => 'FG-OIL-1L', 'name' => 'Mustard oil 1 L', 'type' => ProductType::FinishedGood,
    ]);

    app(RecordStockIn::class)->handle($this->oil, $this->quarantine, '10', '200', $this->owner, batchNumber: 'OIL-A', occurredAt: '2026-10-20 09:00:00');
    $this->damagedBatch = StockBatch::where('warehouse_id', $this->quarantine->id)->sole();

    $this->moveBack = fn (array $overrides = [], ?User $as = null) => ($this->as)($as ?? $this->owner)
        ->from(route('inventory.stock.index'))
        ->post(route('inventory.stock.move-back'), array_merge([
            'effective_date' => '2026-10-24',
            'stock_batch_id' => $this->damagedBatch->id,
            'target_warehouse_id' => $this->shop->id,
            'quantity' => '4',
            'reason_text' => 'Only the outer box was dented',
        ], $overrides));
});

it('seeds one quarantine warehouse per business that keeps stock, once, and never as the first warehouse', function () {
    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_25_260100_warehouses_can_be_not_for_sale.php');

    $other = Business::create(['name' => 'Farm', 'slug' => 'farm-b7', 'fiscal_year_start_month' => 7]);
    $empty = Business::create(['name' => 'Empty', 'slug' => 'empty-b7', 'fiscal_year_start_month' => 7]);
    DB::table('warehouses')->insert(['business_id' => $other->id, 'name' => 'Field store', 'code' => 'QRN', 'is_default' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();
    $migration->up();

    $seeded = DB::table('warehouses')->where('business_id', $other->id)->where('not_for_sale', true)->get();

    expect($seeded)->toHaveCount(1)
        ->and($seeded->first()->name)->toBe('Damaged / Quarantine')
        // QRN was taken in that business.
        ->and($seeded->first()->code)->toBe('QRN2')
        ->and((bool) $seeded->first()->is_default)->toBeFalse()
        // This business already had one flagged: nothing added.
        ->and(DB::table('warehouses')->where('business_id', $this->business->id)->where('not_for_sale', true)->count())->toBe(1)
        ->and(DB::table('warehouses')->where('business_id', $empty->id)->count())->toBe(0);
});

it('never sells, issues or makes from quarantine stock', function () {
    expect(fn () => app(RecordStockOut::class)->handle($this->oil, $this->quarantine, '1', $this->owner, reason: MovementReason::Sale))
        ->toThrow(DomainException::class, 'Damaged / Quarantine is a Not for sale warehouse, so no sale, challan or counter bill can take stock from it.');

    expect(fn () => app(RecordStockOut::class)->handle($this->oil, $this->quarantine, '1', $this->owner, reason: MovementReason::Production))
        ->toThrow(DomainException::class, 'production run');

    expect(fn () => app(ReserveStock::class)->handle($this->oil, $this->quarantine, '1', 'staff purchase', $this->oil, $this->owner))
        ->toThrow(DomainException::class, 'staff purchase or withdrawal');

    expect(fn () => app(RecordProductionRun::class)->prepare($this->quarantine, $this->oil, '1', [], $this->owner, '2026-10-24'))
        ->toThrow(ValidationException::class);

    expect((float) $this->damagedBatch->fresh()->current_quantity)->toBe(10.0);
});

it('refuses a plain transfer out of quarantine, in a sentence', function () {
    ($this->as)($this->owner)->from(route('inventory.stock.index'))->post(route('inventory.stock.transfers.store'), [
        'product_id' => $this->oil->id,
        'warehouse_id' => $this->quarantine->id,
        'target_warehouse_id' => $this->shop->id,
        'quantity' => '1',
        'reason_id' => Reason::query()->forContext('inventory.stock_transfer')->firstOrFail()->id,
    ])->assertSessionHasErrors(['stock' => 'Damaged / Quarantine is a Not for sale warehouse, so no transfer can take stock from it. Move what is reusable back to stock first, or write it off.']);

    expect(StockTransfer::count())->toBe(0);
});

it('moves reusable goods back to stock: same batch number, same cost, same expiry, nothing posted', function () {
    $this->damagedBatch->update(['expiry_date' => '2027-03-31']);

    ($this->moveBack)()->assertSessionHasNoErrors()->assertRedirect(route('inventory.stock.index'));

    $back = StockBatch::where('warehouse_id', $this->shop->id)->sole();
    $transfer = StockTransfer::sole();

    expect((float) $this->damagedBatch->fresh()->current_quantity)->toBe(6.0)
        ->and((float) $back->current_quantity)->toBe(4.0)
        ->and($back->batch_number)->toBe('OIL-A')
        ->and((float) $back->unit_cost)->toBe(200.0)
        ->and($back->expiry_date?->toDateString())->toBe('2027-03-31')
        ->and((int) $back->source_batch_id)->toBe($this->damagedBatch->id)
        ->and($transfer->status)->toBe(TransferStatus::Received)
        ->and($transfer->note)->toBe('Back to sellable stock: Only the outer box was dented')
        ->and($transfer->reason->name)->toBe('Back to sellable stock')
        ->and(Journal::count())->toBe(0)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();

    // And now it sells from the shop like any other stock.
    $sold = app(RecordStockOut::class)->handle($this->oil, $this->shop, '1', $this->owner, reason: MovementReason::Sale);
    expect($sold->sole()->batch_id)->toBe($back->id);
});

it('refuses a move back that is not one', function (array $overrides, string $key) {
    $selling = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Second quarantine', 'code' => 'QR2', 'not_for_sale' => true]);
    app(RecordStockIn::class)->handle($this->oil, $this->shop, '3', '180', $this->owner, batchNumber: 'OIL-S', occurredAt: '2026-10-20 09:00:00');
    $shopBatch = StockBatch::where('batch_number', 'OIL-S')->sole();

    $overrides = array_map(fn ($v) => match ($v) {
        'SECOND_QUARANTINE' => $selling->id,
        'SHOP_BATCH' => $shopBatch->id,
        default => $v,
    }, $overrides);

    ($this->moveBack)($overrides)->assertSessionHasErrors($key);

    expect(StockTransfer::count())->toBe(0)
        ->and((float) $this->damagedBatch->fresh()->current_quantity)->toBe(10.0);
})->with([
    'into another Not for sale warehouse' => [['target_warehouse_id' => 'SECOND_QUARANTINE'], 'target_warehouse_id'],
    'from a selling warehouse' => [['stock_batch_id' => 'SHOP_BATCH', 'target_warehouse_id' => 'SECOND_QUARANTINE'], 'stock_batch_id'],
    'more than the batch holds' => [['quantity' => '11'], 'quantity'],
    'without a real reason' => [['reason_text' => 'ok'], 'reason_text'],
    'a day before the batch existed' => [['effective_date' => '2026-10-19'], 'stock'],
]);

it('lets only the hands that move stock move it back', function () {
    ($this->moveBack)([], ($this->userAs)('Staff'))->assertForbidden();

    expect(StockTransfer::count())->toBe(0);
});

it('writes off what is not reusable as Damaged returns, at the batch cost, on its own head', function () {
    ($this->as)($this->owner)->from(route('inventory.stock.index'))->post(route('inventory.stock.adjustments.store'), [
        'effective_date' => '2026-10-24',
        'reason_id' => Reason::query()->forContext('inventory.stock_adjustment')->where('name', 'Damaged returns')->firstOrFail()->id,
        'product_id' => $this->oil->id,
        'warehouse_id' => $this->quarantine->id,
        'direction' => 'decrease',
        'quantity' => '6',
        'stock_batch_id' => $this->damagedBatch->id,
    ])->assertSessionHasNoErrors();

    InventoryAdjustment::query()->get()->each(fn (InventoryAdjustment $a) => $a->approvalRequest?->isPending()
        ? app(ApproveInventoryAdjustment::class)->handle($a, $this->owner, 'Seen the broken bottles.') : null);

    expect((float) $this->damagedBatch->fresh()->current_quantity)->toBe(4.0)
        ->and((int) JournalLine::where('account_id', Account::bySlug('inventory_loss_damaged_returns')->id)->sum('amount_minor'))->toBe(120000)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();
});

it('keeps the quarantine store off the default and marks it on the screens', function () {
    ($this->as)($this->owner)->patch(route('inventory.warehouses.make-default', $this->quarantine))->assertSessionHas('error');
    expect($this->quarantine->fresh()->is_default)->toBeFalse();

    ($this->as)($this->owner)->put(route('inventory.warehouses.update', $this->shop), ['name' => 'Shop', 'code' => 'SHP', 'is_active' => true, 'not_for_sale' => true])->assertSessionHas('error');
    expect($this->shop->fresh()->not_for_sale)->toBeFalse();

    ($this->as)($this->owner)->post(route('inventory.warehouses.store'), ['name' => 'Spoilt room', 'code' => 'SPL', 'not_for_sale' => true])->assertSessionHasNoErrors();
    expect(Warehouse::where('code', 'SPL')->sole()->not_for_sale)->toBeTrue();

    ($this->as)($this->owner)->get(route('inventory.stock.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('warehouses', fn ($list) => collect($list)->firstWhere('name', 'Damaged / Quarantine')['not_for_sale'] === true)
            ->where('stock.data.0.warehouse.not_for_sale', true));

    // The production run screen never offers it.
    ($this->as)($this->owner)->get(route('inventory.production-runs.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('warehouses', fn ($list) => collect($list)->pluck('name')->all() === ['Shop']));
});
