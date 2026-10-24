<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Journal;
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
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales orders slice 4: the order book screens and their permission layer,
 * plus the invoice-from-challan flow (F1) that finally makes billing a challan
 * reachable - at the ORDER price where the goods trace to an order (C-SO-4).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Order Screen Co', 'slug' => 'order-screen-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
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
        'credit_limit' => '100000',
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '850',
        'effective_from' => '2026-01-01',
    ]);

    Carbon::setTestNow('2026-08-11 10:00:00');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);
        $this->currentUser = $user;

        return $this->actingAs($user);
    };

    app(RecordStockIn::class)->handle(
        $this->product, $this->warehouse, '100', '500',
        User::factory()->create(), occurredAt: '2026-08-01 09:00:00'
    );

    $this->ownerOrder = function (string $quantity = '40') {
        $owner = User::factory()->create();
        $owner->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $owner->assignRole('Owner');

        return app(CreateSalesOrder::class)->handle(
            customer: $this->customer,
            lines: [['product' => $this->product, 'quantity' => $quantity]],
            createdBy: $owner,
        );
    };
});

afterEach(fn () => Carbon::setTestNow());

it('finds an order by the reference the customer holds, not only by ours', function () {
    // ⚠⚠ A customer who ordered on the website quotes `BNC-...` and has never
    // seen `SO-FY2026-0001`. Before slice 2.4 this search covered our own
    // number and the customer's name only, so the one number the person on the
    // telephone is reading out could not be looked up at all.
    $order = ($this->ownerOrder)();
    $order->forceFill(['shop_reference' => 'BNC-20260912-ABCD2345'])->save();

    ($this->asRole)('Staff')->get('/sales/orders?search=ABCD2345')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $order->id));
});

it('puts the phone number and the destination in front of whoever confirms a website order', function () {
    // ⭐⭐ Slice 2.4 lands website orders in this screen's approval queue on
    // purpose - nobody in the company has spoken to the buyer. So the screen
    // has to carry what the confirmation call needs. Without it the gate is a
    // queue nobody can act on, and goods cannot leave an unapproved order.
    $order = ($this->ownerOrder)();
    $order->forceFill(['channel' => 'website', 'shop_reference' => 'BNC-20260912-ABCD2345', 'delivery_charge' => '120'])->save();
    $order->address()->create([
        'business_id' => $this->business->id,
        'recipient_name' => 'Karim Uddin',
        'recipient_phone' => '01711000000',
        'district' => 'Bagerhat',
        'thana' => 'Fakirhat',
        'address_line' => 'House 4, Road 2',
    ]);

    ($this->asRole)('Staff')->get('/sales/orders/'.$order->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('order.channel', 'website')
            ->where('order.address.recipient_phone', '01711000000')
            ->where('order.address.district', 'Bagerhat')
            ->where('order.address.thana', 'Fakirhat'));
});

it('shows the order book to anyone who may view sales', function () {
    ($this->asRole)('Staff')->get('/sales/orders')->assertOk();
});

it('lets Staff record an order that then waits for approval', function () {
    ($this->asRole)('Staff')->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '40']],
    ])->assertRedirect();

    $order = SalesOrder::first();

    expect($order)->not->toBeNull()
        ->and($order->approvalRequest->isPending())->toBeTrue()
        ->and((float) $order->lines->first()->unit_price)->toBe(850.0);
});

it('self-approves a Manager-created order', function () {
    ($this->asRole)('Manager')->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '40']],
    ]);

    expect(SalesOrder::first()->isDeliverable())->toBeTrue();
});

// ⚠⚠ Audit 13 Oct: the invoice bills an order line at the order's price as
// already agreed, so the per-bill discount cap must hold on the order itself,
// or a Manager could type 40% off and approve their own order.
it('refuses an order price that gives more than the Manager cap', function () {
    ($this->asRole)('Manager')->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_price' => '510']], // 40% off 850
    ])->assertSessionHasErrors('lines.0.unit_price');

    expect(SalesOrder::count())->toBe(0);
});

it('lets a Manager price an order inside the cap, and the Owner beyond it', function () {
    ($this->asRole)('Manager')->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_price' => '830']], // 2.4% off
    ])->assertSessionHasNoErrors();

    ($this->asRole)('Owner')->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_price' => '510']],
    ])->assertSessionHasNoErrors();

    expect(SalesOrder::count())->toBe(2);
});

it('answers the exposure question and blocks nothing (C-SO-3)', function () {
    $order = ($this->ownerOrder)('40'); // 40 x 850 = 34,000 undelivered

    ($this->asRole)('Staff')
        ->postJson('/sales/orders/exposure', ['customer_id' => $this->customer->id])
        ->assertOk()
        ->assertJson([
            'credit_limit' => '100000.0000',
            'open_invoices' => '0.00',
            'undelivered_orders' => '34000.00',
            'total' => '34000.00',
        ]);
});

