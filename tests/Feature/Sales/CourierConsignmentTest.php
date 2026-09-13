<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
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
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier settlement slice C1: a challan handed to a courier is invoiced when
 * the courier confirms delivery (Joy, 13 September), and the cash on delivery it
 * collected is recorded as the customer's receipt into the courier's account,
 * 1145 Receivable from Couriers.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Parcel Co', 'slug' => 'parcel-co']);
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

    Carbon::setTestNow('2026-09-14 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
    $this->cashBox = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash box', 'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);

    $this->order = fn (string $quantity, ?string $deliveryCharge = null, string $orderDiscount = '0'): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity]],
        createdBy: $this->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal, deliveryCharge: $deliveryCharge, orderDiscount: $orderDiscount),
    );

    $this->deliver = fn (SalesOrder $order, string $quantity): Delivery => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
        deliveredAt: '2026-09-13 11:00:00',
    );

    $this->challans = fn (SalesOrder $order) => $this->actingAs($this->staff)
        ->get('/sales/orders/'.$order->id)
        ->original->getData()['page']['props']['challans'];

    $this->book = fn (Delivery $delivery, string $cod, string $number = 'DB140926ABCD') => $this->actingAs($this->staff)
        ->post("/sales/deliveries/{$delivery->id}/consignment", [
            'money_account_id' => $this->courier->id,
            'consignment_number' => $number,
            'cod_amount' => $cod,
        ]);

    $this->report = fn (Consignment $consignment, string $outcome, string $collected, array $lines = []) => $this->actingAs($this->staff)
        ->post("/sales/consignments/{$consignment->id}/outcome", [
            'outcome' => $outcome,
            'outcome_on' => '2026-09-14',
            'collected_amount' => $collected,
            'lines' => $lines,
        ]);

    $this->stock = fn () => (float) $this->product->fresh()->currentStockQuantity();
    $this->posted = fn (string $slug) => (int) JournalLine::where('account_id', Account::bySlug($slug)->id)->sum('amount_minor');
});

afterEach(fn () => Carbon::setTestNow());

it('invoices a delivered parcel and records what the courier collected as money it holds', function () {
    $order = ($this->order)('2', '120', '170');
    $delivery = ($this->deliver)($order, '2');

    // 1,700 of goods, less the 170 coupon, plus 120 delivery.
    expect(($this->challans)($order)[0]['cod_preview'])->toBe('1650.00');

    ($this->book)($delivery, '1650')->assertRedirect()->assertSessionHasNoErrors();
    $consignment = Consignment::query()->sole();

    expect(SalesInvoice::query()->count())->toBe(0);

    ($this->report)($consignment, 'delivered', '1650')->assertRedirect()->assertSessionHasNoErrors();

    $consignment->refresh();
    $invoice = SalesInvoice::query()->sole();
    $receipt = CustomerReceipt::query()->sole();

    expect($consignment->status)->toBe(ConsignmentStatus::Delivered)
        ->and($consignment->sales_invoice_id)->toBe($invoice->id)
        ->and($consignment->customer_receipt_id)->toBe($receipt->id)
        ->and((float) $invoice->total)->toBe(1650.0)
        ->and($invoice->invoice_date->toDateString())->toBe('2026-09-14')
        ->and($invoice->outstanding()->minor)->toBe(0)
        ->and($receipt->money_account_id)->toBe($this->courier->id)
        ->and($receipt->reference)->toBe('DB140926ABCD')
        // The courier owes the whole 1,650; the customer owes nothing.
        ->and(($this->posted)('courier_receivable'))->toBe(165000)
        ->and(($this->posted)('accounts_receivable'))->toBe(0)
        ->and((int) JournalLine::where('money_account_id', $this->courier->id)->sum('amount_minor'))->toBe(165000);
});

it('refuses a collected amount that differs from the invoice, and writes nothing', function () {
    $order = ($this->order)('2', '120');
    $delivery = ($this->deliver)($order, '2');
    ($this->book)($delivery, '1820');
    $consignment = Consignment::query()->sole();

    ($this->report)($consignment, 'delivered', '1800')->assertSessionHasErrors('collected_amount');

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Booked)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and(CustomerReceipt::query()->count())->toBe(0)
        ->and((float) $delivery->lines()->first()->quantity_invoiced)->toBe(0.0);
});

