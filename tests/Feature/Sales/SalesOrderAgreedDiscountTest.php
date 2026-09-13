<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
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
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Website slice 2.5a: an order line can carry a discount agreed when the order
 * was taken, and the invoice bills THAT discount - the discount's C-SO-4.
 *
 * ⚠⚠ Before this, a sales order could hold no discount at all and the invoice
 * screen's discount box started at zero. A bundle sold on a shop below the price
 * of its parts would have been quoted at the bundle price and billed at the full
 * price of every jar, with every test green and every journal balanced.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Discount Co', 'slug' => 'discount-co']);
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
    $this->customer = Customer::create([
        'business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail,
        'credit_limit' => '100000',
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $this->product->id,
        'unit_price' => '850', 'effective_from' => '2026-01-01',
    ]);

    Carbon::setTestNow('2026-09-13 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    app(RecordStockIn::class)->handle(
        $this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00'
    );

    $this->order = fn (string $quantity, string $discount, ?string $bundle = null): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'discount' => $discount, 'bundle_label' => $bundle]],
        createdBy: $this->owner,
    );

    $this->deliver = fn (SalesOrder $order, string $quantity) => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
    );

    $this->billable = function () {
        return $this->actingAs($this->staff)
            ->get('/sales/invoices/create?customer_id='.$this->customer->id)
            ->original->getData()['page']['props']['billable'];
    };
});

afterEach(fn () => Carbon::setTestNow());

it('records an agreed discount on the line and states the order net of it', function () {
    $order = ($this->order)('2', '250', 'Honey Pair');
    $line = $order->lines->first();

    // The jar is still recorded at its price-list price. The saving sits beside
    // it rather than being hidden inside a cheaper unit price.
    expect((string) $line->unit_price)->toBe('850.0000')
        ->and((string) $line->line_discount)->toBe('250.0000')
        ->and($line->bundle_label)->toBe('Honey Pair')
        ->and($order->goodsTotal())->toBe('1450.00');
});

it('refuses a discount larger than the line the engine priced', function () {
    // ⚠ Checked against the price the ENGINE resolved (850 x 1), never against a
    // figure the caller worked out for itself.
    ($this->order)('1', '850.01');
})->throws(ValidationException::class, 'larger than the line itself');

it('refuses a negative discount', function () {
    ($this->order)('1', '-5');
})->throws(ValidationException::class, 'cannot be negative');

it('bills the agreed discount when the invoice screen types none', function () {
    $order = ($this->order)('2', '250');
    ($this->deliver)($order, '2');

    $billable = ($this->billable)();

    // The screen is shown the agreement...
    expect($billable[0]['agreed_discount'])->toBe('250.00');

    // ...and sending the line back with NO discount bills it.
    $this->actingAs($this->staff)->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [['delivery_line_id' => $billable[0]['delivery_line_id'], 'quantity' => '2', 'discount' => '']],
    ])->assertRedirect();

    $invoice = SalesInvoice::first();

    expect((string) $invoice->lines->first()->line_discount)->toBe('250.0000')
        ->and((float) $invoice->total)->toBe(1450.0)
        // ⚠ A LINE discount, so revenue is credited net and 4210 is untouched.
        ->and((float) $invoice->invoice_discount)->toBe(0.0);
});

it('bills a partly invoiced line in shares that add back to the agreement exactly', function () {
    // 100.01 over 40 jars does not divide into whole paisa, which is the point.
    $order = ($this->order)('40', '100.01');
    ($this->deliver)($order, '40');

    $bill = function (string $quantity) {
        $billable = ($this->billable)();

        $this->actingAs($this->staff)->post('/sales/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [['delivery_line_id' => $billable[0]['delivery_line_id'], 'quantity' => $quantity]],
        ])->assertRedirect();
    };

    $bill('25');
    $bill('15');

    $shares = SalesInvoiceLine::query()->orderBy('id')->pluck('line_discount')->map(fn ($d) => (string) $d)->all();

    // 25/40 of 100.01 is 62.50625, rounded to 62.51; the rest is 37.50. ⚠ Here a
    // rounded share and the remainder happen to agree, so this pins the shape;
    // the next test is the one where they do not.
    expect($shares)->toBe(['62.5100', '37.5000'])
        ->and(bcadd($shares[0], $shares[1], 2))->toBe('100.01');
});

it('gives the last portion whatever discount is still unbilled', function () {
    // Three thirds of 1.00 round to 0.33 each and lose a paisa. The last one
    // must take 0.34.
    $order = ($this->order)('3', '1.00');
    ($this->deliver)($order, '3');

    foreach (['1', '1', '1'] as $quantity) {
        $billable = ($this->billable)();

        $this->actingAs($this->staff)->post('/sales/invoices', [
            'customer_id' => $this->customer->id,
            'lines' => [['delivery_line_id' => $billable[0]['delivery_line_id'], 'quantity' => $quantity]],
        ])->assertRedirect();
    }

    $shares = SalesInvoiceLine::query()->orderBy('id')->pluck('line_discount')->map(fn ($d) => (string) $d)->all();

    expect($shares)->toBe(['0.3300', '0.3300', '0.3400']);
});

it('treats a typed discount, zero included, as an override', function () {
    $order = ($this->order)('2', '250');
    ($this->deliver)($order, '2');

    $billable = ($this->billable)();

    $this->actingAs($this->staff)->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [['delivery_line_id' => $billable[0]['delivery_line_id'], 'quantity' => '2', 'discount' => '0']],
    ])->assertRedirect();

    expect((string) SalesInvoice::first()->lines->first()->line_discount)->toBe('0.0000');
});

it('counts what the customer agreed to pay in their credit exposure', function () {
    ($this->order)('2', '250');

    // 2 x 850 less the agreed 250: the customer will owe 1,450, not 1,700.
    expect(app(CustomerExposure::class)->handle($this->customer)['undelivered_orders'])->toBe('1450.00');
});