// ⚠⚠ F29 (practice run, 17 Oct 2026): goods delivered and not yet invoiced
// read as 0 in the exposure, so the credit check could not see them.
it('counts goods delivered but not yet invoiced in the exposure, and stops counting once billed', function () {
    $order = ($this->ownerOrder)('40'); // 40 x 850

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '25', 'orderLine' => $order->lines->first()]],
        createdBy: User::factory()->create(),
    );

    // 25 out of the door (21,250) + 15 still to come (12,750).
    ($this->asRole)('Staff')
        ->postJson('/sales/orders/exposure', ['customer_id' => $this->customer->id])
        ->assertOk()
        ->assertJson([
            'open_invoices' => '0.00',
            'delivered_not_invoiced' => '21250.00',
            'undelivered_orders' => '12750.00',
            'total' => '34000.00',
        ]);

    // A challan raised with no order counts at the customer's price.
    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '2']],
        createdBy: User::factory()->create(),
    );

    ($this->asRole)('Staff')
        ->postJson('/sales/orders/exposure', ['customer_id' => $this->customer->id])
        ->assertJson(['delivered_not_invoiced' => '22950.00', 'total' => '35700.00']);

    // Once the order's challan is billed it moves to open invoices, never both.
    $line = DeliveryLine::query()->whereNotNull('sales_order_line_id')->firstOrFail();

    ($this->asRole)('Owner')->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [['delivery_line_id' => $line->id, 'quantity' => '25']],
    ])->assertSessionHasNoErrors();

    ($this->asRole)('Staff')
        ->postJson('/sales/orders/exposure', ['customer_id' => $this->customer->id])
        ->assertJson([
            'open_invoices' => '21250.00',
            'delivered_not_invoiced' => '1700.00',
            'undelivered_orders' => '12750.00',
            'total' => '35700.00',
        ]);
});

describe('who may do what', function () {
    it('stops Staff from approving an order', function () {
        $order = ($this->ownerOrder)();
        $order->approvalRequest->update(['status' => ApprovalStatus::Pending]);

        ($this->asRole)('Staff')->post("/sales/orders/{$order->id}/approve")->assertForbidden();
    });

    it('lets a Manager approve a pending order', function () {
        $order = ($this->ownerOrder)();
        $order->approvalRequest->update(['status' => ApprovalStatus::Pending]);

        ($this->asRole)('Manager')->post("/sales/orders/{$order->id}/approve")->assertRedirect();

        expect($order->fresh()->isDeliverable())->toBeTrue();
    });

    // ⚠ C-SO-6: erasing a commitment is a management decision.
    it('stops Staff from cancelling or closing an order', function () {
        $order = ($this->ownerOrder)();

        ($this->asRole)('Staff')->post("/sales/orders/{$order->id}/cancel")->assertForbidden();
        ($this->asRole)('Staff')->post("/sales/orders/{$order->id}/close", ['reason' => 'x'])->assertForbidden();
    });

    it('lets a Manager cancel an untouched order', function () {
        $order = ($this->ownerOrder)();

        ($this->asRole)('Manager')->post("/sales/orders/{$order->id}/cancel", ['reason' => 'Customer called to cancel'])->assertRedirect();

        expect($order->fresh()->status)->toBe(SalesOrderStatus::Cancelled);
    });
});

it('delivers against the order from its own screen', function () {
    $order = ($this->ownerOrder)('40');

    ($this->asRole)('Staff')->post("/sales/orders/{$order->id}/deliver", [
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '25']],
    ])->assertRedirect();

    $order->refresh();
    expect($order->status)->toBe(SalesOrderStatus::PartiallyDelivered)
        ->and((float) $order->lines->first()->quantity_delivered)->toBe(25.0);
});

// ⚠⚠ F1 + C-SO-4, end to end: the challan is billed from the create screen,
// and the price offered and used is the ORDER price - even though the price
// RULE changed between order and invoice. This is the sentence the whole
// module exists for.
it('bills a challan at the order price, not the rule of the day', function () {
    $order = ($this->ownerOrder)('40'); // agreed at 850

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '25', 'orderLine' => $order->lines->first()]],
        createdBy: $this->customer->createdBy ?? User::factory()->create(),
    );

    // The rule rises AFTER the order was agreed.
    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '999',
        'effective_from' => '2026-08-10',
    ]);

    // The create screen offers the ORDER price for the order-traced line.
    $response = ($this->asRole)('Staff')->get('/sales/invoices/create?customer_id='.$this->customer->id);
    $response->assertOk();

    $billable = $response->original->getData()['page']['props']['billable'];
    expect($billable)->toHaveCount(1)
        ->and($billable[0]['unit_price'])->toBe('850.0000')
        ->and($billable[0]['price_source'])->toContain($order->order_number);

    // And storing WITHOUT a price override bills at the order price too.
    ($this->asRole)('Staff')->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [[
            'delivery_line_id' => $billable[0]['delivery_line_id'],
            'quantity' => '25',
        ]],
    ])->assertRedirect();

    $invoice = SalesInvoice::first();

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->lines->first()->unit_price)->toBe(850.0)
        ->and((float) $invoice->total)->toBe(25 * 850.0);
});