it('invoices what the customer took from a partly delivered parcel and brings the rest back', function () {
    $order = ($this->order)('3');
    $delivery = ($this->deliver)($order, '3');
    ($this->book)($delivery, '2550');
    $consignment = Consignment::query()->sole();
    $lineId = $delivery->lines()->first()->id;

    expect(($this->stock)())->toBe(97.0);

    ($this->report)($consignment, 'partially_delivered', '1700', [
        ['delivery_line_id' => $lineId, 'taken' => '2', 'written_off' => '0'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $consignment->refresh();
    $line = $delivery->lines()->first();
    $return = SalesReturn::query()->sole();

    expect($consignment->status)->toBe(ConsignmentStatus::PartiallyDelivered)
        ->and((float) SalesInvoice::query()->sole()->total)->toBe(1700.0)
        ->and($consignment->sales_return_id)->toBe($return->id)
        ->and((float) $line->quantity_invoiced)->toBe(2.0)
        ->and((float) $line->quantity_returned)->toBe(1.0)
        ->and(($this->stock)())->toBe(98.0);
});

it('raises no invoice for a returned parcel and writes off what came back damaged', function () {
    $order = ($this->order)('2', '120');
    $delivery = ($this->deliver)($order, '2');
    ($this->book)($delivery, '1820');
    $consignment = Consignment::query()->sole();

    ($this->report)($consignment, 'returned', '0', [
        ['delivery_line_id' => $delivery->lines()->first()->id, 'written_off' => '1'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $return = SalesReturn::query()->with('lines')->sole();

    expect($consignment->fresh()->status)->toBe(ConsignmentStatus::Returned)
        ->and(SalesInvoice::query()->count())->toBe(0)
        ->and((float) $return->lines->first()->quantity_to_stock)->toBe(1.0)
        ->and((float) $return->lines->first()->quantity_written_off)->toBe(1.0)
        ->and(($this->stock)())->toBe(99.0);
});

it('refuses a returned parcel that collected money, and brings nothing back', function () {
    $order = ($this->order)('1');
    $delivery = ($this->deliver)($order, '1');
    ($this->book)($delivery, '850');

    ($this->report)(Consignment::query()->sole(), 'returned', '100')->assertSessionHasErrors('collected_amount');

    expect(SalesReturn::query()->count())->toBe(0)
        ->and(($this->stock)())->toBe(99.0);
});

it('invoices a prepaid parcel on delivery and leaves the invoice for its own receipt', function () {
    $order = ($this->order)('2');
    $delivery = ($this->deliver)($order, '2');
    ($this->book)($delivery, '0');

    ($this->report)(Consignment::query()->sole(), 'delivered', '0')->assertRedirect()->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    expect($invoice->outstanding()->toTaka())->toBe('1700.00')
        ->and(CustomerReceipt::query()->count())->toBe(0);
});

it('keeps a parcel with the courier off the invoice screen, and refuses billing or returning it by hand', function () {
    $order = ($this->order)('2');
    $delivery = ($this->deliver)($order, '2');
    ($this->book)($delivery, '1700');

    $billable = $this->actingAs($this->staff)
        ->get('/sales/invoices/create?customer_id='.$this->customer->id)
        ->original->getData()['page']['props']['billable'];

    expect($billable)->toBe([]);

    $this->actingAs($this->staff)->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [['delivery_line_id' => $delivery->lines()->first()->id, 'quantity' => '2']],
    ])->assertSessionHasErrors('lines.0.deliveryLine');

    expect(fn () => app(RecordSalesReturn::class)->handle(
        delivery: $delivery,
        lines: [['deliveryLine' => $delivery->lines()->first(), 'quantityToStock' => '1', 'quantityWrittenOff' => '0']],
        createdBy: $this->owner,
    ))->toThrow(ValidationException::class, 'is out with Pathao');

    expect(SalesInvoice::query()->count())->toBe(0);
});

it('refuses a receipt typed into a courier account, and never offers one to take money by hand', function () {
    $order = ($this->order)('1');
    $delivery = ($this->deliver)($order, '1');

    $this->actingAs($this->staff)->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [['delivery_line_id' => $delivery->lines()->first()->id, 'quantity' => '1']],
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->sole();

    $offered = collect($this->actingAs($this->staff)->get('/sales/invoices/'.$invoice->id)
        ->original->getData()['page']['props']['moneyAccounts'])->pluck('id');

    expect($offered->all())->toContain($this->cashBox->id)
        ->and($offered->all())->not->toContain($this->courier->id);

    $this->actingAs($this->owner)->post("/sales/invoices/{$invoice->id}/receipts", [
        'money_account_id' => $this->courier->id,
        'amount' => '850',
        'received_on' => '2026-09-14',
    ])->assertSessionHasErrors('money_account_id');

    expect(CustomerReceipt::query()->count())->toBe(0);
});

it('hands only an untouched challan to a courier, and each consignment number once', function () {
    $invoiced = ($this->deliver)(($this->order)('1'), '1');
    $this->actingAs($this->staff)->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => [['delivery_line_id' => $invoiced->lines()->first()->id, 'quantity' => '1']],
    ])->assertSessionHasNoErrors();

    ($this->book)($invoiced, '850')->assertSessionHasErrors('consignment_number');

    $first = ($this->deliver)(($this->order)('1'), '1');
    $second = ($this->deliver)(($this->order)('1'), '1');

    ($this->book)($first, '850', 'DB-SAME')->assertSessionHasNoErrors();
    ($this->book)($second, '850', 'DB-SAME')->assertSessionHasErrors('consignment_number');
    ($this->book)($first, '850', 'DB-OTHER')->assertSessionHasErrors('consignment_number');

    expect(Consignment::query()->count())->toBe(1);
});

it('records a courier answer once', function () {
    $order = ($this->order)('1');
    $delivery = ($this->deliver)($order, '1');
    ($this->book)($delivery, '850');
    $consignment = Consignment::query()->sole();

    ($this->report)($consignment, 'delivered', '850')->assertSessionHasNoErrors();
    ($this->report)($consignment, 'returned', '0')->assertSessionHasErrors('outcome');

    expect(SalesInvoice::query()->count())->toBe(1)
        ->and(SalesReturn::query()->count())->toBe(0);
});

it('opens the parcels list on what is still with the courier', function () {
    $order = ($this->order)('1');
    ($this->book)(($this->deliver)($order, '1'), '850');

    $list = function (string $query = '') {
        $response = $this->actingAs($this->staff)->get('/sales/consignments'.$query);
        $response->assertOk();

        return $response->original->getData()['page']['props']['consignments']['data'];
    };

    expect($list())->toHaveCount(1)
        ->and($list()[0]['order_id'])->toBe($order->id);

    ($this->report)(Consignment::query()->sole(), 'delivered', '850')->assertSessionHasNoErrors();

    expect($list())->toHaveCount(0)
        ->and($list('?status=all'))->toHaveCount(1);
});
