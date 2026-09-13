<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\CustomerExposure;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceOrderDiscount;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Website slice 2.5b: a discount agreed on the WHOLE order - a coupon - is billed
 * on the invoice and posts to 4210 Discounts Allowed (Joy, 13 September).
 *
 * ⚠⚠ The agreement is billed automatically and exactly once: the invoice that
 * finishes an order takes whatever is unbilled, every other a share of the goods
 * it covers. A typed whole-invoice discount is added to it, never replaces it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Coupon Books Co', 'slug' => 'coupon-books-co']);
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
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
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

    $this->order = fn (string $quantity, string $orderDiscount): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity]],
        createdBy: $this->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal, orderDiscount: $orderDiscount, couponCode: 'WELCOME10'),
    );

    $this->deliver = fn (SalesOrder $order, string $quantity) => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
    );

    $this->bill = function (string $quantity, ?string $typedDiscount = null) {
        $billable = $this->actingAs($this->staff)
            ->get('/sales/invoices/create?customer_id='.$this->customer->id)
            ->original->getData()['page']['props']['billable'];

        $this->actingAs($this->staff)->post('/sales/invoices', array_filter([
            'customer_id' => $this->customer->id,
            'invoice_discount' => $typedDiscount,
            'lines' => [['delivery_line_id' => $billable[0]['delivery_line_id'], 'quantity' => $quantity]],
        ], fn ($value) => $value !== null))->assertRedirect();

        return SalesInvoice::query()->latest('id')->first();
    };

    $this->discountsAllowed = fn () => (int) JournalLine::where('account_id', Account::bySlug('discounts_allowed')->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('bills the order discount on the invoice and posts it to Discounts Allowed', function () {
    $order = ($this->order)('2', '170');
    ($this->deliver)($order, '2');

    $invoice = ($this->bill)('2');

    // 2 x 850 = 1,700 less the coupon's 170 = 1,530. The LINE stays at full
    // price: a coupon is contra-revenue, not a cheaper jar.
    expect((string) $invoice->invoice_discount)->toBe('170.0000')
        ->and((float) $invoice->total)->toBe(1530.0)
        ->and((string) $invoice->lines->first()->line_discount)->toBe('0.0000')
        ->and(($this->discountsAllowed)())->toBe(17000)
        ->and($order->agreedTotal())->toBe('1530.00');
});

it('bills a partly invoiced order in shares, the last one taking the remainder', function () {
    // Three thirds of 1.00 round to 0.33 each and lose a paisa.
    $order = ($this->order)('3', '1.00');
    ($this->deliver)($order, '3');

    ($this->bill)('1');
    ($this->bill)('1');
    ($this->bill)('1');

    expect(SalesInvoiceOrderDiscount::query()->orderBy('id')->pluck('amount')->map(fn ($a) => (string) $a)->all())
        ->toBe(['0.3300', '0.3300', '0.3400'])
        ->and(($this->discountsAllowed)())->toBe(100);
});

it('adds a typed whole-invoice discount to the agreed one rather than replacing it', function () {
    $order = ($this->order)('2', '170');
    ($this->deliver)($order, '2');

    $invoice = ($this->bill)('2', '30');

    expect((string) $invoice->invoice_discount)->toBe('200.0000')
        ->and((float) $invoice->total)->toBe(1500.0);
});

it('refuses an order discount larger than the goods the engine priced', function () {
    ($this->order)('1', '850.01');
})->throws(ValidationException::class, 'cannot be negative or larger than the goods');

it('shows the screen what the order agreed, and counts it in credit exposure', function () {
    $order = ($this->order)('2', '170');
    ($this->deliver)($order, '1');

    $props = $this->actingAs($this->staff)
        ->get('/sales/invoices/create?customer_id='.$this->customer->id)
        ->original->getData()['page']['props'];

    // Half the goods delivered and billable: half the coupon, previewed.
    expect($props['agreed_order_discounts'])->toBe([
        ['order_number' => $order->order_number, 'coupon_code' => 'WELCOME10', 'amount' => '85.00'],
    ]);

    // One jar still to deliver at 850, less its half of the coupon.
    expect(app(CustomerExposure::class)->handle($this->customer)['undelivered_orders'])->toBe('765.00');
});
