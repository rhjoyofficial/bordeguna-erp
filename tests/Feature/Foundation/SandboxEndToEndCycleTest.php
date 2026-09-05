<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\database\seeders\SandboxSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * The complete operational spine, driven end to end on dummy data.
 *
 * supplier → PO → approve → goods receipt → supplier bill → payment →
 * customer → price → counter sale → receipt → trial balance
 *
 * ⚠⚠ THIS IS THE FIRST TIME PURCHASING, INVENTORY, SALES AND ACCOUNTING RUN
 * TOGETHER ON ONE DATASET. Every module has been tested alone; the integration
 * between them has not. See DUMMY-DATA-DESIGN.md §6 for what it is hunting.
 *
 * ⚠ Runs entirely inside the SANDBOX business. Bor de Guna receives no dummy
 * transaction of any kind.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-09 10:00:00');

    (new SandboxSeeder)->run();

    $this->business = Business::withoutGlobalScopes()->where('slug', SandboxSeeder::SLUG)->sole();
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create(['name' => 'DUMMY Operator']);
    $this->user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->user->assignRole('Owner');

    $this->warehouse = Warehouse::where('code', 'ADABOR')->sole();
    $this->balanceOn = fn (string $slug) => (int) JournalLine::where(
        'account_id', Account::bySlug($slug)->id
    )->sum('amount_minor');
});

/**
 * Buys `$quantity` at `$unitCost`, bills it, and pays it in full.
 *
 * @return array{order: mixed, line: mixed}
 */
function buyAndPay($test, Product $product, string $quantity, string $unitCost)
{
    $supplier = Supplier::firstOrCreate(
        ['business_id' => $test->business->id, 'code' => 'DUMMY-SUP-01'],
        ['name' => 'DUMMY Ad-Deen Supplies']
    );

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $supplier,
        warehouse: $test->warehouse,
        lines: [['product' => $product, 'quantity' => $quantity, 'unit_cost' => $unitCost]],
        createdBy: $test->user,
    );
    approveIfPending($order->approvalRequest, $test->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => $quantity]], $test->user);

    $bill = app(RecordSupplierBill::class)->handle(
        supplier: $supplier,
        billNumber: 'DUMMY-BILL-'.uniqid(),
        billDate: Carbon::parse('2026-08-09'),
        lines: [['line' => $line, 'quantity' => $quantity, 'unitCost' => $unitCost]],
        createdBy: $test->user,
        purchaseOrder: $order,
    );

    app(RecordSupplierPayment::class)->handle(
        supplier: $supplier,
        moneyAccount: MoneyAccount::where('name', 'DUMMY BRAC Bank')->sole(),
        paidOn: Carbon::parse('2026-08-09'),
        allocations: [['bill' => $bill, 'amount' => (string) $bill->total]],
        createdBy: $test->user,
        reference: 'DUMMY-PAY-01',
    );

    return ['order' => $order->fresh('lines'), 'line' => $line, 'bill' => $bill->fresh()];
}

// ── The spine, on a PIECE product ───────────────────────────────────────────

it('runs purchase to payment and leaves GRNI clear', function () {
    $honey = Product::where('sku', 'DUMMY-HON-500G')->sole();

    buyAndPay($this, $honey, '100', '480.00');

    // 100 jars at 480 = 48,000.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(4800000)
        // ⚠ GRNI is a REPORT, not a suspense. Received and invoiced must cancel.
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        // Paid in full, so nothing is owed.
        ->and(($this->balanceOn)('accounts_payable'))->toBe(0);

    $batch = StockBatch::where('product_id', $honey->id)->sole();
    expect((string) $batch->current_quantity)->toBe('100.0000')
        ->and((string) $batch->unit_cost)->toBe('480.0000');

    Journal::all()->each(fn (Journal $j) => expect($j->balance()->isZero())->toBeTrue());
});

it('sells at the counter and posts revenue, COGS and cash', function () {
    $honey = Product::where('sku', 'DUMMY-HON-500G')->sole();
    buyAndPay($this, $honey, '100', '480.00');

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $honey->id,
        'unit_price' => '850.0000',
        'effective_from' => '2026-08-01',
    ]);

    $till = MoneyAccount::where('name', 'DUMMY Cash in Hand')->sole();
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    $this->actingAs($this->user)->post('/sales/counter', [
        'customer_id' => $walkIn->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $till->id,
        'lines' => [['product_id' => $honey->id, 'quantity' => '10']],
    ])->assertRedirect();

    // 10 at 850 = 8,500 revenue; COGS 10 at 480 = 4,800; cash in 8,500.
    expect(($this->balanceOn)('sales_revenue'))->toBe(-850000)
        ->and(($this->balanceOn)('cost_of_goods_sold'))->toBe(480000)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(4800000 - 480000);

    expect((string) StockBatch::where('product_id', $honey->id)->sole()->current_quantity)->toBe('90.0000');
});

