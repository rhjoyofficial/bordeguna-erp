<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\DispatchStockTransfer;
use App\Modules\Inventory\Actions\HoldStockBatch;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockBatchHold;
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
 * Traceability musts (17 Oct 2026, TRACEABILITY-REVIEW-2026-10-17.md):
 * M3 the batch trace page and its recall list, M2 batch hold, M1 the batch
 * label from the run. The world is the ghee lesson: 10 kg of ghee GH-2610-01
 * from Ad-Deen Food on PO-000001 (supplier challan ADF-1017), 18 jars made in
 * one run, 5 to a dealer on a challan and an invoice, 1 back, 2 at the counter.
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

// ── M3: the trace page ───────────────────────────────────────────────────

it('traces a finished batch backward to the run, the ghee batch, the supplier, the PO and the challan', function () {
    ($this->as)($this->manager)->get("/inventory/batches/{$this->jars->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/batches/show')
            // PR2 (18 Oct 2026): the automatic number, no longer the run number.
            ->where('batch.batch_number', fn (string $number) => $number === $this->jars->batch_number && str_ends_with($number, '-261017-01'))
            ->where('batch.manufactured_date', '2026-10-17')
            ->where('batch.expiry_date', '2027-03-31')
            ->where('backward.kind', 'production')
            ->where('backward.run_number', $this->run->run_number)
            ->where('backward.inputs.0.batch_number', 'GH-2610-01')
            ->where('backward.inputs.0.quantity', '9.18')
            ->where('backward.inputs.0.made_on', '2026-10-01')
            ->where('backward.inputs.0.expiry_date', '2027-09-30')
            ->where('backward.inputs.0.from.kind', 'receipt')
            ->where('backward.inputs.0.from.supplier', 'Ad-Deen Food')
            ->where('backward.inputs.0.from.po_number', 'PO-000001')
            ->where('backward.inputs.0.from.supplier_challan', 'ADF-1017')
            ->where('backward.inputs.0.from.date', '2026-10-17'));
});

it('traces a raw batch forward through the run to every customer, with the return and what is left', function () {
    ($this->as)($this->manager)->get("/inventory/batches/{$this->gheeBatch->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('backward.kind', 'receipt')
            ->where('backward.supplier', 'Ad-Deen Food')
            ->where('backward.supplier_challan', 'ADF-1017')
            ->has('runs', 1)
            ->where('runs.0.run_number', $this->run->run_number)
            ->where('runs.0.used', '9.18')
            ->where('runs.0.output_batch_id', $this->jars->id)
            ->where('places.0.on_hand', '0.82')
            ->has('recipients', 2)
            // The dealer: 5 out on the challan, invoiced, 1 back.
            ->where('recipients.0.customer', 'Practice Dealer Store')
            ->where('recipients.0.phone', '01711000000')
            ->where('recipients.0.delivery_number', $this->challan->delivery_number)
            ->where('recipients.0.quantity', '5')
            ->where('recipients.0.returned', '1')
            ->where('recipients.0.invoices.0.number', $this->invoice->invoice_number)
            ->where('recipients.0.via_run.run_number', $this->run->run_number)
            // The counter: a walk-in, 2.
            ->where('recipients.1.quantity', '2')
            ->where('recipients.1.returned', '0'));
});

it('shows the return back onto the batch and the jars still on the shelf', function () {
    ($this->as)($this->manager)->get("/inventory/batches/{$this->jars->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('returns', 1)
            ->where('returns.0.return_number', $this->return->return_number)
            ->where('returns.0.quantity', '1')
            ->where('returns.0.back_to_stock', true)
            // 18 made - 5 dealer + 1 back - 2 counter = 12.
            ->where('places.0.on_hand', '12')
            ->where('places.0.warehouse', 'Savar'));
});

it('follows a batch across a transfer, both ways', function () {
    $reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Restock', 'is_active' => true]);
    $transfer = app(DispatchStockTransfer::class)->handle($this->pure, $this->savar, $this->shop, '4', $reason, $this->manager);
    app(ReceiveStockTransfer::class)->handle($transfer, $this->manager);
    $atShop = StockBatch::where('warehouse_id', $this->shop->id)->sole();
    ($this->counter)('1', $this->shop);

    // From the shop's copy: backward still reaches the run.
    ($this->as)($this->manager)->get("/inventory/batches/{$atShop->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('backward.kind', 'production')
            ->where('backward.run_number', $this->run->run_number)
            ->has('places', 2));

    // From the raw ghee: the shop's walk-in sale is on the recall list too.
    ($this->as)($this->manager)->get("/inventory/batches/{$this->gheeBatch->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recipients', 3)
            ->where('recipients.2.warehouse', 'Shop')
            ->where('recipients.2.quantity', '1'));
});

