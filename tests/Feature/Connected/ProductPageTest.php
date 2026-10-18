<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
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
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * PR5 connected-360 (18 Oct 2026): the product page. The world is the batch
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

    foreach (['inventory.reports.view', 'inventory.stock.adjust', 'inventory.stock.approve', 'inventory.production.manage', 'sales.reports.view', 'purchasing.reports.view'] as $name) {
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

it('opens a product page with the stock by warehouse, costs, last purchase and suppliers', function () {
    ($this->as)($this->manager)->get("/inventory/products/{$this->ghee->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/products/show')
            ->where('product.name', 'Ghee (bulk)')
            ->where('summary.on_hand', '0.82')
            ->where('summary.average_cost', '1,400.00')
            ->where('by_warehouse.0.warehouse', 'Savar')
            ->where('last_purchase.rate', '1,400.00')
            ->where('last_purchase.supplier.name', 'Ad-Deen Food')
            ->where('suppliers.0.name', 'Ad-Deen Food')
            ->where('purchases.0.document.number', 'PO-000001')
            ->where('purchases.0.document.url', "/purchasing/purchase-orders/{$this->po->id}")
            ->where('batches.0.batch_number', 'GH-2610-01'));
});

it('shows the recipe and selling price of a finished product', function () {
    ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selling.mrp', '950.00')
            ->where('selling.rules.0.price', '950.00')
            ->where('sales.0.document.number', fn ($number) => $number !== null)
            ->where('sales.0.customer', fn ($name) => in_array($name, ['Practice Dealer Store', Customer::walkIn()->name], true)));
});

it('lists every movement on the stock card with a running balance and the document behind it', function () {
    $reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Restock', 'is_active' => true]);
    $transfer = app(DispatchStockTransfer::class)->handle($this->pure, $this->savar, $this->shop, '4', $reason, $this->manager);
    app(ReceiveStockTransfer::class)->handle($transfer, $this->manager);

    $response = ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}")->assertOk();
    $card = $response->viewData('page')['props']['card'];
    $rows = collect($card['rows']['data']);

    // In from the run, out on the challan, back on the return, out at the
    // counter, out and in on the transfer.
    expect($rows->pluck('reason')->unique()->sort()->values()->all())->toBe(['production', 'sale', 'sales_return', 'transfer'])
        ->and($rows->first()['in'])->toBe('18')
        ->and($rows->first()['balance'])->toBe('18')
        ->and($rows->first()['document']['type'])->toBe('production_run')
        ->and($rows->first()['document']['url'])->toBe("/inventory/production-runs/{$this->run->id}")
        ->and($rows->firstWhere('reason', 'sales_return')['document']['type'])->toBe('sales_return')
        ->and($rows->firstWhere('reason', 'transfer')['document']['type'])->toBe('stock_transfer')
        ->and($card['closing']['quantity'])->toBe('12')
        ->and($rows->last()['balance'])->toBe('12');

    $challanRow = $rows->first(fn (array $row) => ($row['document']['type'] ?? null) === 'delivery');
    expect($challanRow['document']['number'])->toBe($this->challan->delivery_number)
        ->and($challanRow['document']['party'])->toBe('Practice Dealer Store')
        ->and($challanRow['out'])->toBe('5');

    // The raw material: the receipt opens its own Receipt page (PR2), the run takes it out.
    $ghee = collect(($this->as)($this->manager)->get("/inventory/products/{$this->ghee->id}")->viewData('page')['props']['card']['rows']['data']);
    expect($ghee->first()['document']['type'])->toBe('goods_receipt')
        ->and($ghee->first()['document']['url'])->toStartWith('/purchasing/receipts/')
        ->and($ghee->first()['document']['party'])->toBe('Ad-Deen Food')
        ->and($ghee->firstWhere('reason', 'production')['document']['type'])->toBe('production_run');
});

it('filters the stock card by warehouse', function () {
    $reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Restock', 'is_active' => true]);
    $transfer = app(DispatchStockTransfer::class)->handle($this->pure, $this->savar, $this->shop, '4', $reason, $this->manager);
    app(ReceiveStockTransfer::class)->handle($transfer, $this->manager);

    ($this->as)($this->manager)->get("/inventory/products/{$this->pure->id}?warehouse={$this->shop->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('warehouse', $this->shop->id)
            ->has('card.rows.data', 1)
            ->where('card.rows.data.0.in', '4')
            ->where('card.closing.quantity', '4'));
});

it('hides purchase and sales history from a storekeeper, and links nothing they cannot open', function () {
    $response = ($this->as)($this->storekeeper)->get("/inventory/products/{$this->ghee->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('purchases', null)
            ->where('sales', null)
            ->where('suppliers', null)
            ->where('last_purchase', null));

    $rows = collect($response->viewData('page')['props']['card']['rows']['data']);
    // The storekeeper sees the movement, not a link to a PO they may not
    // open, nor to a production run.
    expect($rows->first()['document']['url'])->toBeNull()
        ->and($rows->firstWhere('reason', 'production')['document']['url'])->toBeNull();
});

it('refuses the product page without the inventory reports permission', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    ($this->as)($nobody)->get("/inventory/products/{$this->ghee->id}")->assertForbidden();
});

it('links product names on the product list, the stock page and the batch pages', function () {
    foreach (['pages/inventory/products/index.tsx', 'pages/inventory/stock/index.tsx', 'pages/inventory/batches/index.tsx', 'pages/inventory/batches/show.tsx', 'pages/inventory/expiry/index.tsx'] as $page) {
        expect(file_get_contents(resource_path('js/'.$page)))->toContain('/inventory/products/${');
    }
});

it('walks from the invoice back to its challan and on to the return (related documents)', function () {
    $related = ($this->as)($this->manager)->get("/sales/invoices/{$this->invoice->id}")
        ->assertOk()
        ->viewData('page')['props']['related'];

    expect(collect($related['from'])->pluck('type')->all())->toContain('delivery')
        ->and(collect($related['from'])->firstWhere('type', 'delivery')['number'])->toBe($this->challan->delivery_number)
        ->and(collect($related['to'])->pluck('type')->all())->toContain('sales_return');
});
