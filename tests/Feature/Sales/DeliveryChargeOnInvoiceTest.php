<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\CostOfGoodsSold;
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
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use App\Modules\Sales\Support\SalesTargetProgress;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A website order's agreed delivery charge is income of its own: billed once, as
 * its own line, on the first invoice that bills any of the order's goods, and
 * credited to 4300 Delivery Charges Income (Joy, 13 September).
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Delivery Books Co', 'slug' => 'delivery-books-co']);
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
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);

    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    Carbon::setTestNow('2026-09-13 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->order = fn (string $quantity, ?string $deliveryCharge, string $orderDiscount = '0'): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity]],
        createdBy: $this->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: $deliveryCharge, orderDiscount: $orderDiscount),
    );

    $this->deliver = fn (SalesOrder $order, string $quantity) => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
    );

    $this->createProps = fn () => $this->actingAs($this->staff)
        ->get('/sales/invoices/create?customer_id='.$this->customer->id)
        ->original->getData()['page']['props'];

    $this->bill = function (string $quantity, ?string $typedDiscount = null) {
        $billable = ($this->createProps)()['billable'];

        return $this->actingAs($this->staff)->post('/sales/invoices', array_filter([
            'customer_id' => $this->customer->id,
            'invoice_discount' => $typedDiscount,
            'lines' => [['delivery_line_id' => $billable[0]['delivery_line_id'], 'quantity' => $quantity]],
        ], fn ($value) => $value !== null));
    };

    $this->posted = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('bills the delivery charge as its own line and credits it to 4300, not sales revenue', function () {
    $order = ($this->order)('2', '120');
    ($this->deliver)($order, '2');

    ($this->bill)('2')->assertRedirect()->assertSessionHasNoErrors();
    $invoice = SalesInvoice::query()->latest('id')->with('lines')->first();

    $delivery = $invoice->lines->firstWhere('billed_delivery_for_order_id', $order->id);

    expect($invoice->lines)->toHaveCount(2)
        ->and($delivery->description)->toBe("Delivery charge ({$order->order_number})")
        ->and((string) $delivery->line_net)->toBe('120.0000')
        ->and($delivery->product_id)->toBeNull()
        ->and((float) $invoice->subtotal)->toBe(1820.0)
        ->and((float) $invoice->total)->toBe(1820.0)
        // Credits are negative paisa: goods to 4101, the charge to 4300.
        ->and(($this->posted)('sales_revenue'))->toBe(-170000)
        ->and(($this->posted)('delivery_income'))->toBe(-12000)
        ->and($order->agreedTotal())->toBe('1820.00');
});

it('bills the charge once, whole, on the first of several invoices for one order', function () {
    $order = ($this->order)('2', '120');
    ($this->deliver)($order, '2');

    ($this->bill)('1')->assertRedirect()->assertSessionHasNoErrors();
    ($this->bill)('1')->assertRedirect()->assertSessionHasNoErrors();

    [$first, $second] = SalesInvoice::query()->orderBy('id')->with('lines')->get()->all();

    expect($first->lines->whereNotNull('billed_delivery_for_order_id'))->toHaveCount(1)
        ->and($second->lines->whereNotNull('billed_delivery_for_order_id'))->toHaveCount(0)
        ->and((float) $first->total)->toBe(970.0)
        ->and((float) $second->total)->toBe(850.0)
        ->and(($this->posted)('delivery_income'))->toBe(-12000);
});

it('adds no delivery line for an order that agreed no charge', function () {
    $order = ($this->order)('1', null);
    ($this->deliver)($order, '1');

    ($this->bill)('1')->assertRedirect()->assertSessionHasNoErrors();

    expect(SalesInvoiceLine::query()->whereNotNull('billed_delivery_for_order_id')->count())->toBe(0)
        ->and(($this->posted)('delivery_income'))->toBe(0);
});