it('downloads the recall list as a CSV', function () {
    $response = ($this->as)($this->manager)->get("/inventory/batches/{$this->gheeBatch->id}/recall-list");

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('recall-list-GH-2610-01.csv');

    $rows = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")))));

    expect($rows[0])->toBe(['Batch', 'Product', 'Customer', 'Phone', 'Recipient', 'Recipient phone', 'Challan', 'Date', 'Quantity', 'Returned', 'Invoice', 'Consignment', 'Courier', 'Made in run'])
        ->and($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe($this->jars->batch_number)
        ->and($rows[1][2])->toBe('Practice Dealer Store')
        ->and($rows[1][3])->toBe('01711000000')
        ->and($rows[1][6])->toBe($this->challan->delivery_number)
        ->and($rows[1][7])->toBe('2026-10-17')
        ->and($rows[1][8])->toBe('5')
        ->and($rows[1][9])->toBe('1')
        ->and($rows[1][10])->toBe($this->invoice->invoice_number)
        ->and($rows[1][13])->toBe($this->run->run_number);
});

it('keeps customer phones from people who may not see customers, on the page and in the file', function () {
    ($this->as)($this->storekeeper)->get("/inventory/batches/{$this->jars->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('recipients.0.customer', 'Practice Dealer Store')
            ->where('recipients.0.phone', null)
            ->where('can.see_customers', false)
            ->where('can.hold', false));

    $csv = ($this->as)($this->storekeeper)->get("/inventory/batches/{$this->jars->id}/recall-list")->streamedContent();

    expect($csv)->not->toContain('01711000000')
        ->and($csv)->not->toContain('Phone');
});

it('finds a batch by its number', function () {
    ($this->as)($this->storekeeper)->get('/inventory/batches?search=GH-2610')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/batches/index')
            ->has('batches', 1)
            ->where('batches.0.id', $this->gheeBatch->id));
});

it('refuses the trace page to someone who cannot read stock', function () {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    ($this->as)($nobody)->get("/inventory/batches/{$this->jars->id}")->assertForbidden();
    ($this->as)($nobody)->get("/inventory/batches/{$this->jars->id}/recall-list")->assertForbidden();
});

// ── M2: batch hold ───────────────────────────────────────────────────────

it('holds a batch with a reason, and the counter then refuses in a plain sentence', function () {
    ($this->as)($this->manager)->post("/inventory/batches/{$this->jars->id}/hold", ['reason' => 'Customer complaint, waiting for the lab', 'everywhere' => true])
        ->assertSessionHasNoErrors();

    expect($this->jars->fresh()->isHeld())->toBeTrue()
        ->and($this->jars->fresh()->hold_reason)->toBe('Customer complaint, waiting for the lab')
        ->and(StockBatchHold::where('stock_batch_id', $this->jars->id)->sole()->action)->toBe('hold');

    expect(fn () => ($this->counter)('1'))->toThrow(
        InsufficientStockException::class,
        'LaLaDia Pure Ghee 500g in Savar: 12 is on hold and cannot be used. Nothing else is free to use. You asked for 1. Release the batch first, or take it from another warehouse.',
    );
});

it('a held batch is skipped by a challan, a transfer and a production run', function () {
    app(HoldStockBatch::class)->hold($this->gheeBatch, $this->manager, 'Lab test pending');
    app(HoldStockBatch::class)->hold($this->jars, $this->manager, 'Recall');

    $reason = Reason::create(['business_id' => $this->business->id, 'context' => 'inventory.stock_transfer', 'name' => 'Restock', 'is_active' => true]);

    // Challan.
    expect(fn () => app(RecordDelivery::class)->handle(
        customer: $this->dealer, warehouse: $this->savar, lines: [['product' => $this->pure, 'quantity' => '1']], createdBy: $this->manager,
    ))->toThrow(InsufficientStockException::class, 'is on hold');

    // Transfer.
    expect(fn () => app(DispatchStockTransfer::class)->handle($this->pure, $this->savar, $this->shop, '1', $reason, $this->manager))
        ->toThrow(InsufficientStockException::class, 'is on hold');

    // Production run: the held ghee is not picked.
    $runs = app(RecordProductionRun::class);
    $draft = $runs->draft(
        warehouse: $this->savar, outputProduct: $this->pure, outputQuantity: '1',
        lines: [['product' => $this->ghee, 'quantity' => '0.5', 'waste' => '0']],
        createdBy: $this->manager, occurredAt: '2026-10-17 11:00:00', outputExpiryDate: '2027-03-31',
    );
    expect(fn () => $runs->confirm($draft, $this->manager))->toThrow(InsufficientStockException::class, 'Ghee (bulk) in Savar: 0.82 is on hold');

    // Nothing moved: the stock is all still there.
    expect((float) $this->jars->fresh()->current_quantity)->toBe(12.0)
        ->and((float) $this->gheeBatch->fresh()->current_quantity)->toBe(0.82);
});