// ── ⚠⚠ The by-weight path, which has never run purchase-to-sale ─────────────

it('runs the complete cycle on a KILOGRAM product and keeps the books straight', function () {
    $shutki = Product::where('sku', 'DUMMY-SHUTKI-LOITTA')->sole();

    // 50 kg at 1,800/kg = 90,000.
    buyAndPay($this, $shutki, '50', '1800.00');

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(9000000);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $shutki->id,
        'unit_price' => '2520.0000',
        'effective_from' => '2026-08-01',
    ]);

    $till = MoneyAccount::where('name', 'DUMMY Cash in Hand')->sole();
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    // ⚠⚠ A FRACTIONAL SALE - 1.25 kg. This is the case the whole by-weight
    // redesign exists for, and it has never been through a real sale.
    $this->actingAs($this->user)->post('/sales/counter', [
        'customer_id' => $walkIn->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $till->id,
        'lines' => [['product_id' => $shutki->id, 'quantity' => '1.25']],
    ])->assertRedirect();

    // 1.25 x 2,520 = 3,150 revenue. COGS 1.25 x 1,800 = 2,250.
    expect(($this->balanceOn)('sales_revenue'))->toBe(-315000)
        ->and(($this->balanceOn)('cost_of_goods_sold'))->toBe(225000);

    // 50 - 1.25 = 48.75 kg left.
    expect((string) StockBatch::where('product_id', $shutki->id)->sole()->current_quantity)->toBe('48.7500');
});

// ── The whole-books check ───────────────────────────────────────────────────

it('leaves the trial balance at zero after the entire cycle', function () {
    $honey = Product::where('sku', 'DUMMY-HON-500G')->sole();
    $shutki = Product::where('sku', 'DUMMY-SHUTKI-LOITTA')->sole();

    buyAndPay($this, $honey, '100', '480.00');
    buyAndPay($this, $shutki, '50', '1800.00');

    foreach ([[$honey, '850.0000'], [$shutki, '2520.0000']] as [$product, $price]) {
        ProductPrice::create([
            'business_id' => $this->business->id, 'product_id' => $product->id,
            'unit_price' => $price, 'effective_from' => '2026-08-01',
        ]);
    }

    $till = MoneyAccount::where('name', 'DUMMY Cash in Hand')->sole();
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    $this->actingAs($this->user)->post('/sales/counter', [
        'customer_id' => $walkIn->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $till->id,
        'lines' => [
            ['product_id' => $honey->id, 'quantity' => '10'],
            ['product_id' => $shutki->id, 'quantity' => '2.5'],
        ],
    ])->assertRedirect();

    // ⚠⚠ THE ONE ASSERTION THAT MATTERS MOST. Every journal in the business,
    // summed, must be zero. A non-zero trial balance means the books do not
    // balance, and no amount of per-module testing would have shown it.
    expect((int) JournalLine::sum('amount_minor'))->toBe(0);

    Journal::all()->each(fn (Journal $j) => expect($j->balance()->isZero())->toBeTrue());

    expect(Journal::count())->toBeGreaterThan(4);
});

// ── ⚠⚠ Running costs, the other half of the P&L ─────────────────────────────

it('records what the business spent on running itself and still balances', function () {
    $honey = Product::where('sku', 'DUMMY-HON-500G')->sole();
    buyAndPay($this, $honey, '100', '480.00');

    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $honey->id,
        'unit_price' => '850.0000', 'effective_from' => '2026-08-01',
    ]);

    $till = MoneyAccount::where('name', 'DUMMY Cash in Hand')->sole();
    $walkIn = Customer::where('code', Customer::WALK_IN_CODE)->sole();

    $this->actingAs($this->user)->post('/sales/counter', [
        'customer_id' => $walkIn->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $till->id,
        'lines' => [['product_id' => $honey->id, 'quantity' => '10']],
    ])->assertRedirect();

    // ⚠⚠ Until this screen existed, THIS WAS UNREACHABLE. Stock could be bought
    // and sold, so the ERP could show a gross margin - but rent, fuel and
    // utilities had nowhere to be entered, so the figure below the gross margin
    // was always zero and the P&L was a half-truth.
    $this->actingAs($this->user)->post('/purchasing/expenses', [
        'money_account_id' => $till->id,
        'spent_on' => '2026-08-09',
        'paid_to' => 'DUMMY Adabor landlord',
        'reference' => 'DUMMY-RCPT-01',
        'lines' => [
            ['account_id' => Account::where('code', '6051')->sole()->id, 'amount' => '25000.00', 'description' => 'August rent'],
            ['account_id' => Account::where('code', '6011')->sole()->id, 'amount' => '1500.50', 'description' => 'Delivery fuel'],
        ],
    ])->assertRedirect();

    $rent = (int) JournalLine::where('account_id', Account::where('code', '6051')->sole()->id)->sum('amount_minor');
    $fuel = (int) JournalLine::where('account_id', Account::where('code', '6011')->sole()->id)->sum('amount_minor');

    expect($rent)->toBe(2500000)
        ->and($fuel)->toBe(150050);

    // Revenue 8,500 · COGS 4,800 · running costs 26,500.50. The business made a
    // gross profit and a net LOSS, and only now can the ERP say so.
    expect(($this->balanceOn)('sales_revenue'))->toBe(-850000)
        ->and(($this->balanceOn)('cost_of_goods_sold'))->toBe(480000);

    // ⚠⚠ The assertion that matters: adding a whole new document type to the
    // day's trading must leave the books balanced.
    expect((int) JournalLine::sum('amount_minor'))->toBe(0);

    Journal::all()->each(fn (Journal $journal) => expect($journal->balance()->isZero())->toBeTrue());
});

