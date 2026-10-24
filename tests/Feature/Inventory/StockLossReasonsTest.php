<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
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
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\StockLossHeads;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B6, loss reasons (24 Oct 2026; Q14 = A, R72): Expired,
 * Broken/Damaged, Spoiled, Shrinkage/Lost and Damaged returns, each with its
 * own head beside 5200, the loss at the chosen batch's cost, and the losses
 * report by reason. Plus the migration that brings a live ledger over without
 * moving a single journal already posted.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 12:00:00');

    $this->business = Business::create(['name' => 'Seed Co', 'slug' => 'seed-co-b6-loss', 'fiscal_year_start_month' => 7]);
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

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');
    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);
    $this->mustard = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id,
        'sku' => 'RM-MST-SEED', 'name' => 'Mustard seed', 'type' => ProductType::RawMaterial,
    ]);

    app(RecordStockIn::class)->handle($this->mustard, $this->warehouse, '100', '100', $this->owner, occurredAt: '2026-10-20 09:00:00');
    app(RecordStockIn::class)->handle($this->mustard, $this->warehouse, '100', '110', $this->owner, occurredAt: '2026-10-22 09:00:00');
    $this->dearer = StockBatch::where('unit_cost', '110')->sole();

    $this->reason = fn (string $name) => Reason::query()->forContext('inventory.stock_adjustment')->where('name', $name)->firstOrFail();

    $this->writeOff = fn (string $reason, string $quantity = '2', ?int $batchId = null) => ($this->as)($this->owner)
        ->from(route('inventory.stock.index'))
        ->post(route('inventory.stock.adjustments.store'), [
            'effective_date' => '2026-10-24',
            'reason_id' => ($this->reason)($reason)->id,
            'product_id' => $this->mustard->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => 'decrease',
            'quantity' => $quantity,
            'stock_batch_id' => $batchId ?? $this->dearer->id,
        ]);

    $this->approveAll = function () {
        InventoryAdjustment::query()->get()->each(function (InventoryAdjustment $adjustment) {
            if ($adjustment->approvalRequest?->isPending()) {
                app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->owner, 'Seen it on the shelf myself.');
            }
        });
    };

    $this->sumOn = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

it('plants the five loss heads on the first free codes after 5200, under Cost of Sales, postable', function () {
    $heads = collect(array_keys(StockLossHeads::HEADS))->map(fn (string $slug) => Account::bySlug($slug));

    expect($heads->pluck('code')->all())->toBe(['5201', '5202', '5203', '5204', '5205'])
        ->and($heads->pluck('name')->all())->toBe([
            'Stock Loss - Expired', 'Stock Loss - Broken/Damaged', 'Stock Loss - Spoiled', 'Stock Loss - Shrinkage/Lost', 'Stock Loss - Damaged Returns',
        ])
        ->and($heads->every(fn (Account $a) => $a->is_postable && $a->type->value === 'expense' && $a->parent?->code === '5000'))->toBeTrue();

    // Seeding again plants nothing new.
    $this->seed(InventoryAccountMappingSeeder::class);
    expect(Account::where('code', 'like', '520_')->count())->toBe(6);
});

it("offers Joy's five loss reasons on the stock popup, each naming its head", function () {
    ($this->as)($this->owner)->get(route('inventory.stock.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('adjustment_reasons', function ($reasons) {
            $byName = collect($reasons)->keyBy('name');

            return $byName['Expired']['loss_head'] === '5201 - Stock Loss - Expired'
                && $byName['Broken/Damaged']['loss_head'] === '5202 - Stock Loss - Broken/Damaged'
                && $byName['Spoiled']['type'] === 'spoiled' && $byName['Spoiled']['only_reduces'] === true
                && $byName['Shrinkage/Lost']['loss_head'] === '5204 - Stock Loss - Shrinkage/Lost'
                && $byName['Damaged returns']['type'] === 'damaged_return' && $byName['Damaged returns']['loss_head'] === '5205 - Stock Loss - Damaged Returns';
        }));
});

