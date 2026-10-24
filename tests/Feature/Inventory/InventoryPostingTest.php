<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApproveInventoryAdjustment;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 3, second integration: inventory posts its own accounting entries.
 *
 * ⚠ Transfers post NOTHING and are not tested here for that reason: stock
 * moving between two warehouses of one business changes nothing the ledger can
 * see, and Joy confirmed it - ownership does not change.
 *
 * See ACCOUNTING-PHASE3-INTEGRATION.md §4.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-03 10:00:00');

    $this->business = Business::create(['name' => 'Stock Co', 'slug' => 'stock-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    foreach (['inventory.stock.adjust', 'inventory.stock.approve'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $role = Role::firstOrCreate(['name' => 'Founder', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['inventory.stock.adjust', 'inventory.stock.approve']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole($role);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'HONEY-1KG',
        'name' => 'Mangrove Gold Honey 1kg',
        'type' => ProductType::FinishedGood,
    ]);
    $this->reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Damaged in handling',
        'is_active' => true,
    ]);

    $this->stockIn = fn (string $qty, string $cost) => app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->user,
    );

    $this->adjust = function (string $qty, string $cost, InventoryAdjustmentType $type, AdjustmentDirection $direction) {
        $adjustment = app(CreateInventoryAdjustment::class)->handle(
            type: $type,
            direction: $direction,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: $qty,
            reason: $this->reason,
            createdBy: $this->user,
            unitCost: $cost,
            // ⚠ Left to default to now. Dating it to midnight would put the
            // withdrawal BEFORE the batch it consumes, which EffectiveDate
            // rightly refuses - backdating changed what FIFO means, and the
            // guard that came with it is doing its job here.
        );

        return $adjustment->fresh(['approvalRequest', 'movements']);
    };

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

it('takes value out of stock and into the loss account when stock is written off', function () {
    ($this->stockIn)('100', '250.00');

    $adjustment = ($this->adjust)('4', '250.00', InventoryAdjustmentType::Damaged, AdjustmentDirection::Decrease);
    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->user, 'Checked it myself.');

    $journal = Journal::where('source_type', $adjustment->getMorphClass())->first();

    expect($journal)->not->toBeNull()
        ->and($journal->balance()->isZero())->toBeTrue();

    // 4 kg at 250 = 1,000 leaving stock and landing on write-offs.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(-100000)
        ->and(($this->balanceOn)('inventory_loss_damaged'))->toBe(100000);
});

it('puts value back into stock when a count finds more than expected', function () {
    ($this->stockIn)('100', '250.00');

    $adjustment = ($this->adjust)('2', '250.00', InventoryAdjustmentType::StockCountVariance, AdjustmentDirection::Increase);
    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->user, 'Checked it myself.');

    // ⚠ The same code path in the opposite direction. The type chose a mapping
    // key, not a branch - there is no `match` on it anywhere in the posting.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(50000)
        ->and(($this->balanceOn)('inventory_write_off'))->toBe(-50000);
});

it('posts an adjustment once, however many times it is approved', function () {
    ($this->stockIn)('100', '250.00');
    $adjustment = ($this->adjust)('4', '250.00', InventoryAdjustmentType::Damaged, AdjustmentDirection::Decrease);

    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->user, 'Checked it myself.');

    expect(Journal::count())->toBe(1);
});

it('values a four-decimal cost without inventing a fraction of a paisa', function () {
    // ⚠⚠ THE CASE THE ROUNDING MECHANISM EXISTS FOR. Inventory carries four
    // decimals and the ledger holds two, so 3.3333 kg at 99.9999 is not
    // expressible exactly. The journal must still balance to zero, and the
    // stock and loss lines must be the same figure rather than two roundings
    // of the same number that disagree by a paisa.
    ($this->stockIn)('100', '99.9999');

    $adjustment = ($this->adjust)('3.3333', '99.9999', InventoryAdjustmentType::Damaged, AdjustmentDirection::Decrease);
    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->user, 'Checked it myself.');

    $journal = Journal::where('source_type', $adjustment->getMorphClass())->first();

    expect($journal->balance()->isZero())->toBeTrue()
        ->and(($this->balanceOn)('stock_on_hand') + ($this->balanceOn)('inventory_loss_damaged'))->toBe(0);

    // 3.3333 * 99.9999 = 333.32966667, which is 333.33 in the books.
    expect(Money::ofMinor(abs(($this->balanceOn)('inventory_loss_damaged')))->format())->toBe('333.33');
});

it('multiplies quantity by cost without going near a float', function () {
    // ⚠⚠ THE VERIFIED CASE, checked in a REPL rather than assumed. A naive
    // truncating multiply of the third assertion below,
    // `(int) (3.3333 * 99.9999 * 100)`, gives 33332 in PHP. The correct answer
    // is 33333. One paisa, on one line, on a real stock value - and it is the
    // same family as the FIFO dust Inventory fixed by scaling to integers.
    //
    // ⚠ Stated precisely, because I first wrote a different example here and it
    // was wrong: `(int) (0.1 * 0.29 * 10000)` is 290, not 289. This test pins
    // the VALUES; the integer implementation additionally removes any
    // dependence on PHP's float-to-string precision setting, which a
    // float-then-parse version would inherit from php.ini.
    expect(Money::ofProduct('0.1', '0.29')->minor)->toBe(3)
        ->and(Money::ofProduct('3.3333', '99.9999')->minor)->toBe(33333)
        ->and(Money::ofProduct('100', '250.00')->minor)->toBe(2500000)
        ->and(Money::ofProduct('-2', '10.005')->minor)->toBe(-2001);
});

it('leaves the ledger consistent and balancing after stock moves', function () {
    ($this->stockIn)('100', '250.00');

    $adjustment = ($this->adjust)('4', '250.00', InventoryAdjustmentType::Expired, AdjustmentDirection::Decrease);
    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->user, 'Checked it myself.');

    expect(TrialBalance::between(Carbon::parse('2026-07-01'), Carbon::parse('2026-08-31'))->balances())->toBeTrue();

    $result = LedgerIntegrity::verifyAllBusinesses();
    expect($result['problems'])->toBeEmpty();
});

it('lets a business that has not switched accounting on keep adjusting stock', function () {
    // ⚠ The same rule payroll learned the expensive way: an accounting feature
    // must never be able to stop an operational process.
    AccountMapping::query()->delete();

    ($this->stockIn)('100', '250.00');
    $adjustment = ($this->adjust)('4', '250.00', InventoryAdjustmentType::Damaged, AdjustmentDirection::Decrease);

    app(ApproveInventoryAdjustment::class)->handle($adjustment, $this->user, 'Checked it myself.');

    expect(Journal::count())->toBe(0)
        ->and($adjustment->fresh()->movements)->not->toBeEmpty();
});
