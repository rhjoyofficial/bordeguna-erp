<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales orders slice 2: goods leaving against the promise.
 *
 * ⚠⚠ The delivery is still the ONLY thing that moves stock and posts the GDNI
 * journal - linking it to an order line adds a cap and a status, never a
 * second stock path. Direct deliveries and the counter are byte-for-byte
 * untouched. See SALES-ORDER-ARCHITECTURE.md §3, §5.2, §5.3.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Order Flow Co', 'slug' => 'order-flow-co']);
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
        // A dealer, not a distributor: since 21 Sep 2026 a distributor's order
        // waits for payment before approval, which is not what this file tests.
        'type' => CustomerType::Dealer,
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

    $this->makeOrder = function (string $quantity = '40', ?Customer $customer = null) {
        return app(CreateSalesOrder::class)->handle(
            customer: $customer ?? $this->customer,
            lines: [['product' => $this->product, 'quantity' => $quantity]],
            createdBy: $this->owner,
        );
    };

    $this->deliverAgainst = function (SalesOrder $order, string $quantity, ?Customer $customer = null) {
        return app(RecordDelivery::class)->handle(
            customer: $customer ?? $this->customer,
            warehouse: $this->warehouse,
            lines: [[
                'product' => $this->product,
                'quantity' => $quantity,
                'orderLine' => $order->lines->first(),
            ]],
            createdBy: $this->owner,
        );
    };
});

afterEach(fn () => Carbon::setTestNow());

it('fulfils an order across several challans and tracks the status through', function () {
    $order = ($this->makeOrder)('40');

    ($this->deliverAgainst)($order, '25');

    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::PartiallyDelivered)
        ->and((float) $order->lines->first()->quantity_delivered)->toBe(25.0)
        ->and($order->lines->first()->quantityRemaining())->toBe('15.0000');

    ($this->deliverAgainst)($order, '15');

    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::Delivered)
        ->and((float) $order->lines->first()->quantity_delivered)->toBe(40.0);
});

it('links the delivery line back to its order line', function () {
    $order = ($this->makeOrder)('40');

    $delivery = ($this->deliverAgainst)($order, '25');

    expect($delivery->lines->first()->sales_order_line_id)->toBe($order->lines->first()->id)
        ->and($order->lines->first()->deliveryLines()->count())->toBe(1);
});

// ⚠ C-SO-5 (Joy): refuse, never silently inflate - and the message says what
// to do instead (a direct line on the same challan).
it('refuses to deliver more than remains on the order line', function () {
    $order = ($this->makeOrder)('40');

    ($this->deliverAgainst)($order, '25');

    expect(fn () => ($this->deliverAgainst)($order, '16'))
        ->toThrow(ValidationException::class);

    // The refused challan wrote nothing: quantity_delivered still 25.
    expect((float) $order->lines->first()->fresh()->quantity_delivered)->toBe(25.0);
});

it('refuses a fully delivered order for further delivery', function () {
    $order = ($this->makeOrder)('40');
    ($this->deliverAgainst)($order, '40');

    ($this->deliverAgainst)($order->fresh(), '1');
})->throws(ValidationException::class);

// ⚠ C-SO-1: an unapproved order exists but goods may not leave against it.
it('refuses delivery against a pending unapproved order', function () {
    $order = ($this->makeOrder)();
    $order->approvalRequest->update(['status' => ApprovalStatus::Pending]);

    ($this->deliverAgainst)($order->fresh(), '10');
})->throws(ValidationException::class);

it('refuses delivery against a cancelled or closed order', function () {
    foreach ([SalesOrderStatus::Cancelled, SalesOrderStatus::Closed] as $terminal) {
        $order = ($this->makeOrder)();
        $order->update(['status' => $terminal]);

        expect(fn () => ($this->deliverAgainst)($order->fresh(), '10'))
            ->toThrow(ValidationException::class);
    }
});

it('refuses an order line belonging to a different customer', function () {
    $other = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Rahim Stores',
        'type' => CustomerType::Dealer,
    ]);
    $order = ($this->makeOrder)('40', $other);

    // Delivery is FOR $this->customer, but the order line is Rahim's.
    ($this->deliverAgainst)($order, '10');
})->throws(ValidationException::class);

it('refuses an order line for a different product', function () {
    $order = ($this->makeOrder)('40');

    $otherProduct = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => UnitOfMeasure::first()->id,
        'sku' => 'SKU-MUSTARD',
        'name' => 'Mustard Oil 1L',
        'type' => ProductType::FinishedGood,
    ]);
    app(RecordStockIn::class)->handle(
        product: $otherProduct,
        warehouse: $this->warehouse,
        quantity: '50',
        unitCost: '300',
        performedBy: $this->owner,
        occurredAt: '2026-08-01 09:00:00',
    );

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [[
            'product' => $otherProduct,
            'quantity' => '10',
            'orderLine' => $order->lines->first(),
        ]],
        createdBy: $this->owner,
    );
})->throws(ValidationException::class);

// One challan, two orders, same customer - the IBOS "Multiple SO To Delivery"
// case, falling out of per-line linking with no special feature.
it('serves two orders of the same customer on one challan', function () {
    $first = ($this->makeOrder)('20');
    $second = ($this->makeOrder)('30');

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [
            ['product' => $this->product, 'quantity' => '20', 'orderLine' => $first->lines->first()],
            ['product' => $this->product, 'quantity' => '10', 'orderLine' => $second->lines->first()],
        ],
        createdBy: $this->owner,
    );

    expect($first->fresh()->status)->toBe(SalesOrderStatus::Delivered)
        ->and($second->fresh()->status)->toBe(SalesOrderStatus::PartiallyDelivered);
});

// A mixed challan: the ordered part and the extra, side by side (C-SO-5's
// "record the extra as a direct delivery line", proven to work).
it('carries an order line and a direct line on the same challan', function () {
    $order = ($this->makeOrder)('20');

    $delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [
            ['product' => $this->product, 'quantity' => '20', 'orderLine' => $order->lines->first()],
            ['product' => $this->product, 'quantity' => '5'],
        ],
        createdBy: $this->owner,
    );

    expect($delivery->lines)->toHaveCount(2)
        ->and($delivery->lines->first()->sales_order_line_id)->not->toBeNull()
        ->and($delivery->lines->last()->sales_order_line_id)->toBeNull()
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::Delivered)
        ->and((float) $order->fresh()->lines->first()->quantity_delivered)->toBe(20.0);
});

// ⚠⚠ The one engine, unchanged: an order-linked delivery still moves stock at
// FIFO and posts Dr GDNI / Cr Stock on Hand exactly as a direct one does.
it('still posts the GDNI journal and moves stock when order-linked', function () {
    $order = ($this->makeOrder)('40');

    ($this->deliverAgainst)($order, '25');

    // ⚠ `amount_minor`, signed whole paisa: positive debits, negative credits.
    // And non-emptiness FIRST - a sum over no rows is zero, which is exactly
    // what a broken posting looks like. Same trap DeliveryTest documents.
    $gdni = Account::where('code', '1155')->first();
    $gdniLines = JournalLine::where('account_id', $gdni->id)->get();

    expect($gdniLines)->not->toBeEmpty()
        ->and((int) $gdniLines->sum('amount_minor'))->toBe(25 * 500 * 100)
        ->and($this->product->fresh()->currentStockQuantity())->toBe('75');
});

it('leaves direct deliveries byte-for-byte untouched', function () {
    $delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '30']],
        createdBy: $this->owner,
    );

    expect($delivery->lines->first()->sales_order_line_id)->toBeNull()
        ->and(SalesOrder::count())->toBe(0);
});
