<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CostOfGoodsSold;
use App\Modules\Accounting\Support\InventoryValuation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\Actions\ProductFamilies;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Inventory valuation and cost of goods sold.
 *
 * ⚠⚠ EVERY ASSERTION IS ECONOMIC, per Joy's rule: it is not enough that the
 * journal balances. These tests check that the VALUATION, the COGS, the margin
 * and the GL all say the same thing about the same goods - because a balanced
 * trial balance does not prove the accounting is correct, and this project has
 * the scars to prove it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-30 10:00:00');

    $this->business = Business::create(['name' => 'Valuation Co', 'slug' => 'valuation-co']);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);

    $this->brand = Brand::create([
        'business_id' => $this->business->id,
        'code' => 'BNC',
        'name' => 'Bionic',
        'slug' => 'bionic',
        'is_active' => true,
    ]);

    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'brand_id' => $this->brand->id,
        'sku' => 'JAR-500',
        'name' => 'Honey jar 500g',
        'type' => ProductType::FinishedGood,
    ]);

    $this->supplier = Supplier::create([
        'business_id' => $this->business->id,
        'name' => 'Dhaka Glassware',
        'code' => 'SUP-001',
    ]);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Till',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '60',
        'effective_from' => '2026-01-01',
    ]);

    // Receive stock on a STATED date, so as-at questions have a real answer.
    $this->receive = function (string $quantity, string $unitCost, string $on) {
        Carbon::setTestNow($on.' 10:00:00');

        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
            createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);
        $order = $order->fresh(['lines', 'approvalRequest']);

        app(RecordGoodsReceipt::class)->handle(
            $order,
            [['line' => $order->lines->first(), 'quantity' => $quantity]],
            $this->user,
        );

        Carbon::setTestNow('2026-09-30 10:00:00');
    };

    $this->sell = function (string $quantity, string $on) {
        Carbon::setTestNow($on.' 10:00:00');

        app(RecordCounterSale::class)->handle(
            customer: Customer::walkIn(),
            warehouse: $this->warehouse,
            lines: [['product' => $this->product, 'quantity' => $quantity]],
            createdBy: $this->user,
            moneyAccount: $this->cash,
        );

        Carbon::setTestNow('2026-09-30 10:00:00');
    };

    $this->allowAdjustments = function () {
        Permission::findOrCreate('inventory.stock.adjust', 'web');
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
        $role = Role::firstOrCreate(['name' => 'Inventory Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);
        $role->givePermissionTo('inventory.stock.adjust');
        $this->user->assignRole($role);
        InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000000']);
    };

    $this->stockOnHandInLedger = function (string $asAt) {
        $accountId = Account::bySlug('stock_on_hand')->id;

        return (int) DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->whereDate('journals.journal_date', '<=', $asAt)
            ->sum('journal_lines.amount_minor');
    };
});

afterEach(fn () => Carbon::setTestNow());

it('values stock as at a date and ties exactly to the ledger', function () {
    ($this->receive)('40', '25', '2026-09-05');

    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    // 40 at 25 = 1,000, and the ledger must agree to the paisa.
    expect($valuation->total()->format())->toBe('1,000.00')
        ->and($valuation->tiesOut())->toBeTrue()
        ->and($valuation->difference()->minor)->toBe(0)
        ->and(($this->stockOnHandInLedger)('2026-09-30'))->toBe(100000);
});

it('EXCLUDES a receipt that had not happened yet on the as-at date', function () {
    // ⚠⚠ The defect the whole class exists to avoid. A `SUM(current_quantity *
    // unit_cost)` cannot express this question at all, and would report the
    // September stock against an August date.
    ($this->receive)('40', '25', '2026-09-05');
    ($this->receive)('10', '30', '2026-09-20');

    $early = InventoryValuation::asAt(Carbon::parse('2026-09-10'));
    $late = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    expect($early->total()->format())->toBe('1,000.00')
        ->and($late->total()->format())->toBe('1,300.00');

    // And each ties to the ledger AS AT ITS OWN DATE.
    expect($early->tiesOut())->toBeTrue()
        ->and($late->tiesOut())->toBeTrue();
});

