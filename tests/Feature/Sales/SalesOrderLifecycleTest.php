<?php

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CloseSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales orders slice 3: how a promise ends when it is not kept in full.
 *
 * Cancel = the order dies untouched. Close = the customer took part and will
 * not take the rest; the shortfall stays visible forever. Two events, two
 * actions, and neither touches stock or the ledger - the order never did.
 * See SALES-ORDER-ARCHITECTURE.md §5.4, C-SO-6.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Lifecycle Co', 'slug' => 'lifecycle-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    Permission::firstOrCreate(['name' => 'sales.orders.approve', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('sales.orders.approve');

    $this->warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR',
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'type' => CustomerType::Distributor,
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    Carbon::setTestNow('2026-08-11 10:00:00');

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '850.0000',
        'effective_from' => '2026-08-01',
        'created_by' => $this->owner->id,
    ]);

    app(RecordStockIn::class)->handle(
        product: $this->product,
        warehouse: $this->warehouse,
        quantity: '100',
        unitCost: '500',
        performedBy: $this->owner,
        occurredAt: '2026-08-01 09:00:00',
    );

    $this->makeOrder = fn (string $quantity = '40') => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity]],
        createdBy: $this->owner,
    );

    $this->deliverAgainst = fn ($order, string $quantity) => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [[
            'product' => $this->product,
            'quantity' => $quantity,
            'orderLine' => $order->lines->first(),
        ]],
        createdBy: $this->owner,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('cancels an untouched order outright', function () {
    $order = ($this->makeOrder)();

    app(CancelSalesOrder::class)->handle($order);

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Cancelled);
});

it('refuses to cancel twice', function () {
    $order = ($this->makeOrder)();
    app(CancelSalesOrder::class)->handle($order);

    app(CancelSalesOrder::class)->handle($order->fresh());
})->throws(RuntimeException::class, 'already cancelled');

it('refuses to cancel once anything has been delivered', function () {
    $order = ($this->makeOrder)('40');
    ($this->deliverAgainst)($order, '25');

    app(CancelSalesOrder::class)->handle($order->fresh());
})->throws(RuntimeException::class, 'close it short instead');

it('closes a partially delivered order short, keeping the shortfall visible', function () {
    $order = ($this->makeOrder)('40');
    ($this->deliverAgainst)($order, '25');

    app(CloseSalesOrder::class)->handle($order->fresh(), $this->owner, 'Customer stopped taking stock');

    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::Closed)
        // ⚠ No per-line mutation: the shortfall of 15 is ordered minus
        // delivered, recorded forever.
        ->and($order->lines->first()->quantityRemaining())->toBe('15.0000')
        ->and($order->note)->toContain('Closed short')
        ->and($order->note)->toContain('Customer stopped taking stock');
});

it('requires a reason to close short', function () {
    $order = ($this->makeOrder)('40');
    ($this->deliverAgainst)($order, '25');

    app(CloseSalesOrder::class)->handle($order->fresh(), $this->owner, '   ');
})->throws(RuntimeException::class, 'needs a reason');

it('refuses to close an untouched order - that is a cancel', function () {
    $order = ($this->makeOrder)();

    app(CloseSalesOrder::class)->handle($order, $this->owner, 'Changed their mind');
})->throws(RuntimeException::class, 'only a partially delivered order');

it('refuses to close a fully delivered order - it is already terminal', function () {
    $order = ($this->makeOrder)('40');
    ($this->deliverAgainst)($order, '40');

    app(CloseSalesOrder::class)->handle($order->fresh(), $this->owner, 'Too late');
})->throws(RuntimeException::class, 'only a partially delivered order');

it('refuses delivery against a closed order', function () {
    $order = ($this->makeOrder)('40');
    ($this->deliverAgainst)($order, '25');
    app(CloseSalesOrder::class)->handle($order->fresh(), $this->owner, 'Customer stopped');

    ($this->deliverAgainst)($order->fresh(), '5');
})->throws(Illuminate\Validation\ValidationException::class);

// ⚠⚠ §7 of the doc, held through the whole lifecycle: create, deliver, close,
// cancel - the only journals and movements in this test came from the
// DELIVERY, never from any order transition.
it('never touches stock or the ledger through any order transition', function () {
    $cancelled = ($this->makeOrder)();
    app(CancelSalesOrder::class)->handle($cancelled);

    expect(Journal::count())->toBe(0)
        ->and(StockMovement::query()->where('reason', \App\Modules\Inventory\Enums\MovementReason::Sale)->count())->toBe(0);

    $closed = ($this->makeOrder)('40');
    ($this->deliverAgainst)($closed, '25');

    $journalsAfterDelivery = Journal::count();
    $movementsAfterDelivery = StockMovement::count();

    app(CloseSalesOrder::class)->handle($closed->fresh(), $this->owner, 'Customer stopped');

    expect(Journal::count())->toBe($journalsAfterDelivery)
        ->and(StockMovement::count())->toBe($movementsAfterDelivery);
});
