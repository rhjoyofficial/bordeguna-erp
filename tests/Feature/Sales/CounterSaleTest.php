<?php

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Exceptions\NoSellingPriceException;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales slice 5: a walk-in sale, end to end.
 *
 * ▶▶ THE MILESTONE: a sale that reduces stock, records revenue, posts cost of
 * goods sold, and leaves the trial balance balancing.
 *
 * ⚠⚠ It is not a fourth kind of sale. It calls the same three actions a
 * distributor sale uses, in one transaction, so both bridge accounts net to
 * zero instantly and what remains is the simple four-line result.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Counter Co', 'slug' => 'counter-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Uttar Adabor', 'code' => 'UA']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Till',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    Carbon::setTestNow('2026-08-04 10:00:00');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '500', $this->user, occurredAt: '2026-08-01 09:00:00');
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '600', $this->user, occurredAt: '2026-08-02 09:00:00');

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '850',
        'effective_from' => '2026-01-01',
    ]);

    $this->sell = fn (string $qty, ?MoneyAccount $account = null, string $discount = '0') => app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $this->user,
        moneyAccount: $account ?? $this->cash,
        invoiceDiscount: $discount,
    );

    $this->balanceOf = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

// ▶▶ THE MILESTONE, stated as one test.
it('reduces stock, records revenue, posts COGS and leaves the books balancing', function () {
    $sale = ($this->sell)('15');

    // 10 at 500 + 5 at 600 = 8000 cost. 15 at 850 = 12750 revenue.
    expect($this->product->fresh()->currentStockQuantity())->toBe('5')
        ->and((float) $sale->invoice->total)->toBe(12750.0)
        ->and(($this->balanceOf)('sales_revenue'))->toBe(-1275000)
        ->and(($this->balanceOf)('cost_of_goods_sold'))->toBe(800000)
        ->and(($this->balanceOf)('stock_on_hand'))->toBe(-800000)
        ->and(($this->balanceOf)('cash_in_hand'))->toBe(1275000);

    // ⚠⚠ The trial balance. Every line ever written, summing to zero.
    expect((int) JournalLine::sum('amount_minor'))->toBe(0);
});

// ⚠⚠ THE BRIDGES COLLAPSE. Both are debited and credited inside the same
// transaction, so nothing is left standing on the books at a counter.
it('leaves both bridge accounts at exactly zero', function () {
    ($this->sell)('15');

    expect(($this->balanceOf)('goods_delivered_not_invoiced'))->toBe(0)
        ->and(($this->balanceOf)('accounts_receivable'))->toBe(0);
});

// ⚠ Not a fourth kind of sale: all three real documents exist.
it('produces a delivery, an invoice and a receipt', function () {
    $sale = ($this->sell)('5');

    expect($sale->delivery)->toBeInstanceOf(Delivery::class)
        ->and($sale->invoice)->toBeInstanceOf(SalesInvoice::class)
        ->and($sale->receipt)->not->toBeNull()
        ->and($sale->delivery->delivery_number)->toStartWith('DC-')
        ->and($sale->invoice->invoice_number)->toStartWith('INV-')
        ->and(Delivery::count())->toBe(1)
        ->and(SalesInvoice::count())->toBe(1);
});

it('prices the line from the resolver when the counter does not override it', function () {
    $sale = ($this->sell)('2');

    expect((float) $sale->invoice->lines->first()->unit_price)->toBe(850.0);
});

it('lets the counter override the price', function () {
    $sale = app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '2', 'unitPrice' => '900']],
        createdBy: $this->user,
        moneyAccount: $this->cash,
    );

    expect((float) $sale->invoice->lines->first()->unit_price)->toBe(900.0);
});

// ⚠ Selling at a price nobody set is how a counter gives stock away, and a
// zero-price sale still posts COGS - so it balances and reads as a bad margin
// months later rather than as the error it is.
it('refuses to sell a product nothing prices', function () {
    ProductPrice::query()->delete();

    ($this->sell)('1');
})->throws(NoSellingPriceException::class);

// ⚠⚠ ONE TRANSACTION. A till cannot half-complete a sale.
it('leaves nothing behind when the stock is not there', function () {
    try {
        ($this->sell)('999');
    } catch (\App\Modules\Inventory\Exceptions\InsufficientStockException) {
        // expected
    }

    expect(Delivery::count())->toBe(0)
        ->and(SalesInvoice::count())->toBe(0)
        ->and((int) JournalLine::count())->toBe(0)
        ->and($this->product->fresh()->currentStockQuantity())->toBe('20');
});

// ⚠ The seam where retail meets credit: no money account means no receipt, the
// invoice stands open, and the SAME documents describe both.
it('lets a customer take goods on account, leaving the receivable open', function () {
    $sale = app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '5']],
        createdBy: $this->user,
        moneyAccount: null,
    );

    expect($sale->receipt)->toBeNull()
        ->and($sale->invoice->fresh()->outstanding()->toTaka())->toBe('4250.00')
        ->and(($this->balanceOf)('accounts_receivable'))->toBe(425000)
        // ⚠ The stock still left and the revenue was still earned.
        ->and(($this->balanceOf)('sales_revenue'))->toBe(-425000)
        ->and(($this->balanceOf)('goods_delivered_not_invoiced'))->toBe(0);
});

it('applies an invoice-level discount and still balances', function () {
    ($this->sell)('10', null, '500');

    // 10 at 850 = 8500 revenue, less 500 discount = 8000 taken.
    expect(($this->balanceOf)('sales_revenue'))->toBe(-850000)
        ->and(($this->balanceOf)('discounts_allowed'))->toBe(50000)
        ->and(($this->balanceOf)('cash_in_hand'))->toBe(800000)
        ->and(($this->balanceOf)('accounts_receivable'))->toBe(0)
        ->and((int) JournalLine::sum('amount_minor'))->toBe(0);
});

// ⚠ R4: three locations, and a counter sale must not consume another
// warehouse's stock.
it('sells only from the warehouse it was made in', function () {
    $savar = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);

    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $savar,
        lines: [['product' => $this->product, 'quantity' => '1']],
        createdBy: $this->user,
        moneyAccount: $this->cash,
    );
})->throws(\App\Modules\Inventory\Exceptions\InsufficientStockException::class);
