<?php

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\BalanceSheet;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
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
use Spatie\Permission\PermissionRegistrar;

/**
 * ▶▶ THE WHOLE OPERATIONAL SPINE, ending in the two statements.
 *
 * Buy on credit → receive → be billed → pay the supplier → sell → and then read
 * the profit and loss and the balance sheet that result.
 *
 * ⚠⚠ NO TEST HAS EVER SPANNED PURCHASING AND SALES IN ONE CYCLE. Every module
 * was proven in isolation, and the 12 August audit found that the whole spine
 * had never been exercised end to end even once. That is the gap this closes:
 * the statements are asserted against a REAL trading cycle driven by the actual
 * actions, not against journals hand-posted to make the sums come out.
 *
 * The arithmetic, by hand, before the engine:
 *   Buy 20 @ 500 on credit          stock 10,000, AP 10,000
 *   Pay the supplier 6,000          bank -6,000, AP 4,000
 *   Capital introduced 50,000       bank 50,000
 *   Sell 12 @ 850 for cash          revenue 10,200, cash +10,200
 *     FIFO cost of those 12         12 x 500 = 6,000 COGS, stock 4,000
 *   Rent paid 2,000                 bank -2,000
 *
 *   P&L:  income 10,200 - COGS 6,000 = gross 4,200; less rent 2,000 = net 2,200
 *   BS:   bank 50,000 - 6,000 + 10,200 - 2,000 = 52,200; stock 4,000
 *         assets 56,200 = AP 4,000 + capital 50,000 + result 2,200
 */
