<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Support\PurchaseLineBackfill;
use App\Modules\Purchasing\Support\PurchaseLineEntry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1 part A (17 Oct 2026, ChatGPT ruling Q1): a purchase line is typed
 * EITHER as a unit price OR as a line total, the typed figure is the truth,
 * and everything downstream (order total, GRNI, batch cost, the bill-vs-order
 * variance) reads the stored total rather than quantity x a rounded rate.
 *
 * ⚠⚠ The case that motivated it: 3,000 units for 100,000. The only four-place
 * rate is 33.3333, and 3,000 x 33.3333 is 99,999.90. The total must stay
 * 100,000.00 to the paisa, and the ledger must balance while it does.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Totals Co', 'slug' => 'totals-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->business->id);

    $permissions = ['purchasing.po.create', 'purchasing.po.approve', 'purchasing.receipt.record', 'purchasing.bill.record'];
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web']);

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    Role::where('business_id', $this->business->id)->where('name', 'Manager')->first()->givePermissionTo($permissions);
    $this->user->assignRole('Manager');

    $this->unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'SALT-BULK',
        'name' => 'Salt bulk',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Salt Mill', 'code' => 'SUP-SALT']);

    $this->balanceOn = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');

    $this->order = function (array $line): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: [['product' => $this->product] + $line],
            createdBy: $this->user,
        );
        approveIfPending($order->approvalRequest, $this->user);

        return $order->fresh(['lines', 'approvalRequest']);
    };

    $this->receiveAll = function (PurchaseOrder $order): void {
        $line = $order->lines->first();
        app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => (string) $line->quantity_ordered]], $this->user);
    };

    $this->bill = fn (PurchaseOrder $order, array $line, string $number = 'SALT-001'): SupplierBill => app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: $number,
        billDate: '2026-10-17',
        lines: [['line' => $order->lines->first()->fresh()] + $line],
        createdBy: $this->user,
        purchaseOrder: $order,
    );
});

afterEach(fn () => Carbon::setTestNow());

// ── The arithmetic on its own ───────────────────────────────────────────────

it('derives 500.00 per kg from 100 kg for 50,000, and the total stays exact', function () {
    $entry = PurchaseLineEntry::resolve('100', null, '50000', 'total');

    expect($entry['entry_mode'])->toBe(PurchaseEntryMode::Total)
        ->and($entry['unit_cost'])->toBe('500.000000')
        ->and($entry['line_total']->minor)->toBe(5000000);
});

it('keeps 100,000 for 3,000 units to the paisa and shows a six-place rate', function () {
    $entry = PurchaseLineEntry::resolve('3000', null, '100000', 'total');

    expect($entry['unit_cost'])->toBe('33.333333')
        ->and($entry['line_total']->minor)->toBe(10000000)
        // ⚠ The four-place rate the old column could hold, multiplied back,
        // is 99,999.90: ten paisa short. That gap is why the total is stored
        // and the rate is only shown.
        ->and(Money::ofProduct('3000', '33.3333')->minor)->toBe(9999990);
});

it('prices 1,000 for 3 units at 333.333333 and keeps the total at 1,000.00', function () {
    $entry = PurchaseLineEntry::resolve('3', null, '1000', 'total');

    expect($entry['unit_cost'])->toBe('333.333333')
        ->and($entry['line_total']->minor)->toBe(100000);
});

it('computes a rate entry exactly as Money::ofProduct always did', function () {
    $entry = PurchaseLineEntry::resolve('3', '0.29', null, 'rate');

    expect($entry['entry_mode'])->toBe(PurchaseEntryMode::Rate)
        ->and($entry['unit_cost'])->toBe('0.290000')
        ->and($entry['line_total']->minor)->toBe(Money::ofProduct('3', '0.29')->minor);
});

it('infers the mode from which figure arrived when none is stated', function () {
    expect(PurchaseLineEntry::resolve('10', '5', null)['entry_mode'])->toBe(PurchaseEntryMode::Rate)
        ->and(PurchaseLineEntry::resolve('10', null, '50')['entry_mode'])->toBe(PurchaseEntryMode::Total)
        // Both sent, mode stated: the stated one wins and the other is ignored.
        ->and(PurchaseLineEntry::resolve('10', '4.99', '50', 'total')['unit_cost'])->toBe('5.000000')
        ->and(PurchaseLineEntry::resolve('10', '5', '49.99', 'rate')['line_total']->minor)->toBe(5000);
});