it('values a layer at the cost it carried THAT DAY, not the cost it carries now', function () {
    // C-IVL-2. Received at 25, revalued to 30 on the 20th. On the 10th it is
    // still worth 25 - and the ledger had not received the revaluation posting
    // on the 10th either, which is why both sides move together.
    ($this->receive)('40', '25', '2026-09-05');
    ($this->allowAdjustments)();

    Carbon::setTestNow('2026-09-20 10:00:00');
    app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Revaluation,
        direction: AdjustmentDirection::Increase,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0',
        reason: Reason::create([
            'business_id' => $this->business->id,
            'context' => 'inventory.stock_adjustment',
            'name' => 'Landed cost',
            'is_active' => true,
        ]),
        createdBy: $this->user->fresh(),
        batch: StockBatch::query()->firstOrFail(),
        newUnitCost: '30',
    );
    Carbon::setTestNow('2026-09-30 10:00:00');

    $before = InventoryValuation::asAt(Carbon::parse('2026-09-10'));
    $after = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    expect($before->total()->format())->toBe('1,000.00')
        ->and($after->total()->format())->toBe('1,200.00')
        // ⚠ Both tie out. This is the proof that summing dated movements IS the
        // historical-cost replay, with no second mechanism needed.
        ->and($before->tiesOut())->toBeTrue()
        ->and($after->tiesOut())->toBeTrue();

    // The derived unit cost follows, without ever reading the batch's column.
    expect($after->rows->first()->unitCost())->toBe('30.0000')
        ->and($before->rows->first()->unitCost())->toBe('25.0000');
});

it('reduces the valuation by exactly what a sale took out', function () {
    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('15', '2026-09-10');

    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    // 25 left at 25 = 625, and the ledger agrees.
    expect($valuation->total()->format())->toBe('625.00')
        ->and($valuation->tiesOut())->toBeTrue()
        ->and((float) $valuation->rows->first()->quantity)->toBe(25.0);
});

it('groups by brand without a single schema change', function () {
    // C-IVL-7, Joy's A6.
    ($this->receive)('40', '25', '2026-09-05');

    $byBrand = InventoryValuation::asAt(Carbon::parse('2026-09-30'))->byBrand();

    expect($byBrand)->toHaveCount(1)
        ->and($byBrand->first()['brand'])->toBe('Bionic')
        ->and($byBrand->first()['value']->format())->toBe('1,000.00');
});

it('surfaces negative stock instead of flooring it at zero', function () {
    // C-IVL-3. Corruption injected directly, because the actions refuse it -
    // which is itself why the report can be trusted to find it.
    ($this->receive)('40', '25', '2026-09-05');

    DB::table('stock_movements')->where('direction', 'in')->update(['quantity' => '-5.0000']);

    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    expect($valuation->anomalies())->toHaveCount(1)
        ->and($valuation->anomalies()->first()->anomaly())->toContain('Negative quantity')
        // ⚠ And it must NOT tie out. A report that hid this to look tidy would
        // be worse than no report.
        ->and($valuation->tiesOut())->toBeFalse();
});

it('surfaces value stranded on an empty layer', function () {
    // ⚠ The real shape of this defect: a layer is fully consumed, and a
    // REVALUATION still carries value against it. Zeroing a receipt's quantity
    // would zero its value too - value is quantity x cost there - so that would
    // prove nothing. Only a `value_delta` can hold money with no units behind
    // it, which is exactly why revaluations were the seam that broke.
    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('40', '2026-09-10');

    DB::table('stock_movements')->insert([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'warehouse_id' => $this->warehouse->id,
        'batch_id' => StockBatch::query()->value('id'),
        'direction' => 'in',
        'reason' => 'adjustment',
        'quantity' => '0.0000',
        'unit_cost' => '25.0000',
        'value_delta' => '120.0000',
        'performed_by' => $this->user->id,
        'occurred_at' => '2026-09-15 10:00:00',
        'created_at' => '2026-09-15 10:00:00',
    ]);

    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    expect($valuation->anomalies())->toHaveCount(1)
        ->and($valuation->anomalies()->first()->anomaly())->toContain('stranded')
        ->and($valuation->anomalies()->first()->unitCost())->toBeNull()
        ->and($valuation->total()->format())->toBe('120.00')
        // ⚠ And it does NOT tie out, because no journal backs this movement.
        // A report that hid the row to look tidy would also hide the break.
        ->and($valuation->tiesOut())->toBeFalse();
});

