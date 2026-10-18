<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\BatchNumbers;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Automatic batch numbers and label dates (purchase redesign PR2, 18 Oct
 * 2026; Joy: "product batch numbers auto-generate"). `<SKU>-<YYMMDD>-<seq>`,
 * unique per product, overwritable; made-on defaults to the day the stock
 * came in, expiry to made-on plus the product's shelf life.
 *
 * ⚠ Asserted on the STOCK BATCH at every door: the receive form (HTTP), Buy
 * and receive's receipt path, opening stock and a production run's output.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:00:00');

    $this->business = Business::create(['name' => 'Batch House', 'slug' => 'batch-house']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $jar = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Jar', 'abbreviation' => 'jar']);

    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $jar->id, 'sku' => 'hny 500/g',
        'name' => 'Sundarban honey 500g', 'type' => ProductType::FinishedGood,
        'tracks_expiry' => true, 'shelf_life_days' => 365,
    ]);
    $this->labels = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $jar->id, 'sku' => '',
        'name' => 'Labels', 'type' => ProductType::RawMaterial,
    ]);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Mouchak Apiary']);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->asManager = fn () => $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id]);

    $this->orderOf = function (array $lines): PurchaseOrder {
        $order = app(CreatePurchaseOrder::class)->handle(
            supplier: $this->supplier,
            warehouse: $this->warehouse,
            lines: array_map(fn (array $line) => ['product' => $line[0], 'quantity' => $line[1], 'unit_cost' => '450'], $lines),
            createdBy: $this->manager,
        );

        approveIfPending($order->approvalRequest, $this->manager);

        return $order->fresh(['lines', 'approvalRequest']);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('builds the number from the cleaned SKU, the day and a two-digit sequence', function () {
    expect(BatchNumbers::codeOf($this->honey))->toBe('HNY-500-G')
        ->and(BatchNumbers::next($this->honey))->toBe('HNY-500-G-261018-01')
        // No SKU left after cleaning: the product id stands in.
        ->and(BatchNumbers::next($this->labels))->toBe("P{$this->labels->id}-261018-01")
        ->and(BatchNumbers::next($this->honey, '2026-09-01'))->toBe('HNY-500-G-260901-01');
});

it('counts up per product and day from the batches that exist, and keeps a typed number', function () {
    foreach (['HNY-500-G-261018-01', 'HNY-500-G-261018-07', 'SUPPLIER-LOT-9'] as $number) {
        app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '1', '10', $this->manager, batchNumber: $number);
    }

    expect(BatchNumbers::next($this->honey))->toBe('HNY-500-G-261018-08')
        // Another day starts again at 01.
        ->and(BatchNumbers::next($this->honey, '2026-10-17'))->toBe('HNY-500-G-261017-01')
        // A typed lot is kept, even when it repeats (a supplier lot can arrive twice).
        ->and(BatchNumbers::resolve($this->honey, ' SUPPLIER-LOT-9 '))->toBe('SUPPLIER-LOT-9')
        // A stale suggestion in our own pattern moves to the next free number of ITS day.
        ->and(BatchNumbers::resolve($this->honey, 'HNY-500-G-261018-01'))->toBe('HNY-500-G-261018-08')
        ->and(BatchNumbers::resolve($this->honey, ''))->toBe('HNY-500-G-261018-08');
});

it('defaults made-on to the day and expiry to made-on plus the shelf life', function () {
    expect(BatchNumbers::label($this->honey, null, null, null, '2026-10-18'))->toBe([
        'batch_number' => 'HNY-500-G-261018-01',
        'manufactured_date' => '2026-10-18',
        'expiry_date' => '2027-10-18',
    ])
        // Typed dates win; a typed made-on moves the default expiry with it.
        ->and(BatchNumbers::label($this->honey, 'L1', '2026-10-01', null, '2026-10-18')['expiry_date'])->toBe('2027-10-01')
        ->and(BatchNumbers::label($this->honey, 'L1', '2026-10-01', '2027-01-31', '2026-10-18')['expiry_date'])->toBe('2027-01-31')
        // No shelf life stated: no expiry guessed.
        ->and(BatchNumbers::label($this->labels, null, null, null, '2026-10-18')['expiry_date'])->toBeNull();
});

