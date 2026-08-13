<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\BalanceSheet;
use App\Modules\Accounting\Support\CostOfGoodsSold;
use App\Modules\Accounting\Support\IncomeStatement;
use App\Modules\Accounting\Support\InventoryValuation;
use App\Modules\Accounting\Support\SubledgerReconciliation;
use App\Modules\Accounting\Support\TrialBalance;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApplyOpeningStockImport;
use App\Modules\Inventory\Actions\PlanOpeningStockImport;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OpeningStockFile;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Actions\RecordSupplierBill;
use App\Modules\Purchasing\Actions\RecordSupplierPayment;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\ApplyPriceListImport;
use App\Modules\Sales\Actions\PlanPriceListImport;
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Support\PriceListFile;
use Illuminate\Support\Carbon;

/**
 * THE 1 SEPTEMBER CUTOVER REHEARSAL.
 *
 * ⚠⚠ Everything Bor De Guna will actually do, in the order it will do it, on a
 * scratch business - so that the first time this sequence runs on the real
 * company's books is not the first time it has ever been run.
 *
 *   31 Aug  opening stock imported from the count sheet
 *   31 Aug  selling prices imported, VAT-exclusive, four tiers
 *    1 Sep  purchase order -> goods receipt -> supplier bill -> payment
 *    2 Sep  delivery -> sales invoice -> customer receipt
 *   30 Sep  trial balance, P&L, balance sheet, reconciliation,
 *           valuation and COGS, all cross-checked
 *
 * ⚠⚠ THE ASSERTIONS ARE ECONOMIC. Joy's rule: it is not enough that the journal
 * balances. A balanced trial balance does not prove the accounting is correct,
 * so every figure below is checked against what the business actually did, and
 * the two report engines are checked against each other.
 *
 * ⚠ Two brands throughout, because brand-wise revenue, COGS and gross profit is
 * a stated requirement (A6) and a rehearsal that used one brand would prove
 * nothing about it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-31 09:00:00');

    $this->business = Business::create(['name' => 'Rehearsal Co', 'slug' => 'rehearsal-co']);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);

    $bionic = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic', 'slug' => 'bionic', 'is_active' => true]);
    $laladia = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia', 'is_active' => true]);

    $this->honey = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'brand_id' => $bionic->id,
        'sku' => 'BNC-HON-FLO-255G',
        'name' => 'Floral Gold Honey 255g',
        'type' => ProductType::FinishedGood,
    ]);

    $this->shutki = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'brand_id' => $laladia->id,
        'sku' => 'LLD-DRY-LOI-500G',
        'name' => 'Loitta Shutki 500g',
        'type' => ProductType::FinishedGood,
    ]);

    foreach ([['RETAIL', 'Retail'], ['DEALER', 'Dealer'], ['DISTRIBUTOR', 'Distributor'], ['CORPORATE', 'Corporate']] as [$code, $name]) {
        PriceGroup::create([
            'business_id' => $this->business->id,
            'code' => $code,
            'name' => $name,
            // ⚠ VAT-EXCLUSIVE, per Joy's confirmed decision. VAT stays dormant.
            'prices_include_tax' => false,
            'is_active' => true,
        ]);
    }

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Razzak Oil Mill', 'code' => 'RAZZAK']);

    $this->bank = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'BRAC Bank',
        'type' => 'bank',
        'account_id' => Account::bySlug('cash_at_bank')->id,
    ]);

    $this->importFile = function (string $csv, string $prefix) {
        $path = tempnam(sys_get_temp_dir(), $prefix).'.csv';
        file_put_contents($path, $csv);

        return $path;
    };
});

afterEach(fn () => Carbon::setTestNow());

it('runs the whole cutover, and every report agrees with what the business did', function () {
    // ───────────────────────────────────────────────────────────────────────
    // 31 AUGUST: the opening position.
    // ───────────────────────────────────────────────────────────────────────
    Carbon::setTestNow('2026-08-31 09:00:00');

    $path = ($this->importFile)(
        "sku,warehouse,quantity,unit_cost,unit\n".
        "BNC-HON-FLO-255G,MAIN,100,300,pcs\n".
        "LLD-DRY-LOI-500G,MAIN,50,700,pcs\n",
        'opening'
    );

    $openingPlan = app(PlanOpeningStockImport::class)
        ->handle(OpeningStockFile::read($path), Carbon::parse('2026-08-31'));
    @unlink($path);

    expect($openingPlan->errors)->toBeEmpty()
        // 100 x 300 = 30,000 plus 50 x 700 = 35,000.
        ->and($openingPlan->totalValue()->format())->toBe('65,000.00');

    app(ApplyOpeningStockImport::class)->handle($openingPlan, Carbon::parse('2026-08-31'), $this->user);

    // ⚠⚠ Opening Balance Equity carries the whole opening position and nothing
    // else. Once every opening figure is in it must reach zero - and that is
    // the only check that catches an opening position which balances while
    // being wrong.
    expect(balanceOf('stock_on_hand'))->toBe(6500000)
        ->and(balanceOf('opening_balance_equity'))->toBe(-6500000);

    // ───────────────────────────────────────────────────────────────────────
    // 31 AUGUST: the price list, four tiers, VAT-exclusive.
    // ───────────────────────────────────────────────────────────────────────
    $path = ($this->importFile)(
        "sku,retail_price,dealer_price,distributor_price,corporate_price\n".
        "BNC-HON-FLO-255G,499,445,420,395\n".
        "LLD-DRY-LOI-500G,1260,1130,1070,1005\n",
        'prices'
    );

    ['rows' => $rows, 'ignored' => $ignored] = PriceListFile::read($path);
    $pricePlan = app(PlanPriceListImport::class)->handle($rows, $ignored, Carbon::parse('2026-09-01'));
    @unlink($path);

    expect($pricePlan->errors)->toBeEmpty()
        ->and($pricePlan->creates)->toHaveCount(8)
        ->and($pricePlan->stillUnpriced)->toBeEmpty();

    app(ApplyPriceListImport::class)->handle($pricePlan, Carbon::parse('2026-09-01'), $this->user);

    // ───────────────────────────────────────────────────────────────────────
    // 1 SEPTEMBER: buy more honey. Order, receive, get billed, pay.
    // ───────────────────────────────────────────────────────────────────────
    Carbon::setTestNow('2026-09-01 10:00:00');

    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->honey, 'quantity' => '60', 'unit_cost' => '320.00']],
        createdBy: $this->user,
    );
    $order->approvalRequest->approve($this->user);
    $order = $order->fresh(['lines', 'approvalRequest']);
    $orderLine = $order->lines->first();

    app(RecordGoodsReceipt::class)->handle($order, [['line' => $orderLine, 'quantity' => '60']], $this->user);

    $bill = app(RecordSupplierBill::class)->handle(
        supplier: $this->supplier,
        billNumber: 'RAZZAK-1001',
        billDate: Carbon::parse('2026-09-01'),
        lines: [['line' => $orderLine, 'quantity' => '60', 'unitCost' => '320.00']],
        createdBy: $this->user,
        purchaseOrder: $order,
    );

    app(RecordSupplierPayment::class)->handle(
        supplier: $this->supplier,
        moneyAccount: $this->bank,
        paidOn: '2026-09-01',
        allocations: [['bill' => $bill, 'amount' => '19200.00']],
        createdBy: $this->user,
    );

    // Stock is now 30,000 + 35,000 + 19,200 = 84,200.
    expect(balanceOf('stock_on_hand'))->toBe(8420000);

    // ───────────────────────────────────────────────────────────────────────
    // 2 SEPTEMBER: sell to a dealer on credit, then collect.
    // ───────────────────────────────────────────────────────────────────────
    Carbon::setTestNow('2026-09-02 10:00:00');

    $dealer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Mirpur Dealer',
        'code' => 'MIRPUR',
        'price_group_id' => PriceGroup::where('code', 'DEALER')->value('id'),
    ]);

    $delivery = app(RecordDelivery::class)->handle(
        customer: $dealer,
        warehouse: $this->warehouse,
        lines: [
            ['product' => $this->honey, 'quantity' => '120'],
            ['product' => $this->shutki, 'quantity' => '20'],
        ],
        createdBy: $this->user,
        deliveredAt: '2026-09-02',
    );

    $invoice = app(RecordSalesInvoice::class)->handle(
        customer: $dealer,
        lines: $delivery->lines->map(fn ($line) => [
            'deliveryLine' => $line,
            'quantity' => (string) (float) $line->quantity,
            // ⚠ The DEALER price, resolved by the importer's own rules.
            'unitPrice' => $line->product_id === $this->honey->id ? '445' : '1130',
        ])->all(),
        createdBy: $this->user,
        invoiceDate: '2026-09-02',
        dueDate: '2026-09-30',
    );

    // Revenue: 120 x 445 = 53,400 plus 20 x 1,130 = 22,600 = 76,000.
    expect((float) $invoice->total)->toBe(76000.0);

    // ⚠ The clock moves with the story. A journal dated ahead of "now" is
    // refused, which is correct and is why every step here sets the day it
    // happens on rather than backdating from the end.
    Carbon::setTestNow('2026-09-05 10:00:00');

    app(RecordCustomerReceipt::class)->handle(
        customer: $dealer,
        moneyAccount: $this->bank,
        receivedOn: '2026-09-05',
        allocations: [['invoice' => $invoice, 'amount' => '50000.00']],
        createdBy: $this->user,
    );

    // ───────────────────────────────────────────────────────────────────────
    // 30 SEPTEMBER: does everything agree?
    // ───────────────────────────────────────────────────────────────────────
    Carbon::setTestNow('2026-09-30 18:00:00');

    // 1. The ledger agrees with itself.
    $trial = TrialBalance::between(Carbon::parse('2026-08-01'), Carbon::parse('2026-09-30'));
    expect($trial->balances())->toBeTrue();

    // 2. Every subsidiary ledger agrees with its control account.
    $reconciliation = SubledgerReconciliation::reconcileAllBusinesses();
    TenantScope::enter($this->business->id);
    expect($reconciliation['problems'])->toBeEmpty()
        ->and($reconciliation['warnings'])->toBeEmpty()
        ->and($reconciliation['checks'])->toBeGreaterThan(0);

    // 3. COGS, computed by hand from what actually left the shelves.
    //    Honey FIFO: 100 opening at 300 = 30,000, then 20 of the new layer at
    //    320 = 6,400. Shutki: 20 at 700 = 14,000. Total 50,400.
    $cogs = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    expect($cogs->totalCost()->format())->toBe('50,400.00')
        ->and($cogs->totalRevenue()->format())->toBe('76,000.00')
        ->and($cogs->grossProfit()->format())->toBe('25,600.00')
        ->and($cogs->tiesOut())->toBeTrue();

    // 4. Brand-wise, which is the requirement A6 actually asked for.
    //    Bionic: revenue 53,400, cost 36,400, profit 17,000.
    //    LaLaDia: revenue 22,600, cost 14,000, profit 8,600.
    $byBrand = $cogs->byBrand()->keyBy('brand');

    expect($byBrand['Bionic']['revenue']->format())->toBe('53,400.00')
        ->and($byBrand['Bionic']['cost']->format())->toBe('36,400.00')
        ->and($byBrand['Bionic']['gross_profit']->format())->toBe('17,000.00')
        ->and($byBrand['LaLaDia']['revenue']->format())->toBe('22,600.00')
        ->and($byBrand['LaLaDia']['cost']->format())->toBe('14,000.00')
        ->and($byBrand['LaLaDia']['gross_profit']->format())->toBe('8,600.00');

    // 5. What is left on the shelf: 40 honey at 320 = 12,800, 30 shutki at
    //    700 = 21,000, so 33,800.
    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    expect($valuation->total()->format())->toBe('33,800.00')
        ->and($valuation->tiesOut())->toBeTrue()
        ->and($valuation->anomalies())->toBeEmpty();

    // ⚠⚠ THE CROSS-CHECK. Two engines over two different sources: everything
    // that came in (65,000 opening + 19,200 bought) less everything COGS took
    // out (50,400) must equal what the valuation says is left (33,800).
    expect($valuation->total()->minor + $cogs->totalCost()->minor)->toBe(6500000 + 1920000);

    // 6. The statements.
    $pnl = IncomeStatement::between(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
    expect($pnl->grossProfit()->format())->toBe('25,600.00');

    $sheet = BalanceSheet::asAt(Carbon::parse('2026-09-30'), $this->business);
    expect($sheet->balances())->toBeTrue();

    // 7. And the money. Bank: paid 19,200 out, took 50,000 in = +30,800.
    //    Receivable: invoiced 76,000, collected 50,000 = 26,000 outstanding.
    expect(balanceOf('accounts_receivable'))->toBe(2600000);
});

function balanceOf(string $slug): int
{
    return (int) DB::table('journal_lines')
        ->where('account_id', Account::bySlug($slug)->id)
        ->sum('amount_minor');
}