it('drops a fully consumed layer but keeps one that still holds value', function () {
    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('40', '2026-09-10');

    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    expect($valuation->rows)->toBeEmpty()
        ->and($valuation->total()->format())->toBe('0.00')
        ->and($valuation->tiesOut())->toBeTrue();
});

// ─── Cost of goods sold ─────────────────────────────────────────────────────

it('reports COGS for a period and ties it to the COGS account', function () {
    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('15', '2026-09-10');

    $cogs = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    // 15 at 25 = 375.
    expect($cogs->totalCost()->format())->toBe('375.00')
        ->and($cogs->tiesOut())->toBeTrue()
        ->and($cogs->difference()->minor)->toBe(0);
});

it('reports revenue, gross profit and margin, all economically correct', function () {
    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('15', '2026-09-10');

    $cogs = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    // 15 sold at 60 = 900 revenue, cost 375, profit 525, margin 58.3%.
    expect($cogs->totalRevenue()->format())->toBe('900.00')
        ->and($cogs->totalCost()->format())->toBe('375.00')
        ->and($cogs->grossProfit()->format())->toBe('525.00')
        ->and($cogs->rows->first()->marginPercent())->toBe('58.3');
});

it('EXCLUDES a sale outside the period on both ends', function () {
    ($this->receive)('100', '25', '2026-08-01');
    ($this->sell)('10', '2026-08-20');
    ($this->sell)('15', '2026-09-10');
    ($this->sell)('20', '2026-10-05');

    $september = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    // Only the September sale: 15 at 25 = 375.
    expect($september->totalCost()->format())->toBe('375.00')
        // ⚠⚠ And it ties to the MOVEMENT in the period, not the running
        // balance. Comparing one month of documents with an expense account's
        // lifetime balance would disagree by all of history.
        ->and($september->tiesOut())->toBeTrue();
});

it('groups COGS and gross profit by brand', function () {
    // Joy's A6, the part he actually asked for: revenue, COGS and gross profit
    // by brand.
    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('15', '2026-09-10');

    $byBrand = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->byBrand();

    expect($byBrand)->toHaveCount(1)
        ->and($byBrand->first()['brand'])->toBe('Bionic')
        ->and($byBrand->first()['cost']->format())->toBe('375.00')
        ->and($byBrand->first()['revenue']->format())->toBe('900.00')
        ->and($byBrand->first()['gross_profit']->format())->toBe('525.00');
});

it('reports no margin rather than a zero margin when nothing was sold', function () {
    // ⚠ Until product_prices is loaded this is EVERY product, so a displayed
    // "0.0%" would be the normal state and would teach people to ignore it.
    ($this->receive)('40', '25', '2026-09-05');

    $cogs = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    expect($cogs->rows)->toBeEmpty()
        ->and($cogs->totalCost()->format())->toBe('0.00');
});

it('agrees with the valuation: what came in, less what COGS took out', function () {
    // ⚠⚠ THE CROSS-CHECK THAT MATTERS. Two independent engines - one over stock
    // movements, one over sales documents - must describe the same goods.
    // Received 1,000, COGS 375, so 625 must remain. If these two ever disagree,
    // one of them is wrong and the trial balance will not say which.
    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('15', '2026-09-10');

    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));
    $cogs = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    $received = 100000; // 40 x 25 in minor units

    expect($valuation->total()->minor + $cogs->totalCost()->minor)->toBe($received)
        ->and($valuation->tiesOut())->toBeTrue()
        ->and($cogs->tiesOut())->toBeTrue();
});