it('refuses a line with no authoritative figure or no quantity', function () {
    expect(fn () => PurchaseLineEntry::resolve('10', null, null, 'rate'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PurchaseLineEntry::resolve('10', '5', null, 'total'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PurchaseLineEntry::resolve('0', '5', null, 'rate'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PurchaseLineEntry::resolve('10', '-5', null, 'rate'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => PurchaseLineEntry::resolve('10', null, '-50', 'total'))->toThrow(InvalidArgumentException::class);

    expect(PurchaseLineEntry::problems('10', null, null, 'total'))->toHaveKey('line_total')
        ->and(PurchaseLineEntry::problems('10', null, null, 'rate'))->toHaveKey('unit_cost')
        ->and(PurchaseLineEntry::problems('0', '5', null, 'rate'))->toHaveKey('quantity')
        ->and(PurchaseLineEntry::problems('10', '5', null, 'rate'))->toBe([]);
});

it('shares a typed total across a part quantity to the paisa', function () {
    $total = Money::ofMinor(10000000); // 100,000.00 for 3,000

    expect(PurchaseLineEntry::shareOf($total, '1000', '3000')->minor)->toBe(3333333)
        ->and(PurchaseLineEntry::shareOf($total, '3000', '3000')->minor)->toBe(10000000)
        ->and(PurchaseLineEntry::shareOf($total, '0.5', '3')->minor)->toBe(1666667);
});

it('rounds a typed rate to six places half away from zero', function () {
    expect(PurchaseLineEntry::roundTo('33.3333335', 6))->toBe('33.333334')
        ->and(PurchaseLineEntry::roundTo('5', 6))->toBe('5.000000')
        ->and(PurchaseLineEntry::roundTo('0.9999995', 6))->toBe('1.000000');
});

// ── Through the order, the receipt and the bill ─────────────────────────────

it('stores the typed total on the order line and derives the rate', function () {
    $order = ($this->order)(['quantity' => '100', 'line_total' => '50000', 'entry_mode' => 'total']);
    $line = $order->lines->first();

    expect($line->entry_mode)->toBe(PurchaseEntryMode::Total)
        ->and((string) $line->unit_cost)->toBe('500.000000')
        ->and($line->line_total_paisa)->toBe(5000000)
        ->and($line->line_total)->toBe('50000.00');
});

it('stores a rate entry with its total exactly as before', function () {
    $order = ($this->order)(['quantity' => '100', 'unit_cost' => '320.00']);
    $line = $order->lines->first();

    expect($line->entry_mode)->toBe(PurchaseEntryMode::Rate)
        ->and((string) $line->unit_cost)->toBe('320.000000')
        ->and($line->line_total_paisa)->toBe(3200000);
});

// ▶▶ THE CASE. 3,000 for 100,000: GRNI, stock and the bill all read 100,000.00.
it('puts exactly 100,000.00 through GRNI for 3,000 units bought for 100,000 and balances', function () {
    $order = ($this->order)(['quantity' => '3000', 'line_total' => '100000', 'entry_mode' => 'total']);
    ($this->receiveAll)($order);

    // The supplier will invoice 100,000.00, so that is what GRNI holds.
    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-10000000)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);

    // The batch holds four places (33.3333), so stock is 99,999.90 and the
    // 10 paisa is named as rounding on the receipt journal, as it always was
    // for a converted product; never absorbed into GRNI.
    $batch = $this->product->batches()->sole();
    expect((string) $batch->unit_cost)->toBe('33.3333')
        ->and(($this->balanceOn)('stock_on_hand'))->toBe(9999990);

    // Billed as typed on the supplier's paper: the same 100,000. No variance.
    ($this->bill)($order, ['quantity' => '3000', 'lineTotal' => '100000', 'entryMode' => 'total']);

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-10000000)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(0)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBe([]);

    $billLine = SupplierBill::sole()->lines->first();
    expect((string) $billLine->line_total)->toBe('100000.0000')
        ->and((string) $billLine->unit_cost)->toBe('33.333333')
        ->and($billLine->entry_mode)->toBe(PurchaseEntryMode::Total);
});

it('receives 3 units bought for 1,000 at a stock value of 1,000.00 with no rounding line', function () {
    $order = ($this->order)(['quantity' => '3', 'line_total' => '1000', 'entry_mode' => 'total']);
    ($this->receiveAll)($order);

    // 3 x 333.3333 = 999.9999, which Money rounds to 1,000.00: both sides agree.
    expect(($this->balanceOn)('stock_on_hand'))->toBe(100000)
        ->and(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-100000)
        ->and(JournalLine::count())->toBe(2);
});

it('credits GRNI with the paisa share of a typed total on a part delivery', function () {
    $order = ($this->order)(['quantity' => '3000', 'line_total' => '100000', 'entry_mode' => 'total']);
    $line = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $line, 'quantity' => '1000']], $this->user);

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(-3333333)
        ->and($line->fresh()->valueOf('1000')->minor)->toBe(3333333)
        ->and($line->fresh()->valueOf('3000')->minor)->toBe(10000000);
});