beforeEach(function () {
    $this->business = Business::create([
        'name' => 'Cycle Co',
        'slug' => 'cycle-co',
        'fiscal_year_start_month' => 7,
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    // ⚠ The purchasing mappings (AP, GRNI) live in the INVENTORY seeder, not a
    // purchasing one - purchasing has only a permissions seeder.
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    Carbon::setTestNow('2026-08-10 10:00:00');

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Uttar Adabor', 'code' => 'ADABOR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'HON-FLO-255G',
        'name' => 'Floral Gold Honey 255gm',
        'type' => ProductType::FinishedGood,
    ]);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Sundarban Apiaries']);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '850',
        'effective_from' => '2026-07-01',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('produces a correct profit and loss and a balancing sheet from one real trading cycle', function () {
    // ─── Capital introduced, so the business has money to trade with ────────
    app(PostJournal::class)->handle(
        new JournalDraft(
            date: Carbon::parse('2026-08-01'),
            lines: [
                JournalLineDraft::debit(
                    Account::bySlug('cash_at_bank')->id,
                    Money::ofTaka('50000'),
                ),
                JournalLineDraft::credit(
                    Account::bySlug('owners_capital')->id,
                    Money::ofTaka('50000'),
                ),
            ],
            memo: 'Capital introduced',
        ),
        $this->user,
    );

    // ─── BUY: order, approve, receive ───────────────────────────────────────
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '20', 'unit_cost' => '500']],
        createdBy: $this->user,
    );
    approveIfPending($order->approvalRequest, $this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);

    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '20'],
    ], $this->user);

    // ─── BE BILLED: the liability becomes real ──────────────────────────────
    $bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'SA/2026/114',
        billDate: '2026-08-02',
        lines: [['line' => $order->fresh('lines')->lines->first(), 'quantity' => '20', 'unitCost' => '500']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    // ─── PAY part of it ─────────────────────────────────────────────────────
    // ⚠ The payment carries no `amount` of its own: it is the sum of what it
    // settles. That is the design - a payment that could disagree with its own
    // allocations is how AP stops being a subsidiary ledger.
    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->bank,
        paidOn: '2026-08-05',
        allocations: [['bill' => $bill, 'amount' => '6000']],
        createdBy: $this->user,
    );

    // ─── SELL 12 for cash ───────────────────────────────────────────────────
    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '12']],
        createdBy: $this->user,
        moneyAccount: $this->bank,
    );

    // ─── An operating expense ───────────────────────────────────────────────
    app(PostJournal::class)->handle(
        new JournalDraft(
            date: Carbon::parse('2026-08-09'),
            lines: [
                JournalLineDraft::debit(
                    Account::where('code', '6051')->first()->id,
                    Money::ofTaka('2000'),
                ),
                JournalLineDraft::credit(
                    Account::bySlug('cash_at_bank')->id,
                    Money::ofTaka('2000'),
                ),
            ],
            memo: 'Rent',
        ),
        $this->user,
    );

    // ─── THE STATEMENTS ─────────────────────────────────────────────────────
    $from = Carbon::parse('2026-07-01')->startOfDay();
    $to = Carbon::parse('2026-08-10')->endOfDay();

    $pl = IncomeStatement::between($from, $to);
    $bs = BalanceSheet::asAt($to, $this->business);

    // Hand arithmetic, stated at the top of this file, met exactly.
    expect($pl->totalIncome()->format())->toBe('10,200.00')
        ->and($pl->totalCostOfSales()->format())->toBe('6,000.00')
        ->and($pl->grossProfit()->format())->toBe('4,200.00')
        ->and($pl->totalOperatingExpenses()->format())->toBe('2,000.00')
        ->and($pl->netProfit()->format())->toBe('2,200.00');

    expect($bs->totalAssets()->format())->toBe('56,200.00')
        ->and($bs->totalLiabilities()->format())->toBe('4,000.00')
        ->and($bs->totalEquityAccounts()->format())->toBe('50,000.00')
        ->and($bs->unclosedResult()->format())->toBe('2,200.00')
        ->and($bs->totalLiabilitiesAndEquity()->format())->toBe('56,200.00');

    // ⚠⚠ The two properties everything else exists to protect.
    expect($bs->balances())->toBeTrue()
        ->and($bs->difference()->minor)->toBe(0)
        ->and(TrialBalance::between($from, $to)->balances())->toBeTrue();

    // ⚠ And the statements agree with each other: this fiscal year's whole
    // profit IS the balance sheet's result for the year, because the ledger
    // began inside it.
    expect($pl->netProfit()->format())->toBe($bs->presentedResultForTheYear()->format())
        ->and($bs->presentedResultBroughtForward()->minor)->toBe(0);

    // ⚠ GRNI must be flat: goods received AND billed leaves nothing in the
    // bridge account. A residue here would be a real liability sitting in the
    // wrong place, and the balance sheet would still balance.
    $grni = $bs->liabilities
        ->flatMap(fn ($section) => $section->lines)
        ->first(fn ($line) => $line->account->system_slug === 'grni');

    expect($grni)->toBeNull();
});

it('shows a margin a manager can act on', function () {
    app(PostJournal::class)->handle(
        new JournalDraft(
            date: Carbon::parse('2026-08-01'),
            lines: [
                JournalLineDraft::debit(
                    Account::bySlug('cash_at_bank')->id,
                    Money::ofTaka('50000'),
                ),
                JournalLineDraft::credit(
                    Account::bySlug('owners_capital')->id,
                    Money::ofTaka('50000'),
                ),
            ],
        ),
        $this->user,
    );

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '20', 'unit_cost' => '500']],
        createdBy: $this->user,
    );
    approveIfPending($order->approvalRequest, $this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    app(RecordGoodsReceipt::class)->handle($order, [
        ['line' => $order->lines->first(), 'quantity' => '20'],
    ], $this->user);

    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '12']],
        createdBy: $this->user,
        moneyAccount: $this->bank,
    );

    $pl = IncomeStatement::between(
        Carbon::parse('2026-07-01')->startOfDay(),
        Carbon::parse('2026-08-10')->endOfDay(),
    );

    // Bought at 500, sold at 850: 41.18% gross margin, and the number a
    // manager actually asks for is on the face of the statement.
    expect($pl->grossMarginPercent())->toBe(41.18);
});
