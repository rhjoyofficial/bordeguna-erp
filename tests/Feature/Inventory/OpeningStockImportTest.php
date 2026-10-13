<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\CostOfGoodsSold;
use App\Modules\Accounting\Support\InventoryValuation;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\ApplyOpeningStockImport;
use App\Modules\Inventory\Actions\PlanOpeningStockImport;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OpeningStockFile;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;

/**
 * The 31 August count, imported.
 *
 * ⚠⚠ THE HIGHEST-STAKES IMPORT IN THE SYSTEM. An opening stock document is
 * written ONCE PER WAREHOUSE for the life of the business, its unit costs decide
 * every margin reported until that stock sells through, and its total has to
 * equal the stock line on the accountant's opening trial balance to the paisa or
 * Opening Balance Equity never reaches zero.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-01 10:00:00');

    $this->business = Business::create(['name' => 'Opening Co', 'slug' => 'opening-co']);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    TenantScope::enter($this->business->id);

    $this->unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true]);
    $this->shop = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SHOP', 'is_active' => true]);

    $this->product = function (string $sku, bool $active = true) {
        return Product::create([
            'business_id' => $this->business->id,
            'unit_of_measure_id' => $this->unit->id,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'type' => ProductType::FinishedGood,
            'is_active' => $active,
        ]);
    };

    $this->plan = function (string $csv, string $asAt = '2026-08-31') {
        $path = tempnam(sys_get_temp_dir(), 'opening').'.csv';
        file_put_contents($path, $csv);
        $rows = OpeningStockFile::read($path);
        @unlink($path);

        return app(PlanOpeningStockImport::class)->handle($rows, Carbon::parse($asAt));
    };

    $this->apply = fn ($plan, string $asAt = '2026-08-31') => app(ApplyOpeningStockImport::class)
        ->handle($plan, Carbon::parse($asAt), $this->user);
});

afterEach(fn () => Carbon::setTestNow());

it('imports a two-warehouse count and posts it as an opening balance', function () {
    ($this->product)('SKU-1');
    ($this->product)('SKU-2');

    $plan = ($this->plan)(
        "sku,warehouse,quantity,unit_cost\n".
        "SKU-1,MAIN,100,25\n".
        "SKU-2,MAIN,40,10\n".
        "SKU-1,SHOP,20,25\n"
    );

    expect($plan->errors)->toBeEmpty()
        ->and($plan->warehouses)->toHaveCount(2)
        ->and($plan->lineCount())->toBe(3)
        // 2,500 + 400 + 500
        ->and($plan->totalValue()->format())->toBe('3,400.00');

    $result = ($this->apply)($plan);

    expect($result['documents'])->toBe(2)
        ->and($result['lines'])->toBe(3)
        ->and(OpeningStock::count())->toBe(2);

    // ⚠⚠ The economics: Stock on Hand debited, Opening Balance Equity credited,
    // by exactly the same amount.
    $stock = ledgerBalance('stock_on_hand');
    $equity = ledgerBalance('opening_balance_equity');

    expect($stock)->toBe(340000)
        ->and($equity)->toBe(-340000);
});

it('feeds the inventory valuation exactly, and ties to the ledger', function () {
    ($this->product)('SKU-1');

    ($this->apply)(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,100,25\n"));

    $valuation = InventoryValuation::asAt(Carbon::parse('2026-08-31'));

    expect($valuation->total()->format())->toBe('2,500.00')
        ->and($valuation->tiesOut())->toBeTrue()
        ->and((float) $valuation->rows->first()->quantity)->toBe(100.0)
        ->and($valuation->rows->first()->unitCost())->toBe('25.0000');
});

it('is invisible to a valuation dated BEFORE the count', function () {
    // ⚠ The opening position is dated 31 August. On the 30th the business had
    // nothing in this system, and the report must say so.
    ($this->product)('SKU-1');

    ($this->apply)(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,100,25\n"));

    expect(InventoryValuation::asAt(Carbon::parse('2026-08-30'))->total()->format())->toBe('0.00')
        ->and(InventoryValuation::asAt(Carbon::parse('2026-08-31'))->total()->format())->toBe('2,500.00');
});

it('feeds FIFO in received order, and COGS uses the opening cost', function () {
    // ⚠⚠ THE POINT OF THE WHOLE EXERCISE. Opening stock is the first cost layer
    // the business ever has, so the first sale must consume it at the cost the
    // count sheet stated - not at some later purchase price.
    $product = ($this->product)('SKU-1');

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $product->id,
        'unit_price' => '60',
        'effective_from' => '2026-01-01',
    ]);

    ($this->apply)(($this->plan)(
        "sku,warehouse,quantity,unit_cost,batch_number\n".
        "SKU-1,MAIN,10,25,OPEN-A\n".
        "SKU-1,MAIN,10,40,OPEN-B\n"
    ));

    $cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Till',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    Carbon::setTestNow('2026-09-01 10:00:00');
    app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->main,
        lines: [['product' => $product, 'quantity' => '12']],
        createdBy: $this->user,
        moneyAccount: $cash,
    );

    // FIFO: all 10 of the 25 layer, then 2 of the 40 layer = 250 + 80 = 330.
    $cogs = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    expect($cogs->totalCost()->format())->toBe('330.00')
        ->and($cogs->tiesOut())->toBeTrue()
        // Revenue 12 x 60 = 720, so gross profit is 390.
        ->and($cogs->totalRevenue()->format())->toBe('720.00')
        ->and($cogs->grossProfit()->format())->toBe('390.00');

    // And what remains is 8 of the 40 layer = 320.
    $valuation = InventoryValuation::asAt(Carbon::parse('2026-09-30'));

    expect($valuation->total()->format())->toBe('320.00')
        ->and($valuation->tiesOut())->toBeTrue();

    // ⚠ The cross-check: opened 650, sold 330 of it, 320 left.
    expect($valuation->total()->minor + $cogs->totalCost()->minor)->toBe(65000);
});

it('REFUSES a warehouse that already has opening stock', function () {
    // C-OPN-1. The engine would catch this too, but only after the earlier
    // warehouses had been written - and the document cannot be redone.
    ($this->product)('SKU-1');

    ($this->apply)(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,100,25\n"));

    $second = ($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,50,25\n");

    expect($second->hasErrors())->toBeTrue()
        ->and($second->errors[0]['message'])->toContain('ALREADY been recorded')
        ->and(OpeningStock::count())->toBe(1);
});

it('writes NOTHING when one warehouse in the file is already recorded', function () {
    // ⚠⚠ All-or-nothing. A file covering four warehouses where one is already
    // done must not write the other three.
    ($this->product)('SKU-1');

    ($this->apply)(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,SHOP,5,10\n"));

    $plan = ($this->plan)(
        "sku,warehouse,quantity,unit_cost\n".
        "SKU-1,MAIN,100,25\n".
        "SKU-1,SHOP,50,25\n"
    );

    expect($plan->hasErrors())->toBeTrue();
    expect(fn () => ($this->apply)($plan))->toThrow(RuntimeException::class);

    // MAIN was never written, even though its own rows were fine.
    expect(OpeningStock::count())->toBe(1)
        ->and(OpeningStock::first()->warehouse_id)->toBe($this->shop->id);
});

it('refuses a plan that went stale between review and apply', function () {
    // ⚠⚠ Joy's rule against stale mappings, made concrete: the plan is built,
    // somebody else records the document, and the apply must fail loudly rather
    // than collide with a constraint halfway through.
    ($this->product)('SKU-1');

    $plan = ($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,100,25\n");

    // The world moves after the plan is built.
    ($this->apply)(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,7,25\n"));

    expect(fn () => ($this->apply)($plan))
        ->toThrow(RuntimeException::class, 'recorded after this plan was built');

    expect(OpeningStock::count())->toBe(1)
        ->and(StockBatch::sum('current_quantity'))->toEqual(7);
});

it('catches the same product counted twice in one warehouse', function () {
    // C-OPN-3. A double count balances perfectly: it debits stock and credits
    // equity twice, just as correctly as once.
    ($this->product)('SKU-1');

    $plan = ($this->plan)(
        "sku,warehouse,quantity,unit_cost\n".
        "SKU-1,MAIN,100,25\n".
        "SKU-1,MAIN,60,25\n"
    );

    expect($plan->hasErrors())->toBeTrue()
        ->and($plan->errors[0]['message'])->toContain('already counted');
});

it('allows the same product twice when the layers are named', function () {
    ($this->product)('SKU-1');

    $plan = ($this->plan)(
        "sku,warehouse,quantity,unit_cost,batch_number\n".
        "SKU-1,MAIN,100,25,A\n".
        "SKU-1,MAIN,60,30,B\n"
    );

    expect($plan->errors)->toBeEmpty()
        ->and($plan->lineCount())->toBe(2)
        // 2,500 + 1,800
        ->and($plan->totalValue()->format())->toBe('4,300.00');
});

it('refuses a quantity in the wrong unit', function () {
    // C-OPN-4. A count sheet saying kg against a product held in pieces.
    ($this->product)('SKU-1');

    $plan = ($this->plan)("sku,warehouse,quantity,unit_cost,unit\nSKU-1,MAIN,100,25,kg\n");

    expect($plan->hasErrors())->toBeTrue()
        ->and($plan->errors[0]['message'])->toContain("held in 'pcs'");
});

it('accepts the matching unit', function () {
    ($this->product)('SKU-1');

    expect(($this->plan)("sku,warehouse,quantity,unit_cost,unit\nSKU-1,MAIN,100,25,PCS\n")->errors)->toBeEmpty();
});

it('refuses an unknown SKU, an unknown warehouse and an inactive product', function () {
    ($this->product)('SKU-1');
    ($this->product)('OLD-1', false);

    expect(($this->plan)("sku,warehouse,quantity,unit_cost\nGHOST,MAIN,1,1\n")->errors[0]['message'])
        ->toContain('never creates products');

    // ⚠ An ACTIVE product here on purpose. Using the inactive one would trip the
    // inactive check first and this line would pass without ever reaching the
    // warehouse lookup it claims to test.
    expect(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,NOWHERE,1,1\n")->errors[0]['message'])
        ->toContain("warehouse with code 'NOWHERE'");

    expect(($this->plan)("sku,warehouse,quantity,unit_cost\nOLD-1,MAIN,1,1\n")->errors[0]['message'])
        ->toContain('inactive');
});

it('refuses a zero or negative quantity, and a negative cost', function () {
    ($this->product)('SKU-1');

    expect(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,0,25\n")->errors[0]['message'])
        ->toContain('more than zero');

    expect(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,-5,25\n")->errors[0]['message'])
        ->toContain('more than zero');

    expect(($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,5,-1\n")->errors[0]['message'])
        ->toContain('zero or more');
});

it('warns on a zero cost rather than refusing it', function () {
    // C-OPN-5. Donated or sampled goods are real.
    ($this->product)('SKU-1');

    $plan = ($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,5,0\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->warnings[0]['message'])->toContain('donated');
});

it('never applies a plan carrying errors', function () {
    ($this->product)('SKU-1');

    $plan = ($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,100,25\nGHOST,MAIN,1,1\n");

    expect(fn () => ($this->apply)($plan))->toThrow(RuntimeException::class);
    expect(OpeningStock::count())->toBe(0)
        ->and(StockBatch::count())->toBe(0);
});

it('refuses an unrecognised column outright', function () {
    // ⚠ Stricter than the price file on purpose: a price list can be re-run
    // tomorrow, an opening stock document cannot.
    $path = tempnam(sys_get_temp_dir(), 'opening').'.csv';
    file_put_contents($path, "sku,warehouse,qty,unit_cost\nSKU-1,MAIN,1,1\n");

    expect(fn () => OpeningStockFile::read($path))
        ->toThrow(RuntimeException::class, 'missing mandatory column');

    file_put_contents($path, "sku,warehouse,quantity,unit_cost,colour\nSKU-1,MAIN,1,1,red\n");

    expect(fn () => OpeningStockFile::read($path))
        ->toThrow(RuntimeException::class, 'unrecognised column');

    @unlink($path);
});

function ledgerBalance(string $slug): int
{
    return (int) DB::table('journal_lines')
        ->where('account_id', Account::bySlug($slug)->id)
        ->sum('amount_minor');
}

it('refuses an expiry-tracked product counted without its batch and expiry', function () {
    ($this->product)('SKU-1')->update(['tracks_expiry' => true]);

    $plan = ($this->plan)("sku,warehouse,quantity,unit_cost\nSKU-1,MAIN,10,25\n");

    expect($plan->errors)->toHaveCount(2)
        ->and($plan->errors[0]['message'])->toContain('batch number')
        ->and($plan->errors[1]['message'])->toContain('expiry date')
        ->and($plan->lineCount())->toBe(0);

    $plan = ($this->plan)("sku,warehouse,quantity,unit_cost,batch_number,expiry_date\nSKU-1,MAIN,10,25,B-1,2027-03-31\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->lineCount())->toBe(1);
});