it('picks around a held batch when another batch can serve, oldest expiry first', function () {
    // A second, LATER-expiring batch of jars: normally FEFO takes the held one first.
    app(RecordStockIn::class)->handle($this->pure, $this->savar, '10', '780', $this->manager, batchNumber: 'PRN-LATER', expiryDate: '2027-06-30');
    app(HoldStockBatch::class)->hold($this->jars, $this->manager, 'Recall');

    ($this->counter)('3');

    expect((float) $this->jars->fresh()->current_quantity)->toBe(12.0)
        ->and((float) StockBatch::where('batch_number', 'PRN-LATER')->value('current_quantity'))->toBe(7.0);
});

it('releases a held batch with a reason, keeps both steps in the history, and sells again', function () {
    ($this->as)($this->manager)->post("/inventory/batches/{$this->jars->id}/hold", ['reason' => 'Lab test', 'everywhere' => true]);
    ($this->as)($this->manager)->post("/inventory/batches/{$this->jars->id}/release", ['reason' => 'Lab report clear', 'everywhere' => true])
        ->assertSessionHasNoErrors();

    expect($this->jars->fresh()->isHeld())->toBeFalse()
        ->and(StockBatchHold::where('stock_batch_id', $this->jars->id)->orderBy('id')->pluck('action')->all())->toBe(['hold', 'release']);

    ($this->counter)('1');
    expect((float) $this->jars->fresh()->current_quantity)->toBe(11.0);

    ($this->as)($this->manager)->get("/inventory/batches/{$this->jars->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('holds', 2)
            ->where('holds.0.action', 'release')
            ->where('holds.0.reason', 'Lab report clear')
            ->where('holds.1.reason', 'Lab test'));
});

it('needs a reason, and refuses hold and release to someone without stock correction rights', function () {
    ($this->as)($this->manager)->post("/inventory/batches/{$this->jars->id}/hold", ['reason' => ''])
        ->assertSessionHasErrors(['reason' => 'Write why the batch is on hold.']);

    ($this->as)($this->storekeeper)->post("/inventory/batches/{$this->jars->id}/hold", ['reason' => 'x'])->assertForbidden();

    expect($this->jars->fresh()->isHeld())->toBeFalse();
});

it('shows the hold on the stock list and the expiry list', function () {
    app(HoldStockBatch::class)->hold($this->jars, $this->manager, 'Recall');

    ($this->as)($this->manager)->get('/inventory/stock?search=PURE')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stock.data.0.held_quantity', fn ($q) => (float) $q === 12.0));

    ($this->as)($this->manager)->get('/inventory/expiry?within=all')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('expiring', fn ($rows) => collect($rows)->firstWhere('id', $this->jars->id)['held'] === true));
});

// ── M1: batch label from the run ─────────────────────────────────────────

it('prints batch labels from a confirmed run: batch, MFG, EXP, MRP and the barcode, one per jar made', function () {
    ($this->as)($this->manager)->get("/inventory/production-runs/{$this->run->id}/labels")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/labels/print')
            ->has('labels', 18)
            ->where('labels.0.name', 'LaLaDia Pure Ghee 500g')
            ->where('labels.0.brand', 'LaLaDia')
            ->where('labels.0.net_content', '500 g')
            ->where('labels.0.batch_number', $this->jars->batch_number)
            ->where('labels.0.mfg', '17 Oct 2026')
            ->where('labels.0.exp', '31 Mar 2027')
            ->where('labels.0.mrp', 'Tk 950')
            ->where('labels.0.barcode', '8801234567890')
            ->where('labels.0.svg', fn ($svg) => str_starts_with($svg, '<svg'))
            ->where('batch.run_number', $this->run->run_number)
            ->where('skipped', []));

    ($this->as)($this->manager)->get("/inventory/production-runs/{$this->run->id}/labels?count=40&size=large")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('labels', 40)->where('batch.size', 'large'));
});

it('offers the label print and the trace link on the run page', function () {
    ($this->as)($this->manager)->get("/inventory/production-runs/{$this->run->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('run.output_batch.id', $this->jars->id)
            ->where('run.output_batch.held', false)
            ->where('run.movements.0.batch_id', fn ($id) => $id !== null));
});
