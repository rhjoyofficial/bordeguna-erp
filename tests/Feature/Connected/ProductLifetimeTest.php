<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ProductLifetime;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Support\Lifetime\LifetimeRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * B23 lifetime views (25 Oct 2026; R13, R76): the product page's lifetime
 * totals, money, runs, "used in" and profit per batch, on the same world as
 * ProductPageTest (PR5 connected-360, 18 Oct 2026): the product page. The world is the batch
 * trace's ghee lesson (10 kg of ghee from Ad-Deen Food on PO-000001, 18 jars
 * made in one run, 5 to a dealer on a challan and an invoice, 1 back, 2 at
 * the counter), so every movement on the stock card has a real document.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 12:00:00');

    $this->business = Business::create(['name' => 'Trace Co', 'slug' => 'trace-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['inventory.reports.view', 'inventory.stock.adjust', 'inventory.stock.approve', 'inventory.production.manage', 'sales.reports.view', 'sales.reports.costs', 'purchasing.reports.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->savar = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $this->shop = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SHP']);
    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $pcs = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $gram = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Gram', 'abbreviation' => 'g']);
    $brand = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);

    $this->ghee = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'BDG-RM-GHEE-KG',
        'name' => 'Ghee (bulk)', 'type' => ProductType::RawMaterial, 'tracks_expiry' => true,
    ]);
    $this->jar = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $pcs->id, 'sku' => 'PK-JAR-500ML',
        'name' => 'Glass jar 500 ml', 'type' => ProductType::RawMaterial,
    ]);
    $this->pure = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $pcs->id, 'brand_id' => $brand->id,
        'sku' => 'LLD-GHE-PURE-500G', 'name' => 'LaLaDia Pure Ghee 500g', 'type' => ProductType::FinishedGood,
        'tracks_expiry' => true, 'mrp' => '950', 'barcode' => '8801234567890',
        'content_quantity' => '500', 'content_unit_id' => $gram->id,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->pure->id, 'unit_price' => '950', 'effective_from' => '2026-01-01']);

    $this->manager = User::factory()->create(['name' => 'Joy']);
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->givePermissionTo(['inventory.reports.view', 'inventory.stock.adjust', 'inventory.production.manage', 'sales.reports.view', 'purchasing.reports.view']);

    // Reads stock, nothing about customers, cannot hold.
    $this->storekeeper = User::factory()->create();
    $this->storekeeper->businesses()->attach($this->business->id);
    $this->storekeeper->givePermissionTo(['inventory.reports.view']);

    $this->as = fn (User $user) => $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);

    // ── Buy: PO-000001, received with the supplier's challan ADF-1017.
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Ad-Deen Food']);
    $this->po = PurchaseOrder::create([
        'business_id' => $this->business->id, 'po_number' => 'PO-000001', 'supplier_id' => $supplier->id,
        'warehouse_id' => $this->savar->id, 'status' => PurchaseOrderStatus::Received, 'created_by' => $this->manager->id,
    ]);
    $poLine = PurchaseOrderLine::create([
        'business_id' => $this->business->id, 'purchase_order_id' => $this->po->id, 'product_id' => $this->ghee->id,
        'quantity_ordered' => '10', 'quantity_received' => '10', 'unit_cost' => '1400',
    ]);
    $receipt = GoodsReceipt::create([
        'business_id' => $this->business->id, 'purchase_order_id' => $this->po->id, 'received_by' => $this->manager->id,
        'received_at' => '2026-10-17 09:00:00', 'supplier_challan_number' => 'ADF-1017',
    ]);
    $gheeIn = app(RecordStockIn::class)->handle(
        product: $this->ghee, warehouse: $this->savar, quantity: '10', unitCost: '1400', performedBy: $this->manager,
        batchNumber: 'GH-2610-01', expiryDate: '2027-09-30', occurredAt: '2026-10-17 09:00:00', manufacturedDate: '2026-10-01',
    );
    GoodsReceiptLine::create([
        'business_id' => $this->business->id, 'goods_receipt_id' => $receipt->id, 'purchase_order_line_id' => $poLine->id,
        'quantity_received' => '10', 'stock_movement_id' => $gheeIn->id,
    ]);
    $this->gheeBatch = StockBatch::findOrFail($gheeIn->batch_id);
    app(RecordStockIn::class)->handle($this->jar, $this->savar, '20', '35', $this->manager, occurredAt: '2026-10-17 09:00:00');

    // ── Make: 18 jars, PRN-..., expiry 31 Mar 2027, MRP 950.
    $runs = app(RecordProductionRun::class);
    $this->run = $runs->confirm($runs->draft(
        warehouse: $this->savar,
        outputProduct: $this->pure,
        outputQuantity: '18',
        lines: [
            ['product' => $this->ghee, 'quantity' => '9.18', 'waste' => '0.18'],
            ['product' => $this->jar, 'quantity' => '18', 'waste' => '0'],
        ],
        createdBy: $this->manager,
        occurredAt: '2026-10-17 10:00:00',
        outputExpiryDate: '2027-03-31',
        outputMrp: '950',
    ), $this->manager);
    $this->jars = $this->run->outputBatch;

    // ── Sell: 5 to the dealer on a challan, invoiced; 1 comes back.
    $this->dealer = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Practice Dealer Store', 'type' => CustomerType::Dealer, 'phone' => '01711000000',
    ]);
    $this->challan = app(RecordDelivery::class)->handle(
        customer: $this->dealer, warehouse: $this->savar,
        lines: [['product' => $this->pure, 'quantity' => '5']], createdBy: $this->manager,
    );
    $this->invoice = app(RecordSalesInvoice::class)->handle(
        customer: $this->dealer,
        lines: [['deliveryLine' => $this->challan->lines->first(), 'quantity' => '5', 'unitPrice' => '807.50']],
        createdBy: $this->manager,
    );
    $this->return = app(RecordSalesReturn::class)->handle(
        delivery: $this->challan->fresh('lines'),
        lines: [['deliveryLine' => $this->challan->lines->first()->fresh(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0']],
        createdBy: $this->manager,
    );

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash in Hand', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);
    $this->counter = fn (string $qty, ?Warehouse $warehouse = null) => app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(), warehouse: $warehouse ?? $this->savar,
        lines: [['product' => $this->pure, 'quantity' => $qty]], createdBy: $this->manager, moneyAccount: $this->cash,
    );
    ($this->counter)('2');
});

