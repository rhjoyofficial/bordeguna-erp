<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\CreateInventoryAdjustment;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The reconciliation: proving each subsidiary ledger agrees with its control
 * account. See RECONCILIATION-ARCHITECTURE.md.
 *
 * ⚠ Corruption is injected with raw DB updates throughout, because the models
 * involved refuse writes - which is itself the reason reconciliation is
 * possible to trust.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-10 10:00:00');

    $this->business = Business::create(['name' => 'Recon Co', 'slug' => 'recon-co']);
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
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
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

    // The full trading cycle, through the real actions, so every document has
    // the posting the application would give it: buy 40 @ 25, get billed,
    // pay 600 of the 1,000, sell 15 @ 60 for cash.
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '100', 'unit_cost' => '25.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '40']], $this->user);

    $this->bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'INV-9001',
        billDate: Carbon::parse('2026-08-10'),
        lines: [['line' => $line, 'quantity' => '40', 'unitCost' => '25.00']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    $this->payment = app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->cash,
        paidOn: '2026-08-10',
        allocations: [['bill' => $this->bill, 'amount' => '600']],
        createdBy: $this->user,
    );

    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '15']],
        createdBy: $this->user,
        moneyAccount: $this->cash,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('reconciles a full trading cycle with zero problems', function () {
    $result = SubledgerReconciliation::reconcileAllBusinesses();

    expect($result['problems'])->toBeEmpty()
        ->and($result['warnings'])->toBeEmpty()
        ->and($result['checks'])->toBeGreaterThanOrEqual(6);
});

// ⚠⚠ The defining failure this command exists for: the trial balance still
// balances after this corruption - only the documents disagree.
it('catches a bill total that no longer matches its posting, in total and for the supplier', function () {
    DB::table('supplier_bills')->where('id', $this->bill->id)->update(['total' => '1100.0000']);

    $problems = SubledgerReconciliation::reconcileAllBusinesses()['problems'];

    // Three breaks from one corruption, and all three are true: the AP total,
    // the supplier's own balance, and GRNI - because the bill's posting debited
    // GRNI at the total the document no longer carries.
    expect($problems)->toHaveCount(3)
        ->and(implode(' ', $problems))->toContain('Accounts Payable')
        ->and(implode(' ', $problems))->toContain('Dhaka Glassware')
        ->and(implode(' ', $problems))->toContain('Goods Received Not Invoiced');
});

it('catches a stock movement that no longer matches what was posted', function () {
    DB::table('stock_movements')->limit(1)->update(['unit_cost' => '26.0000']);

    $problems = SubledgerReconciliation::reconcileAllBusinesses()['problems'];

    expect(implode(' ', $problems))->toContain('Stock on Hand');
});

it('catches invoiced cost drifting from COGS and GDNI', function () {
    DB::table('sales_invoice_lines')->limit(1)->update(['cost_released' => '999.0000']);

    $problems = implode(' ', SubledgerReconciliation::reconcileAllBusinesses()['problems']);

    expect($problems)->toContain('Cost of Goods Sold')
        ->and($problems)->toContain('Goods Delivered Not Invoiced');
});

// C-REC-4: money paid on account is a NAMED reconciling item, never a break.
// GL agrees (it was posted); only the allocation is missing.
it('reports an unallocated payment residue as a warning, not a problem', function () {
    DB::table('supplier_payment_allocations')->update(['amount' => '500.0000']);

    $result = SubledgerReconciliation::reconcileAllBusinesses();

    expect($result['problems'])->toBeEmpty()
        ->and(implode(' ', $result['warnings']))->toContain('not allocated to any bill');
});