// ▶▶ PPV policy unchanged: a bill against a total-typed order still reports
// the gap, and never touches the batch.
it('keeps the bill-vs-order difference in Purchase Price Variance for a total-typed order', function () {
    $order = ($this->order)(['quantity' => '3000', 'line_total' => '100000', 'entry_mode' => 'total']);
    ($this->receiveAll)($order);

    $batch = $this->product->batches()->sole();
    $costBefore = (string) $batch->unit_cost;

    // The supplier's invoice says 34.00 a unit: 102,000.
    ($this->bill)($order, ['quantity' => '3000', 'unitCost' => '34']);

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(200000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-10200000)
        ->and((string) $batch->fresh()->unit_cost)->toBe($costBefore)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

it('leaves a rate-typed order and its bill exactly as they were (Joy\'s 100 kg at 320)', function () {
    $order = ($this->order)(['quantity' => '100', 'unit_cost' => '320.00']);
    ($this->receiveAll)($order);
    ($this->bill)($order, ['quantity' => '100', 'unitCost' => '330.00']);

    expect(($this->balanceOn)('goods_received_not_invoiced'))->toBe(0)
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(100000)
        ->and(($this->balanceOn)('accounts_payable'))->toBe(-3300000)
        ->and((string) $this->product->batches()->sole()->unit_cost)->toBe('320.0000');
});

// ── The screens ─────────────────────────────────────────────────────────────

it('accepts a line total from the order form and refuses a line with neither figure', function () {
    $post = fn (array $line) => $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->post(route('purchasing.purchase-orders.store'), [
            'supplier_id' => $this->supplier->id,
            'order_date' => now()->toDateString(),
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id] + $line],
        ]);

    $post(['quantity' => '100', 'unit_cost' => '', 'line_total' => '50000', 'entry_mode' => 'total'])->assertRedirect();

    $line = PurchaseOrder::sole()->lines->first();
    expect($line->line_total_paisa)->toBe(5000000)->and((string) $line->unit_cost)->toBe('500.000000');

    $post(['quantity' => '100', 'entry_mode' => 'total'])->assertSessionHasErrors(['lines.0.line_total']);
    $post(['quantity' => '100', 'entry_mode' => 'rate'])->assertSessionHasErrors(['lines.0.unit_cost']);

    expect(PurchaseOrder::count())->toBe(1);
});

it('opens the bill form on the order line\'s figures and records a total-typed bill line', function () {
    $order = ($this->order)(['quantity' => '3000', 'line_total' => '100000', 'entry_mode' => 'total']);
    ($this->receiveAll)($order);

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->get(route('purchasing.bills.create', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('order.lines.0.order_entry_mode', 'total')
            ->where('order.lines.0.order_unit_cost', '33.333333')
            ->where('order.lines.0.order_value_for_billable', '100000.00'));

    $this->actingAs($this->user)
        ->withSession(['current_business_id' => $this->business->id])
        ->post('/purchasing/bills', [
            'purchase_order_id' => $order->id,
            'bill_number' => 'SALT-WEB-1',
            'bill_date' => '2026-10-17',
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'quantity' => '3000',
                'unit_cost' => '33.333333',
                'line_total' => '100000',
                'entry_mode' => 'total',
            ]],
        ])
        ->assertRedirect();

    expect((string) SupplierBill::sole()->total)->toBe('100000.0000')
        ->and(($this->balanceOn)('purchase_price_variance'))->toBe(0);
});

// ── The backfill ────────────────────────────────────────────────────────────

it('backfills every older line with exactly the total Money::ofProduct gave it', function () {
    // Lines written through the action already carry a total; the backfill
    // is for rows that predate the column. Reproduce them: open the column,
    // insert bare rows with awkward decimals, run the backfill in small
    // chunks, and hold every row to the application's own expression.
    $order = ($this->order)(['quantity' => '1', 'unit_cost' => '1']);

    Schema::table('purchase_order_lines', fn (Blueprint $table) => $table->bigInteger('line_total_paisa')->nullable()->change());

    $samples = [['3', '0.29'], ['100', '320'], ['3000', '33.3333'], ['0.5', '0.335'], ['12.25', '1234.5678'], ['7', '0.005'], ['1000000', '0.0001']];

    foreach ($samples as [$quantity, $unitCost]) {
        DB::table('purchase_order_lines')->insert([
            'business_id' => $this->business->id,
            'purchase_order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity_ordered' => $quantity,
            'unit_cost' => $unitCost,
            'line_total_paisa' => null,
            'entry_mode' => 'rate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(PurchaseLineBackfill::remaining())->toBe(count($samples))
        ->and(PurchaseLineBackfill::run(2))->toBe(count($samples))
        ->and(PurchaseLineBackfill::remaining())->toBe(0);

    $rows = DB::table('purchase_order_lines')->where('purchase_order_id', $order->id)->whereNot('id', $order->lines->first()->id)->orderBy('id')->get();

    foreach ($rows as $index => $row) {
        [$quantity, $unitCost] = $samples[$index];

        expect((int) $row->line_total_paisa)->toBe(Money::ofProduct($quantity, $unitCost)->minor, "Row for {$quantity} x {$unitCost}")
            ->and($row->entry_mode)->toBe('rate');
    }

    // A backfilled line values its full quantity at that same total.
    $filled = $order->lines()->orderByDesc('id')->first();
    expect($filled->valueOf((string) $filled->quantity_ordered)->minor)->toBe(Money::ofProduct('1000000', '0.0001')->minor);
});