it('bills exactly what the order agreed when a coupon and a delivery charge meet', function () {
    $order = ($this->order)('2', '120', '170');
    ($this->deliver)($order, '2');

    ($this->bill)('2')->assertRedirect()->assertSessionHasNoErrors();
    $invoice = SalesInvoice::query()->latest('id')->first();

    // 1,700 of goods less the 170 coupon, plus 120 delivery.
    expect((float) $invoice->total)->toBe(1650.0)
        ->and($order->agreedTotal())->toBe('1650.00')
        ->and(($this->posted)('discounts_allowed'))->toBe(17000);
});

it('refuses a typed discount that would reach into the delivery charge', function () {
    $order = ($this->order)('1', '120');
    ($this->deliver)($order, '1');

    // 900 is under the 970 subtotal but over the 850 of goods.
    ($this->bill)('1', '900')->assertSessionHasErrors('invoice_discount');

    expect(SalesInvoice::query()->count())->toBe(0);
});

it('refunds on a full return what was paid for the goods, never a share of delivery', function () {
    $order = ($this->order)('2', '120', '170');
    $delivery = ($this->deliver)($order, '2');

    ($this->bill)('2')->assertRedirect()->assertSessionHasNoErrors();

    $return = app(RecordSalesReturn::class)->handle(
        delivery: $delivery->fresh('lines'),
        lines: [['deliveryLine' => $delivery->lines->first(), 'quantityToStock' => '2', 'quantityWrittenOff' => '0']],
        createdBy: $this->owner,
    );

    // The customer paid 1,530 for the goods; delivery was carried and stays billed.
    expect($return->creditTotalMoney()->toTaka())->toBe('1530.00');
});

it('shows the screen the charge before billing, and not again once it is billed', function () {
    $order = ($this->order)('2', '120');
    ($this->deliver)($order, '2');

    expect(($this->createProps)()['agreed_delivery_charges'])->toBe([
        ['order_number' => $order->order_number, 'amount' => '120.00'],
    ]);

    ($this->bill)('1')->assertRedirect()->assertSessionHasNoErrors();

    expect(($this->createProps)()['agreed_delivery_charges'])->toBe([]);
});

it('refuses a second delivery line for the same order in the database itself', function () {
    $order = ($this->order)('1', '120');
    ($this->deliver)($order, '1');
    ($this->bill)('1')->assertRedirect()->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->latest('id')->first();

    expect(fn () => $invoice->lines()->create([
        'business_id' => $invoice->business_id,
        'billed_delivery_for_order_id' => $order->id,
        'description' => 'Delivery charge again',
        'quantity' => '1',
        'unit_price' => '120',
        'line_net' => '120',
    ]))->toThrow(QueryException::class);
});

it('refuses to invoice a delivery charge with no income account mapped, naming the mapping', function () {
    $order = ($this->order)('1', '120');
    ($this->deliver)($order, '1');

    AccountMapping::query()->where('key', 'sales.delivery_income')->delete();

    ($this->bill)('1')->assertSessionHasErrors(['invoice_date' => 'No account is mapped to [sales.delivery_income] on 2026-09-13. Set it up under Accounting before posting this.']);

    expect(SalesInvoice::query()->count())->toBe(0);
});

it('keeps delivery income out of product revenue on the cost of goods sold report', function () {
    $order = ($this->order)('2', '120');
    ($this->deliver)($order, '2');
    ($this->bill)('2')->assertRedirect()->assertSessionHasNoErrors();

    $report = CostOfGoodsSold::forPeriod(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows->first()->productId)->toBe($this->product->id)
        ->and($report->totalRevenue()->toTaka())->toBe('1700.00');
});

// ⚠ Bug hunt 16 Oct 2026: a delivery charge is not a sale, and a seller's
// target counted it.
it('leaves the delivery charge out of the seller target progress', function () {
    $order = ($this->order)('2', '120');
    ($this->deliver)($order, '2');
    ($this->bill)('2')->assertSessionHasNoErrors();

    expect(SalesTargetProgress::actualFor($this->business->id, $this->staff->id, '2026-09-01', '2026-09-30'))
        ->toBe(1700.0);
});
