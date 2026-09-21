<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockOut;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Batch number, manufacture and expiry dates on a goods receipt (INVENTORY-UX-AUDIT
 * A.9 and fix #2; SALES-UX-AUDIT #11).
 *
 * ⚠⚠ WHY THIS FILE EXISTS. FEFO was real (`StockBatch::scopeConsumable` sorts by
 * expiry) and so were the Expiry screen and the nightly expired-stock proposals,
 * but the receive form sent only a line id and a quantity, and RecordGoodsReceipt
 * called RecordStockIn with no batch and no dates. Every batch a supplier
 * delivered therefore had a null expiry: FEFO could not order two deliveries of
 * honey, and nothing bought ever reached the Expiry screen. Only opening stock
 * and production output did.
 *
 * ⚠ Asserted on the STOCK BATCH, where FEFO reads, and through the HTTP layer,
 * where the storekeeper types. A test that only called the action would have
 * been green while the form still sent nothing.
 */
beforeEach(function () {
    // A fixed Dhaka morning, so "today" and "already expired" are exact.
    Carbon::setTestNow('2026-09-21 10:00:00');

    $this->business = Business::create(['name' => 'Honey House', 'slug' => 'honey-house']);
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
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $jar->id,
        'sku' => 'HNY-500',
        'name' => 'Sundarban honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->ghee = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $jar->id,
        'sku' => 'GHE-250',
        'name' => 'Cow ghee 250g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->lab = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $jar->id,
        'sku' => 'SRV-LAB',
        'name' => 'Lab test',
        'type' => ProductType::Service,
    ]);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Mouchak Apiary']);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->asManager = fn () => $this->actingAs($this->manager)
        ->withSession(['current_business_id' => $this->business->id]);

    /** @param array<int, array{0: Product, 1: string}> $lines */
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

    $this->receive = fn (PurchaseOrder $order, array $payload) => ($this->asManager)()
        ->post(route('purchasing.purchase-orders.receive', $order), $payload);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('carries the batch number, manufacture and expiry dates from the receive form onto the stock batch', function () {
    $order = ($this->orderOf)([[$this->honey, '40']]);

    ($this->receive)($order, [
        'supplier_challan_number' => 'CH-4471',
        'lines' => [[
            'purchase_order_line_id' => $order->lines->first()->id,
            'quantity' => '40',
            'batch_number' => '  MA-2609  ',
            'manufactured_date' => '2026-09-01',
            'expiry_date' => '2027-08-31',
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $batch = StockBatch::where('product_id', $this->honey->id)->sole();

    expect($batch->batch_number)->toBe('MA-2609')
        ->and($batch->manufactured_date->toDateString())->toBe('2026-09-01')
        ->and($batch->expiry_date->toDateString())->toBe('2027-08-31')
        ->and((string) $batch->current_quantity)->toBe('40.0000');

    // The supplier's own delivery-note number, on the receipt it came with.
    expect(GoodsReceipt::sole()->supplier_challan_number)->toBe('CH-4471');
});

it('still receives a line that carries no label data at all', function () {
    $order = ($this->orderOf)([[$this->honey, '10']]);

    ($this->receive)($order, [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '10']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $batch = StockBatch::sole();

    expect($batch->batch_number)->toBeNull()
        ->and($batch->manufactured_date)->toBeNull()
        ->and($batch->expiry_date)->toBeNull()
        ->and(GoodsReceipt::sole()->supplier_challan_number)->toBeNull();
});

// ▶▶ The point of the whole slice: the jar that goes off first leaves first.
it('lets FEFO sell the earlier-expiring delivery first even when it arrived later', function () {
    $first = ($this->orderOf)([[$this->honey, '10']]);
    ($this->receive)($first, [
        'lines' => [['purchase_order_line_id' => $first->lines->first()->id, 'quantity' => '10', 'batch_number' => 'LATE', 'expiry_date' => '2027-06-30']],
    ])->assertSessionHasNoErrors();

    Carbon::setTestNow('2026-09-22 10:00:00');

    $second = ($this->orderOf)([[$this->honey, '10']]);
    ($this->receive)($second, [
        'lines' => [['purchase_order_line_id' => $second->lines->first()->id, 'quantity' => '10', 'batch_number' => 'SOON', 'expiry_date' => '2026-12-31']],
    ])->assertSessionHasNoErrors();

    $movements = app(RecordStockOut::class)->handle($this->honey, $this->warehouse, '4', $this->manager);

    expect($movements)->toHaveCount(1)
        ->and($movements->first()->batch->batch_number)->toBe('SOON')
        ->and((string) StockBatch::where('batch_number', 'SOON')->sole()->current_quantity)->toBe('6.0000')
        ->and((string) StockBatch::where('batch_number', 'LATE')->sole()->current_quantity)->toBe('10.0000');
});

it('puts a bought batch that expires soon on the Expiry screen', function () {
    $order = ($this->orderOf)([[$this->honey, '12']]);

    ($this->receive)($order, [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '12', 'batch_number' => 'MA-EXP', 'expiry_date' => '2026-10-05']],
    ])->assertSessionHasNoErrors();

    ($this->asManager)()->get(route('inventory.expiry.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('expiring', 1)
            ->where('expiring.0.batch_number', 'MA-EXP')
            ->where('expiring.0.expiry_date', '2026-10-05')
        );
});

it('refuses an expiry date that has already passed, under that line, and receives nothing', function () {
    $order = ($this->orderOf)([[$this->honey, '10']]);

    ($this->receive)($order, [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '10', 'expiry_date' => '2026-09-20']],
    ])->assertSessionHasErrors('lines.0.expiry_date');

    expect(session('errors')->first('lines.0.expiry_date'))
        ->toContain('expired on 20 Sep 2026')
        ->toContain('cannot be received');

    expect(GoodsReceipt::count())->toBe(0)
        ->and(StockBatch::count())->toBe(0)
        ->and((string) $order->lines->first()->fresh()->quantity_received)->toBe('0.0000');
});

// ⚠ Good THROUGH the expiry date, the same rule FEFO sells by. An off-by-one
// here would turn away a delivery that is still sellable all day.
it('accepts stock that expires today', function () {
    $order = ($this->orderOf)([[$this->honey, '3']]);

    ($this->receive)($order, [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '3', 'expiry_date' => '2026-09-21']],
    ])->assertSessionHasNoErrors();

    expect(StockBatch::sole()->expiry_date->toDateString())->toBe('2026-09-21');
});

it('refuses an expiry before the manufacture date and a manufacture date in the future', function () {
    $order = ($this->orderOf)([[$this->honey, '5'], [$this->ghee, '5']]);
    [$honeyLine, $gheeLine] = [$order->lines[0], $order->lines[1]];

    ($this->receive)($order, [
        'lines' => [
            ['purchase_order_line_id' => $honeyLine->id, 'quantity' => '5', 'manufactured_date' => '2026-09-01', 'expiry_date' => '2026-12-01'],
            ['purchase_order_line_id' => $gheeLine->id, 'quantity' => '5', 'manufactured_date' => '2026-10-01', 'expiry_date' => '2026-09-30'],
        ],
    ])->assertSessionHasErrors(['lines.1.manufactured_date', 'lines.1.expiry_date']);

    expect(session('errors')->first('lines.1.manufactured_date'))->toContain('in the future')
        ->and(session('errors')->first('lines.1.expiry_date'))->toContain('before the manufacture date')
        // ⚠ Per line: the good first line carries no error of its own.
        ->and(session('errors')->has('lines.0.expiry_date'))->toBeFalse()
        ->and(session('errors')->has('lines.0.manufactured_date'))->toBeFalse();

    // All or nothing: the good line was not received either.
    expect(StockBatch::count())->toBe(0);
});

it('names the field in words when a date is not a date', function () {
    $order = ($this->orderOf)([[$this->honey, '5']]);

    ($this->receive)($order, [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => 'five', 'expiry_date' => '31/12/2026']],
    ])->assertSessionHasErrors(['lines.0.quantity', 'lines.0.expiry_date']);

    expect(session('errors')->first('lines.0.expiry_date'))->toContain('expiry date')->not->toContain('lines.0')
        ->and(session('errors')->first('lines.0.quantity'))->toContain('quantity')->not->toContain('lines.0');
});