it('posts each reason to its own head at the chosen batch cost', function (string $reason, string $slug) {
    ($this->writeOff)($reason)->assertSessionHasNoErrors();
    ($this->approveAll)();

    // 2 kg of the 110 batch = 220.00 on that reason's head, nothing on 5200.
    expect(($this->sumOn)($slug))->toBe(22000)
        ->and(($this->sumOn)('inventory_write_off'))->toBe(0)
        ->and(($this->sumOn)('stock_on_hand'))->toBe(-22000)
        ->and((float) $this->dearer->fresh()->current_quantity)->toBe(98.0)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();
})->with([
    'Expired' => ['Expired', 'inventory_loss_expired'],
    'Broken/Damaged' => ['Broken/Damaged', 'inventory_loss_damaged'],
    'Spoiled' => ['Spoiled', 'inventory_loss_spoiled'],
    'Shrinkage/Lost' => ['Shrinkage/Lost', 'inventory_loss_shrinkage'],
    'Damaged returns' => ['Damaged returns', 'inventory_loss_damaged_returns'],
]);

it('keeps count corrections and everything else on 5200', function () {
    ($this->writeOff)('Physical count correction', '1')->assertSessionHasNoErrors();
    ($this->approveAll)();

    expect(($this->sumOn)('inventory_write_off'))->toBe(11000);
});