/**
 * C-DSC-2, and Joy's own case: the pickles are printed MRP 1350 and sold at 999.
 *
 * ⚠⚠ The engine has always accepted a line discount and the controller has
 * always validated it. The INVOICE SCREEN never sent one, so the only discount
 * anyone could enter on a credit sale was the whole-invoice kind - which posts
 * to 4210 Discounts Allowed and leaves the product's own margin reading as if it
 * sold at full price. Every report would still have balanced.
 */
it('bills a line at its list price less a discount, and credits revenue net', function () {
    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '1']],
        createdBy: User::factory()->create(),
    );

    $response = ($this->asRole)('Staff')->get('/sales/invoices/create?customer_id='.$this->customer->id);
    $billable = $response->original->getData()['page']['props']['billable'];

    // ⚠ 26% off is above every cap but the Owner's (13 Oct 2026: the counter's
    // discount rules now apply to the invoice too).
    ($this->asRole)('Owner')->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [[
            'delivery_line_id' => $billable[0]['delivery_line_id'],
            'quantity' => '1',
            'unit_price' => '1350',
            'discount' => '351',
        ]],
    ])->assertRedirect();

    $invoice = SalesInvoice::first();
    $line = $invoice->lines->first();

    // The list price is still on the line - the jar really is printed 1350 -
    // and the concession is recorded beside it rather than hidden in the price.
    expect((float) $line->unit_price)->toBe(1350.0)
        ->and((float) $line->line_discount)->toBe(351.0)
        ->and((float) $invoice->subtotal)->toBe(999.0)
        ->and((float) $invoice->total)->toBe(999.0)
        // ⚠ And NOT contra-revenue. A line discount reduces the line.
        ->and((float) $invoice->invoice_discount)->toBe(0.0);
});

it('refuses a line discount bigger than the line, from the screen', function () {
    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '1']],
        createdBy: User::factory()->create(),
    );

    $billable = ($this->asRole)('Staff')
        ->get('/sales/invoices/create?customer_id='.$this->customer->id)
        ->original->getData()['page']['props']['billable'];

    ($this->asRole)('Staff')->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [[
            'delivery_line_id' => $billable[0]['delivery_line_id'],
            'quantity' => '1',
            'unit_price' => '1350',
            'discount' => '1400',
        ]],
    ])->assertSessionHasErrors('lines.0.discount');

    expect(SalesInvoice::count())->toBe(0);
});

it('bills a direct challan at the resolver price of the invoice date', function () {
    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10']],
        createdBy: User::factory()->create(),
    );

    ($this->asRole)('Staff')->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [[
            'delivery_line_id' => DeliveryLine::first()->id,
            'quantity' => '10',
        ]],
    ])->assertRedirect();

    expect((float) SalesInvoice::first()->lines->first()->unit_price)->toBe(850.0);
});

// ⚠ C-SO-2: no reservation - the committed-vs-on-hand report is the honest
// substitute, and it must only count APPROVED, still-open promises.
it('reports committed vs on hand, and flags a shortfall', function () {
    $order = ($this->ownerOrder)('40');
    ($this->ownerOrder)('75'); // 40 + 75 = 115 committed against 100 on hand

    $response = ($this->asRole)('Staff')->get('/sales/orders');
    $committed = $response->original->getData()['page']['props']['committed'];

    expect($committed)->toHaveCount(1)
        ->and($committed[0]['committed'])->toBe('115.0000')
        ->and($committed[0]['on_hand'])->toBe('100.0000')
        ->and($committed[0]['short'])->toBeTrue();

    // Delivering part of one order shrinks the committed figure...
    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '25', 'orderLine' => $order->lines->first()]],
        createdBy: User::factory()->create(),
    );

    $committed = ($this->asRole)('Staff')->get('/sales/orders')
        ->original->getData()['page']['props']['committed'];

    // 115 - 25 delivered = 90 committed, 75 on hand: still short.
    expect($committed[0]['committed'])->toBe('90.0000')
        ->and($committed[0]['on_hand'])->toBe('75.0000')
        ->and($committed[0]['short'])->toBeTrue();
});

it('excludes pending, cancelled and closed orders from the committed figure', function () {
    // A pending (unapproved) order is not yet a commitment.
    $staffUser = User::factory()->create();
    $staffUser->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $staffUser->assignRole('Staff');
    app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => '30']],
        createdBy: $staffUser,
    );

    // A cancelled order stopped being owed.
    $cancelled = ($this->ownerOrder)('20');
    $cancelled->update(['status' => SalesOrderStatus::Cancelled]);

    $committed = ($this->asRole)('Staff')->get('/sales/orders')
        ->original->getData()['page']['props']['committed'];

    expect($committed)->toBeEmpty();
});

it('keeps the whole order flow invisible to the ledger until delivery', function () {
    ($this->asRole)('Manager')->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '40']],
    ]);

    expect(Journal::count())->toBe(0);
});