it('numbers a blank batch on the receive form and gives two lines of one product their own numbers', function () {
    $order = ($this->orderOf)([[$this->honey, '10'], [$this->honey, '5']]);

    // A product that tracks expiry is received with a blank batch and expiry:
    // the shelf life supplies the expiry, so nothing is refused.
    ($this->asManager)()->post(route('purchasing.purchase-orders.receive', $order), [
        'lines' => [
            ['purchase_order_line_id' => $order->lines[0]->id, 'quantity' => '10'],
            ['purchase_order_line_id' => $order->lines[1]->id, 'quantity' => '5'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $batches = StockBatch::where('product_id', $this->honey->id)->orderBy('id')->get();

    expect($batches->pluck('batch_number')->all())->toBe(['HNY-500-G-261018-01', 'HNY-500-G-261018-02'])
        ->and($batches[0]->manufactured_date->toDateString())->toBe('2026-10-18')
        ->and($batches[0]->expiry_date->toDateString())->toBe('2027-10-18');
});

it('numbers a back-dated receipt for the day the goods arrived', function () {
    $order = ($this->orderOf)([[$this->labels, '100']]);

    ($this->asManager)()->post(route('purchasing.purchase-orders.receive', $order), [
        'received_on' => '2026-10-15',
        'backdate_reason' => 'Entered late',
        'lines' => [['purchase_order_line_id' => $order->lines[0]->id, 'quantity' => '100']],
    ])->assertSessionHasNoErrors();

    $batch = StockBatch::where('product_id', $this->labels->id)->sole();

    expect($batch->batch_number)->toBe("P{$this->labels->id}-261015-01")
        ->and($batch->manufactured_date->toDateString())->toBe('2026-10-15')
        ->and($batch->expiry_date)->toBeNull();
});

it('prefills the Receive step with the automatic number', function () {
    $order = ($this->orderOf)([[$this->honey, '10']]);

    ($this->asManager)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where("batch_suggestions.{$order->lines[0]->id}", 'HNY-500-G-261018-01')
            ->where('order.lines.0.product.shelf_life_days', 365));
});

it('numbers opening stock lines one by one', function () {
    app(RecordOpeningStock::class)->handle(
        warehouse: $this->warehouse,
        lines: [
            ['product' => $this->honey, 'quantity' => '3', 'unitCost' => '400', 'expiryDate' => '2027-06-30'],
            ['product' => $this->honey, 'quantity' => '2', 'unitCost' => '400', 'expiryDate' => '2027-06-30'],
            ['product' => $this->labels, 'quantity' => '50', 'unitCost' => '1', 'batchNumber' => 'OLD-ROLL'],
        ],
        createdBy: $this->manager,
        asAt: '2026-09-30',
    );

    expect(StockBatch::orderBy('id')->pluck('batch_number')->all())
        ->toBe(['HNY-500-G-260930-01', 'HNY-500-G-260930-02', 'OLD-ROLL']);
});

it('numbers a production run output and dates its expiry from the shelf life', function () {
    app(RecordStockIn::class)->handle($this->labels, $this->warehouse, '100', '2', $this->manager, batchNumber: 'ROLL-1');

    $runs = app(RecordProductionRun::class);
    $run = $runs->confirm($runs->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->honey,
        outputQuantity: '10',
        lines: [['product' => $this->labels, 'quantity' => '10']],
        createdBy: $this->manager,
    ), $this->manager);

    expect($run->outputBatch->batch_number)->toBe('HNY-500-G-261018-01')
        ->and($run->outputBatch->manufactured_date->toDateString())->toBe('2026-10-18')
        ->and($run->outputBatch->expiry_date->toDateString())->toBe('2027-10-18');
});

it('lets the product popup save a shelf life', function () {
    ($this->asManager)()->putJson(route('inventory.products.update', $this->labels), [
        'name' => 'Labels',
        'sku' => 'LBL-1',
        'type' => 'raw_material',
        'unit_of_measure_id' => $this->labels->unit_of_measure_id,
        'shelf_life_days' => 730,
    ])->assertSuccessful();

    expect($this->labels->fresh()->shelf_life_days)->toBe(730);
});