// ── ⚠⚠ What the business OWNS, and what it wears out ────────────────────────

it('buys a van, keeps it off the P&L, and wears it down a month at a time', function () {
    $till = MoneyAccount::where('name', 'DUMMY BRAC Bank')->sole();
    $vehicles = Account::where('code', '1230')->sole();

    // ⚠⚠ Until the Assets screens existed, THE ONLY REACHABLE PATH for this van
    // was Purchasing - which files it as stock and expenses it as cost of goods
    // sold the first time it is issued. It would pass through one month's P&L
    // and vanish from the balance sheet.
    $this->actingAs($this->user)->post('/assets', [
        'asset_code' => 'DUMMY-VAN-01',
        'name' => 'DUMMY Delivery van',
        'account_id' => $vehicles->id,
        'acquired_on' => '2026-07-01',
        'cost' => '1200000.00',
        'salvage_value' => '120000.00',
        'useful_life_months' => 36,
        'money_account_id' => $till->id,
    ])->assertRedirect();

    // On the balance sheet, not in the costs.
    expect((int) JournalLine::where('account_id', $vehicles->id)->sum('amount_minor'))->toBe(120000000)
        ->and(($this->balanceOn)('cost_of_goods_sold'))->toBe(0);

    $this->actingAs($this->user)
        ->post('/assets/depreciation', ['year' => 2026, 'month' => 7])
        ->assertRedirect();

    // (1,200,000 - 120,000) / 36 = 30,000 for July.
    expect(($this->balanceOn)('depreciation_expense'))->toBe(3000000)
        ->and(($this->balanceOn)('accumulated_depreciation'))->toBe(-3000000);

    expect((int) JournalLine::sum('amount_minor'))->toBe(0);

    Journal::all()->each(fn (Journal $journal) => expect($journal->balance()->isZero())->toBeTrue());
});

// ── Teardown ────────────────────────────────────────────────────────────────

it('removes the entire sandbox and leaves no orphan row', function () {
    $honey = Product::where('sku', 'DUMMY-HON-500G')->sole();
    buyAndPay($this, $honey, '100', '480.00');

    $businessId = $this->business->id;

    TenantScope::leave();

    // ⚠ --force because a sandbox that has run the cycle is SUPPOSED to hold
    // journals; the check exists so that pointing this at a real business fails.
    $this->artisan('sandbox:teardown', ['--force' => true])->assertSuccessful();

    expect(Business::withoutGlobalScopes()->where('slug', SandboxSeeder::SLUG)->exists())->toBeFalse();

    // ⚠⚠ Every table carrying business_id must hold nothing for that id. A
    // half-deleted tenant leaves rows belonging to a business that no longer
    // exists, and every scoped query would silently ignore them for ever.
    $tables = collect(Schema::getTables())
        ->map(fn ($t) => is_array($t) ? $t['name'] : $t->name)
        ->filter(fn (string $t) => Schema::hasColumn($t, 'business_id'));

    $orphans = $tables->mapWithKeys(fn (string $t) => [
        $t => (int) DB::table($t)->where('business_id', $businessId)->count(),
    ])->filter();

    expect($orphans->all())->toBe([], 'orphan rows: '.$orphans->keys()->implode(', '));
});

it('refuses to delete anything that is not the sandbox', function () {
    // ⚠⚠ THE GUARD THAT MATTERS. Bor de Guna must be untouchable by this command.
    $this->artisan('sandbox:teardown', ['--business' => 'bor-de-guna'])->assertFailed();

    expect(Business::withoutGlobalScopes()->where('slug', SandboxSeeder::SLUG)->exists())->toBeTrue();
});