it('does not check the dates on a line left at zero, which is not in this delivery', function () {
    $order = ($this->orderOf)([[$this->honey, '5'], [$this->ghee, '5']]);

    ($this->receive)($order, [
        'lines' => [
            ['purchase_order_line_id' => $order->lines[0]->id, 'quantity' => '5', 'expiry_date' => '2027-01-31'],
            ['purchase_order_line_id' => $order->lines[1]->id, 'quantity' => '0', 'expiry_date' => '2020-01-01'],
        ],
    ])->assertSessionHasNoErrors();

    expect(StockBatch::sole()->product_id)->toBe($this->honey->id);
});

// ⚠⚠ The chokepoint. The form is one way in; the action is the only way stock
// is created, so it refuses on its own, whoever calls it.
it('refuses expired stock in the action itself, not only on the form', function () {
    $order = ($this->orderOf)([[$this->honey, '5']]);

    expect(fn () => app(RecordGoodsReceipt::class)->handle($order, [[
        'line' => $order->lines->first(),
        'quantity' => '5',
        'expiry_date' => '2026-09-01',
    ]], $this->manager))->toThrow(InvalidArgumentException::class, 'Sundarban honey 500g (HNY-500): This batch expired on 1 Sep 2026');

    expect(StockBatch::count())->toBe(0)
        ->and(GoodsReceipt::count())->toBe(0);
});