afterEach(fn () => Carbon::setTestNow());

it('totals a raw material over its whole life: bought, then used in a run', function () {
    $totals = collect(($this->as)($this->manager)->get("/inventory/products/{$this->ghee->id}")
        ->assertOk()
        ->viewData('page')['props']['lifetime']['totals'])->keyBy('key');

    expect($totals['receipt_in']['quantity'])->toBe('10')
        ->and($totals['receipt_in']['value'])->toBe('14,000.00')
        ->and($totals['production_out']['quantity'])->toBe('9.18');
});

it('totals a finished good: made, sold, returned, and the margin for a cost reader', function () {
    $this->manager->givePermissionTo('sales.reports.costs');

    $lifetime = ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}")
        ->assertOk()
        ->viewData('page')['props']['lifetime'];
    $totals = collect($lifetime['totals'])->keyBy('key');

    expect($totals['production_in']['quantity'])->toBe('18')
        ->and($totals['sale_out']['quantity'])->toBe('7')
        ->and($totals['sales_return_in']['quantity'])->toBe('1')
        ->and($lifetime['money'])->not->toBeNull()
        ->and($lifetime['money']['returned_quantity'])->toBe('1')
        ->and($lifetime['money']['cost_of_sales'])->toBe($totals['sale_out']['value']);

    // Margin = (revenue - returns credit) - (cost of sales - cost given back).
    $m = fn (string $v) => (int) round(((float) str_replace(',', '', $v)) * 100);
    $money = $lifetime['money'];
    expect($m($money['margin']))->toBe(($m($money['revenue']) - $m($money['returns_credit'])) - ($m($money['cost_of_sales']) - $m($money['returns_cost'])));

    // The run that made it, and where the ghee went.
    expect($lifetime['runs'][0]['document']['type'])->toBe('production_run')
        ->and($lifetime['runs'][0]['quantity'])->toBe('18');

    $ghee = ($this->as)($this->manager)->get("/inventory/products/{$this->ghee->id}")->viewData('page')['props']['lifetime'];
    expect($ghee['used_in']['runs'][0]['output_product'])->toBe('LaLaDia Pure Ghee 500g')
        ->and($ghee['used_in']['runs'][0]['quantity'])->toBe('9.18')
        ->and($ghee['by_supplier'][0]['name'])->toBe('Ad-Deen Food')
        ->and($ghee['by_supplier'][0]['quantity'])->toBe('10')
        ->and($ghee['by_supplier'][0]['value'])->toBe('14,000.00');
});