// C-REC-3: operations predating accounting are a warning - the signature is an
// account with documents behind it and no postings at all.
it('treats documents with no postings behind them as a warning, not a break', function () {
    $second = Business::create(['name' => 'Late Books Co', 'slug' => 'late-books-co']);
    TenantScope::enter($second->id);
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    TenantScope::enter($second->id);

    $supplier = Supplier::create(['business_id' => $second->id, 'name' => 'Old Supplier', 'code' => 'OLD-1']);
    DB::table('supplier_bills')->insert([
        'business_id' => $second->id,
        'supplier_id' => $supplier->id,
        'bill_number' => 'PRE-1',
        'bill_date' => '2026-08-01',
        'total' => '1000.0000',
        'created_by' => $this->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    TenantScope::enter($this->business->id);
    $result = SubledgerReconciliation::reconcileAllBusinesses();

    expect($result['problems'])->toBeEmpty()
        ->and(implode(' ', $result['warnings']))->toContain('no postings - operations predate accounting');
});

// ─── The tenant traps LedgerIntegrity already taught this codebase ───────────

it('actually looks at something when run with no tenant', function () {
    TenantScope::leave();

    // With the scope failing closed this would see nothing and report success -
    // the worst possible failure for an integrity check.
    expect(Journal::count())->toBe(0);

    $result = SubledgerReconciliation::reconcileAllBusinesses();

    expect($result['businesses'])->toBeGreaterThan(0)
        ->and($result['checks'])->toBeGreaterThan(0);
});

it('puts the caller back in the business it found them in', function () {
    SubledgerReconciliation::reconcileAllBusinesses();

    expect(app(BusinessContext::class)->id())->toBe($this->business->id);
});

it('leaves no tenant behind when called without one', function () {
    TenantScope::leave();

    SubledgerReconciliation::reconcileAllBusinesses();

    expect(app(BusinessContext::class)->id())->toBeNull();
});

// ─── The command ─────────────────────────────────────────────────────────────

it('passes the artisan command on agreeing books', function () {
    $this->artisan('accounting:reconcile')
        ->expectsOutputToContain('agrees with its control account')
        ->assertExitCode(0);
});

it('refuses to call zero checks agreement', function () {
    // ⚠⚠ THE FALSE GREEN THE FIRST RESTORE DRILL FOUND (2026-08-13). Every check
    // family is gated on an anchor mapping, so a system with no mappings skips
    // all of them - and the command used to report "every subsidiary ledger
    // agrees" having examined nothing. On the restored 2 August dump that meant
    // three businesses holding 61,500 BDT of stock documents, none examined, and
    // a green result.
    AccountMapping::query()->delete();

    $this->artisan('accounting:reconcile')
        ->expectsOutputToContain('Ran 0 reconciliation(s)')
        ->expectsOutputToContain('Nothing was reconciled')
        ->expectsOutputToContain('NOT a statement that the books agree')
        ->doesntExpectOutputToContain('agrees with its control account')
        // ⚠ Still exit 0: a freshly installed system has no mappings yet, and a
        // command that is red through a normal state stops being read.
        ->assertExitCode(0);
});

it('fails the artisan command on a reconciliation break', function () {
    DB::table('supplier_bills')->where('id', $this->bill->id)->update(['total' => '1100.0000']);

    $this->artisan('accounting:reconcile')
        ->expectsOutputToContain('reconciliation break')
        ->assertExitCode(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// C-IVL-1: REVALUATION, THEN RECONCILIATION
//
// ⚠⚠ THE SEAM THAT NOTHING CROSSED. `StockRevaluationTest` revalues and never
// reconciles; every test above reconciles and never revalues. Both suites were
// green while a revaluation posted to the ledger in full and was valued at
// nothing by the figure that checks the ledger - a permanent, unclearable break
// equal to the revaluation, plus a spurious "batch cache differs" warning.
//
// Latent only because production had zero adjustments. These tests are the
// crossing, added before real opening stock exists. Approved by Joy 2026-08-13.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Setup leaves 25 units on hand at a cost of 25: bought 40 @ 25 (1,000),
 * sold 15 at cost (375), so stock is 625 in the ledger, the movements and the
 * batch cache alike.
 */
$revalueTo = function (string $newCost, AdjustmentDirection $direction) {
    Permission::findOrCreate('inventory.stock.adjust', 'web');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $role = Role::firstOrCreate([
        'name' => 'Inventory Manager',
        'business_id' => $this->business->id,
        'guard_name' => 'web',
    ]);
    $role->givePermissionTo('inventory.stock.adjust');
    $this->user->assignRole($role);

    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000000']);

    $reason = Reason::create([
        'business_id' => $this->business->id,
        'context' => 'inventory.stock_adjustment',
        'name' => 'Net realisable value',
        'is_active' => true,
    ]);

    return app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Revaluation,
        direction: $direction,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '0',
        reason: $reason,
        createdBy: $this->user->fresh(),
        batch: StockBatch::query()->where('product_id', $this->product->id)->firstOrFail(),
        newUnitCost: $newCost,
    );
};

it('still reconciles after stock is revalued UPWARDS', function () use ($revalueTo) {
    // 25 units from 25 to 30 = 125 more, capitalised onto the layer.
    $revalueTo->call($this, '30', AdjustmentDirection::Increase);

    $result = SubledgerReconciliation::reconcileAllBusinesses();

    // ⚠ The whole point: the ledger moved by 125, and so must the figure that
    // checks it. Before the fix the derivation valued the revaluation at
    // 0 x 30 = nothing and reported a 125 break that no repair could clear.
    expect($result['problems'])->toBeEmpty()
        // ⚠ And no "batch cache differs from the movements" warning either.
        // The batch's unit_cost IS updated by a revaluation, so the cache moved
        // while the movements did not - a second, entirely spurious alarm.
        ->and($result['warnings'])->toBeEmpty();

    expect(stockOnHandInLedger())->toBe('750.00');
});

it('still reconciles after stock is written DOWN', function () use ($revalueTo) {
    // Net realisable value: 25 units from 25 to 20 = 125 less.
    $revalueTo->call($this, '20', AdjustmentDirection::Decrease);

    $result = SubledgerReconciliation::reconcileAllBusinesses();

    expect($result['problems'])->toBeEmpty()
        ->and($result['warnings'])->toBeEmpty();

    // ⚠⚠ THE SECOND DEFECT, AND THE WORSE ONE. `value_delta` is stored as a
    // positive MAGNITUDE with its direction on the movement, and the posting
    // engine used to return it UNSIGNED. A positive amount is a debit, so
    // writing stock DOWN used to INCREASE Stock on Hand to 750 and credit the
    // write-off expense: backwards, for twice the amount. It must fall to 500.
    expect(stockOnHandInLedger())->toBe('500.00');
});

it('leaves an ordinary write-off posting exactly as it was', function () {
    // ⚠ The regression guard Joy asked for: unrelated accounting behaviour must
    // not move. A quantity write-off carries no `value_delta`, so it takes the
    // quantity x cost path and its sign has always come from its direction.
    Permission::findOrCreate('inventory.stock.adjust', 'web');
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $role = Role::firstOrCreate(['name' => 'Inventory Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo('inventory.stock.adjust');
    $this->user->assignRole($role);
    InventorySetting::forCurrentBusiness()->update(['adjustment_approval_threshold' => '1000000']);

    app(CreateInventoryAdjustment::class)->handle(
        type: InventoryAdjustmentType::Damaged,
        direction: AdjustmentDirection::Decrease,
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '5',
        reason: Reason::create([
            'business_id' => $this->business->id,
            'context' => 'inventory.stock_adjustment',
            'name' => 'Damaged',
            'is_active' => true,
        ]),
        createdBy: $this->user->fresh(),
    );

    $result = SubledgerReconciliation::reconcileAllBusinesses();

    // 625 less 5 units at 25 = 500, by the path that always worked.
    expect($result['problems'])->toBeEmpty()
        ->and($result['warnings'])->toBeEmpty()
        ->and(stockOnHandInLedger())->toBe('500.00');
});

function stockOnHandInLedger(): string
{
    $accountId = Account::bySlug('stock_on_hand')->id;

    return number_format((float) DB::table('journal_lines')
        ->where('account_id', $accountId)
        ->sum('amount_minor') / 100, 2, '.', '');
}