it('reports losses by reason for the period, with the head of each', function () {
    ($this->writeOff)('Spoiled', '2')->assertSessionHasNoErrors();
    ($this->writeOff)('Spoiled', '1')->assertSessionHasNoErrors();
    ($this->writeOff)('Expired', '3')->assertSessionHasNoErrors();
    ($this->approveAll)();

    ($this->as)($this->owner)->get(route('inventory.stock.adjustments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('losses.from', '2026-10-01')
            ->where('losses.to', '2026-10-24')
            ->where('losses.total', '660.00')
            ->where('losses.rows', fn ($rows) => collect($rows)->map(fn ($r) => [$r['label'], $r['head'], $r['count'], $r['amount']])->all() === [
                ['Expired', '5201 - Stock Loss - Expired', 1, '330.00'],
                ['Spoiled', '5203 - Stock Loss - Spoiled', 2, '330.00'],
            ]));

    // Another period shows nothing.
    ($this->as)($this->owner)->get(route('inventory.stock.adjustments.index', ['loss_from' => '2026-09-01', 'loss_to' => '2026-09-30']))
        ->assertInertia(fn (Assert $page) => $page->where('losses.rows', [])->where('losses.total', '0.00'));
});

it('brings a live ledger over: history stays on 5200, new losses go to their heads from the day it ran', function () {
    // The state before B6: no loss heads, the old reason names, every loss
    // type mapped to 5200 since 2000, and one damaged write-off already posted.
    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_25_260000_stock_loss_reasons_have_their_own_heads.php');
    $writeOff = Account::bySlug('inventory_write_off');

    DB::table('account_mappings')->where('key', 'like', 'inventory.adjustment.%')->where('key', '!=', 'inventory.adjustment.default')->delete();
    foreach (['damaged', 'expired', 'lost_or_stolen'] as $type) {
        AccountMapping::assign("inventory.adjustment.{$type}", $writeOff, Carbon::parse('2000-01-01'), 'Seeded default');
    }
    DB::table('accounts')->whereIn('system_slug', array_keys(StockLossHeads::HEADS))->delete();
    Reason::query()->forContext('inventory.stock_adjustment')->whereIn('name', ['Spoiled', 'Damaged returns'])->delete();
    ($this->reason)('Broken/Damaged')->update(['name' => 'Damaged']);
    ($this->reason)('Shrinkage/Lost')->update(['name' => 'Theft or loss']);

    Carbon::setTestNow('2026-10-23 12:00:00');
    ($this->as)($this->owner)->from(route('inventory.stock.index'))->post(route('inventory.stock.adjustments.store'), [
        'effective_date' => '2026-10-23', 'reason_id' => ($this->reason)('Damaged')->id, 'product_id' => $this->mustard->id,
        'warehouse_id' => $this->warehouse->id, 'direction' => 'decrease', 'quantity' => '1', 'stock_batch_id' => $this->dearer->id,
    ])->assertSessionHasNoErrors();
    ($this->approveAll)();
    expect(($this->sumOn)('inventory_write_off'))->toBe(11000);

    Carbon::setTestNow('2026-10-24 12:00:00');
    $migration->up();
    $migration->up(); // idempotent

    $damagedHead = Account::bySlug('inventory_loss_damaged');

    expect($damagedHead)->not->toBeNull()
        // Yesterday still resolves where it posted; today goes to the new head.
        ->and(AccountMapping::resolve('inventory.adjustment.damaged', Carbon::parse('2026-10-23'))->id)->toBe($writeOff->id)
        ->and(AccountMapping::resolve('inventory.adjustment.damaged', Carbon::parse('2026-10-24'))->id)->toBe($damagedHead->id)
        ->and(AccountMapping::resolve('inventory.adjustment.spoiled', Carbon::parse('2026-10-24'))->system_slug)->toBe('inventory_loss_spoiled')
        ->and(AccountMapping::query()->where('key', 'inventory.adjustment.damaged')->count())->toBe(2)
        // The reasons carry Joy's words now, the old adjustment keeps its row.
        ->and(Reason::query()->forContext('inventory.stock_adjustment')->whereIn('name', ['Expired', 'Broken/Damaged', 'Spoiled', 'Shrinkage/Lost', 'Damaged returns'])->count())->toBe(5)
        ->and(Reason::query()->forContext('inventory.stock_adjustment')->whereIn('name', ['Damaged', 'Theft or loss'])->count())->toBe(0)
        ->and(InventoryAdjustment::sole()->reason->name)->toBe('Broken/Damaged');

    // A new write-off for the same reason lands on the new head; the old
    // journal is untouched.
    ($this->writeOff)('Broken/Damaged', '1')->assertSessionHasNoErrors();
    ($this->approveAll)();

    expect(($this->sumOn)('inventory_write_off'))->toBe(11000)
        ->and(($this->sumOn)('inventory_loss_damaged'))->toBe(11000)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();
});

it('points the old rows in place when nothing was ever posted to 5200', function () {
    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_25_260000_stock_loss_reasons_have_their_own_heads.php');
    $writeOff = Account::bySlug('inventory_write_off');

    DB::table('account_mappings')->where('key', 'inventory.adjustment.expired')->delete();
    AccountMapping::assign('inventory.adjustment.expired', $writeOff, Carbon::parse('2000-01-01'), 'Seeded default');

    $migration->up();

    expect(AccountMapping::query()->where('key', 'inventory.adjustment.expired')->count())->toBe(1)
        ->and(AccountMapping::resolve('inventory.adjustment.expired', Carbon::parse('2001-01-01'))->system_slug)->toBe('inventory_loss_expired');
});

it('leaves a loss key alone that an accountant pointed somewhere else', function () {
    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_25_260000_stock_loss_reasons_have_their_own_heads.php');
    $elsewhere = Account::where('code', '5300')->sole();

    DB::table('account_mappings')->where('key', 'inventory.adjustment.expired')->delete();
    AccountMapping::assign('inventory.adjustment.expired', $elsewhere, Carbon::parse('2000-01-01'), 'Accountant');

    $migration->up();

    expect(AccountMapping::resolve('inventory.adjustment.expired', Carbon::parse('2026-10-24'))->id)->toBe($elsewhere->id);
});

it('labels the adjustment types in Joy words', function () {
    expect(InventoryAdjustmentType::Damaged->label())->toBe('Broken/Damaged')
        ->and(InventoryAdjustmentType::LostOrStolen->label())->toBe('Shrinkage/Lost')
        ->and(InventoryAdjustmentType::Spoiled->label())->toBe('Spoiled')
        ->and(InventoryAdjustmentType::DamagedReturn->label())->toBe('Damaged returns');
});