it('shows profit per batch: the run batch, what of it was sold, its revenue and margin', function () {
    $this->manager->givePermissionTo('sales.reports.costs');

    $batches = collect(($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}")
        ->viewData('page')['props']['lifetime']['batches']);
    $row = $batches->firstWhere('id', $this->jars->id);

    // 5 to the dealer less 1 back, and 2 at the counter.
    expect($row['source'])->toBe('production')
        ->and($row['document']['type'])->toBe('production_run')
        ->and($row['received'])->toBe('18')
        ->and($row['sold'])->toBe('6')
        ->and($row['revenue'])->not->toBeNull();

    $m = fn (string $v) => (int) round(((float) str_replace(',', '', $v)) * 100);
    expect($m($row['margin']))->toBe($m($row['revenue']) - $m($row['cost_of_sold']));

    // The ghee batch came from the supplier's receipt.
    $ghee = collect(($this->as)($this->manager)->get("/inventory/products/{$this->ghee->id}")
        ->viewData('page')['props']['lifetime']['batches'])->firstWhere('id', $this->gheeBatch->id);
    expect($ghee['source'])->toBe('receipt')
        ->and($ghee['supplier']['name'])->toBe('Ad-Deen Food')
        ->and($ghee['document']['type'])->toBe('goods_receipt');
});

it('keeps revenue and margin from a reader without the sales cost reports, and suppliers from a storekeeper', function () {
    $lifetime = ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}")->viewData('page')['props']['lifetime'];
    expect($lifetime['money'])->toBeNull()
        ->and($lifetime['sees_margin'])->toBeFalse()
        ->and(collect($lifetime['batches'])->pluck('revenue')->filter()->all())->toBe([]);

    $store = ($this->as)($this->storekeeper)->get("/inventory/products/{$this->ghee->id}")->assertOk()->viewData('page')['props']['lifetime'];
    expect($store['by_supplier'])->toBeNull()
        ->and($store['money'])->toBeNull()
        ->and($store['totals'])->not->toBeEmpty();
});

it('narrows every lifetime figure to a date window, all time by default', function () {
    $page = ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}")->viewData('page')['props'];
    expect($page['range'])->toBe(['from' => null, 'to' => null, 'all_time' => true]);

    $before = ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}?from=2026-01-01&to=2026-10-16")->viewData('page')['props'];
    expect($before['range']['from'])->toBe('2026-01-01')
        ->and($before['lifetime']['totals'])->toBe([])
        ->and($before['lifetime']['runs'])->toBe([]);

    // A typo is ignored, not refused.
    ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}?from=17/10/2026")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('range.all_time', true));
});

it('reads the lifetime in a fixed number of queries however many batches and sales there are', function () {
    $this->manager->givePermissionTo('sales.reports.costs');
    $read = function () {
        $lifetime = new ProductLifetime($this->pure->fresh(), $this->manager->fresh(), LifetimeRange::allTime());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $lifetime->totals();
        $lifetime->writeOffs();
        $lifetime->money();
        $lifetime->bySupplier();
        $lifetime->runs();
        $lifetime->usedIn();
        $lifetime->batchProfit();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $first = $read();

    foreach (['3', '4', '5'] as $i => $qty) {
        app(RecordStockIn::class)->handle($this->pure, $this->savar, '10', '700', $this->manager, batchNumber: "EXTRA-{$i}", occurredAt: '2026-10-17 11:00:00');
        ($this->counter)($qty);
    }

    expect($read())->toBe($first);
});

it('exports profit per batch as a spreadsheet', function () {
    $response = ($this->as)($this->manager)->get("/inventory/products/{$this->ghee->id}/lifetime-export")->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('GH-2610-01')
        ->and($csv)->toContain('Ad-Deen Food');

    ($this->as)($this->storekeeper)->get("/inventory/products/{$this->ghee->id}/lifetime-export")->assertOk();
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);
    ($this->as)($nobody)->get("/inventory/products/{$this->ghee->id}/lifetime-export")->assertForbidden();
});