it('receives a service line without a batch, whatever label data arrives with it', function () {
    $order = ($this->orderOf)([[$this->lab, '1']]);

    ($this->receive)($order, [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '1', 'batch_number' => 'X', 'expiry_date' => '2027-01-01']],
    ])->assertSessionHasNoErrors();

    expect(StockBatch::count())->toBe(0)
        ->and(GoodsReceipt::sole()->lines()->sole()->stock_movement_id)->toBeNull();
});

it('names the product, not a line id, when a storekeeper over-receives', function () {
    $order = ($this->orderOf)([[$this->honey, '20']]);

    ($this->receive)($order, [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '50']],
    ])->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))
        ->toContain('Sundarban honey 500g (HNY-500)')
        ->toContain('only 20')
        ->not->toContain('line #');
});

it('shows each receipt line with its batch, dates and the supplier challan on the order page', function () {
    $order = ($this->orderOf)([[$this->honey, '8']]);

    ($this->receive)($order, [
        'supplier_challan_number' => 'CH-9001',
        'lines' => [[
            'purchase_order_line_id' => $order->lines->first()->id,
            'quantity' => '8',
            'batch_number' => 'MA-77',
            'manufactured_date' => '2026-09-10',
            'expiry_date' => '2027-09-09',
        ]],
    ])->assertSessionHasNoErrors();

    ($this->asManager)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('purchasing/purchase-orders/show')
            ->where('today', '2026-09-21')
            ->has('receipts', 1)
            ->where('receipts.0.supplier_challan_number', 'CH-9001')
            // ⚠ Dhaka clock, formatted on the server: not a UTC ISO string.
            ->where('receipts.0.received_at', '2026-09-21 10:00')
            ->where('receipts.0.lines.0.batch_number', 'MA-77')
            ->where('receipts.0.lines.0.manufactured_date', '2026-09-10')
            ->where('receipts.0.lines.0.expiry_date', '2027-09-09')
        );
});