// ─── Reachability ───────────────────────────────────────────────────────────
// ⚠⚠ THE RECURRING DEFECT CLASS ON THIS PROJECT: an engine built and tested
// with no route, no screen, or no NAV LINK. All three are checked.

it('reaches both screens and shows the figures the engines computed', function () {
    Permission::findOrCreate('accounting.view', 'web');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $role = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo('accounting.view');

    $reader = User::factory()->create();
    $reader->businesses()->attach($this->business->id);
    $reader->assignRole($role);

    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('15', '2026-09-10');

    $this->actingAs($reader)
        ->get('/accounting/inventory-valuation?as_at=2026-09-30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/inventory-valuation/index')
            ->where('total', '625.00')
            ->where('control.ties_out', true)
            ->where('by_brand.0.brand', 'Bionic')
            ->where('anomaly_count', 0)
        );

    $this->actingAs($reader)
        ->get('/accounting/cost-of-goods-sold?from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('accounting/cost-of-goods-sold/index')
            ->where('totals.cost', '375.00')
            ->where('totals.revenue', '900.00')
            ->where('totals.gross_profit', '525.00')
            ->where('control.ties_out', true)
        );
});

it('keeps both screens away from a user who cannot view accounting', function () {
    Permission::findOrCreate('accounting.view', 'web');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    $outsider->assignRole(Role::firstOrCreate([
        'name' => 'Outsider', 'business_id' => $this->business->id, 'guard_name' => 'web',
    ]));

    $this->actingAs($outsider)->get('/accounting/inventory-valuation')->assertForbidden();
    $this->actingAs($outsider)->get('/accounting/cost-of-goods-sold')->assertForbidden();
});

it('links both screens in the sidebar, because a route nobody can find is not shipped', function () {
    // Round 3: tabs of Accounts > Reports (accounts-sections.ts).
    $sections = file_get_contents(base_path('resources/js/components/accounting/accounts-sections.ts'));

    expect($sections)->toContain("url: '/accounting/inventory-valuation', can: view")
        ->and($sections)->toContain("url: '/accounting/cost-of-goods-sold', can: view");
});

it('groups a family\'s sizes into one row on both reports when asked (B22), and only then', function () {
    Permission::findOrCreate('accounting.view', 'web');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $role = Role::firstOrCreate(['name' => 'Reader', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo('accounting.view');
    $reader = User::factory()->create();
    $reader->businesses()->attach($this->business->id);
    $reader->assignRole($role);

    $family = app(ProductFamilies::class)->make($this->product, ['name' => 'Honey jar', 'sku' => 'JAR', 'value' => '500 g']);
    app(ProductFamilies::class)->addSize($family, ['value' => '1 kg', 'sku' => 'JAR-1KG', 'name' => 'Honey jar 1 kg']);

    ($this->receive)('40', '25', '2026-09-05');
    ($this->sell)('15', '2026-09-10');

    $this->actingAs($reader)
        ->get('/accounting/inventory-valuation?as_at=2026-09-30&group=family')
        ->assertInertia(fn ($page) => $page
            ->where('total', '625.00')
            ->where('by_family.0.name', 'Honey jar')
            ->where('by_family.0.quantity_text', '500 g: 25')
            ->where('by_family.0.value', '625.00'));

    $this->actingAs($reader)
        ->get('/accounting/cost-of-goods-sold?from=2026-09-01&to=2026-09-30&group=family')
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.product_name', 'Honey jar')
            ->where('rows.0.family_id', $family->id)
            ->where('rows.0.quantity_text', '500 g: 15')
            ->where('rows.0.cost', '375.00')
            ->where('rows.0.revenue', '900.00')
            ->where('rows.0.margin_percent', '58.3')
            ->where('totals.cost', '375.00'));

    $this->actingAs($reader)
        ->get('/accounting/cost-of-goods-sold?from=2026-09-01&to=2026-09-30')
        ->assertInertia(fn ($page) => $page->where('rows.0.product_name', 'Honey jar 500g')->where('group', ''));
});
