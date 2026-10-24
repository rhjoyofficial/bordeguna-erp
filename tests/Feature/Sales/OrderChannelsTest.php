<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockReservationOverride;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\DeliveryMethod;
use App\Modules\Sales\Enums\OrderConfirmation;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ B18 (Joy's initial plan R91 to R96, 25 Oct 2026, Q2 = B): website, phone,
 * WhatsApp, Facebook and walk-in orders typed with a channel, confirmed by a
 * call, with gifts, an order discount, a delivery method and a payment way.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::create(['name' => 'Channel Co', 'slug' => 'channel-co']);
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

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR', 'is_default' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Bottles', 'abbreviation' => 'btl']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-OIL', 'name' => 'Mustard Oil 1L', 'type' => ProductType::FinishedGood,
    ]);
    $this->gift = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-PICKLE', 'name' => 'Pickle sample', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders', 'type' => CustomerType::Dealer, 'credit_limit' => '100000000']);
    $this->other = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Store', 'type' => CustomerType::Dealer, 'credit_limit' => '100000000']);

    $this->owner = User::factory()->create(['name' => 'Joy']);
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
    $this->manager = User::factory()->create(['name' => 'Mitu']);
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');
    $this->staff = User::factory()->create(['name' => 'Sifat']);
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    Carbon::setTestNow('2026-10-25 10:00:00');

    foreach ([$this->product, $this->gift] as $product) {
        ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'unit_price' => '300', 'effective_from' => '2026-01-01', 'created_by' => $this->owner->id]);
    }

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '500', '200', $this->owner, occurredAt: '2026-10-01 09:00:00');
    app(RecordStockIn::class)->handle($this->gift, $this->warehouse, '50', '40', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->order = fn (string $qty, ?User $by = null, ?Customer $customer = null) => app(CreateSalesOrder::class)->handle(
        customer: $customer ?? $this->customer,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $by ?? $this->owner,
        warehouse: $this->warehouse,
    );

    $this->address = ['name' => 'Karim', 'phone' => '01711000000', 'district' => 'Dhaka', 'thana' => 'Mirpur', 'address_line' => 'House 7, Road 3'];

    // A typed order, as the form sends it.
    $this->post = fn (array $extra = [], ?User $by = null) => $this->actingAs($by ?? $this->owner)->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'ordered_at' => '2026-10-25',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '10']],
        ...$extra,
    ]);
    $this->latest = fn () => SalesOrder::query()->latest('id')->first();
});

afterEach(fn () => Carbon::setTestNow());

it('lets the order challan take stock held for another order only with an Owner reason', function () {
    ($this->order)('400');
    $second = ($this->order)('150', $this->staff, $this->other);
    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/approve", ['backorder_reason' => 'Dealer waits for the next lot'])->assertSessionHas('success');
    $line = $second->fresh('lines')->lines->first();
    $challan = fn (array $extra = []) => ['warehouse_id' => $this->warehouse->id, 'delivered_at' => '2026-10-25', 'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '150']], ...$extra];

    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/deliver", $challan())->assertSessionHasErrors(['reserved', 'lines']);
    $this->actingAs($this->staff)->post("/sales/orders/{$second->id}/deliver", $challan(['take_reserved_reason' => 'Customer is waiting']))->assertSessionHasErrors('reserved');

    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/deliver", $challan(['take_reserved_reason' => 'Customer is waiting']))->assertSessionHasNoErrors();

    expect(StockReservationOverride::query()->where('kind', 'take_reserved')->sole()->reason)->toBe('Customer is waiting');
});

it('keeps the channel and the shop reference, and a phone order waits for its call while a walk-in does not', function () {
    ($this->post)(['channel' => 'phone', 'external_reference' => 'LD-1024'])->assertSessionHasNoErrors();
    $phone = ($this->latest)();

    expect($phone->channel)->toBe(SalesChannel::Phone)
        ->and($phone->external_reference)->toBe('LD-1024')
        ->and($phone->confirmation_status)->toBe(OrderConfirmation::Waiting);

    ($this->post)(['channel' => 'walk_in'])->assertSessionHasNoErrors();
    $walkIn = ($this->latest)();

    expect($walkIn->confirmation_status)->toBe(OrderConfirmation::Confirmed)
        ->and($walkIn->confirmed_by)->toBe($this->owner->id);

    $this->actingAs($this->owner)->get('/sales/orders?queue=confirm')->assertInertia(fn (Assert $page) => $page
        ->where('queues.confirm', 1)
        ->where('orders.data.0.id', $phone->id)
        ->where('orders.data.0.channel_label', 'Phone call')
        ->has('order_options.channels', 6));
});

it('asks which shop a website order came from, and warns on a repeated shop number until told to save anyway', function () {
    ($this->post)(['channel' => 'website'])->assertSessionHasErrors('channel_site');
    ($this->post)(['channel' => 'website', 'channel_site' => 'laladia.com', 'external_reference' => '1024'])->assertSessionHasNoErrors();

    ($this->post)(['channel' => 'website', 'channel_site' => 'bionic.garden', 'external_reference' => '1024'])
        ->assertSessionHasErrors('external_reference');
    expect(session('errors')->first('external_reference'))->toContain('already carries reference 1024');

    $this->actingAs($this->owner)->getJson('/sales/orders/channel-check?reference=1024')->assertJsonPath('duplicate', fn ($s) => is_string($s) && str_contains($s, 'Save anyway'));

    ($this->post)(['channel' => 'website', 'channel_site' => 'bionic.garden', 'external_reference' => '1024', 'allow_duplicate_reference' => true])->assertSessionHasNoErrors();

    expect(SalesOrder::query()->where('external_reference', '1024')->count())->toBe(2)
        ->and(($this->latest)()->channel_site)->toBe('bionic.garden');
});

it('counts each call: no answer with its time, a stale second click refused, a customer cancel with a reason, then confirmed', function () {
    ($this->post)(['channel' => 'whatsapp'], $this->staff)->assertSessionHasNoErrors();
    $order = ($this->latest)();
    $call = fn (string $outcome, int $seen, ?string $note = null, ?User $by = null) => $this->actingAs($by ?? $this->staff)
        ->post("/sales/orders/{$order->id}/confirmation", array_filter(['outcome' => $outcome, 'tries_seen' => $seen, 'note' => $note], fn ($v) => $v !== null));

    $call('no_answer', 0)->assertSessionHasNoErrors();
    $call('no_answer', 0)->assertSessionHasErrors('outcome');
    $call('customer_cancelled', 1)->assertSessionHasErrors('note');

    expect($order->fresh()->confirmation_attempts)->toBe(1)
        ->and($order->fresh()->confirmation_status)->toBe(OrderConfirmation::NoAnswer)
        ->and($order->fresh()->confirmation_last_try_at->toDateTimeString())->toBe('2026-10-25 10:00:00');

    $call('confirmed', 1)->assertSessionHasNoErrors();

    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmation::Confirmed)
        ->and($order->fresh()->confirmation_attempts)->toBe(2)
        ->and($order->fresh()->confirmed_by)->toBe($this->staff->id);

    $this->actingAs($this->owner)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
        ->where('channel_info.confirmation_label', 'Called, confirmed')
        ->where('channel_info.confirmation_attempts', 2)
        ->where('channel_info.channel_label', 'WhatsApp'));
});

it('gives a gift line at zero, and on the invoice its cost goes to Customer Gift, not to cost of goods sold', function () {
    ($this->post)(['channel' => 'facebook', 'lines' => [
        ['product_id' => $this->product->id, 'quantity' => '2'],
        ['product_id' => $this->gift->id, 'quantity' => '1', 'is_gift' => true, 'unit_price' => '300'],
    ]])->assertSessionHasNoErrors();
    $order = ($this->latest)()->load('lines');
    $giftLine = $order->lines->firstWhere('product_id', $this->gift->id);

    expect($giftLine->is_gift)->toBeTrue()
        ->and((float) $giftLine->unit_price)->toBe(0.0)
        ->and($order->agreedTotal())->toBe('600.00');

    $this->actingAs($this->owner)->post("/sales/orders/{$order->id}/deliver", [
        'warehouse_id' => $this->warehouse->id, 'delivered_at' => '2026-10-25',
        'lines' => $order->lines->map(fn ($l) => ['sales_order_line_id' => $l->id, 'quantity' => $l->quantity_ordered])->all(),
    ])->assertSessionHasNoErrors();

    $billable = $this->actingAs($this->owner)->get('/sales/invoices/create?customer_id='.$this->customer->id)->original->getData()['page']['props']['billable'];
    $this->actingAs($this->owner)->post('/sales/invoices', [
        'customer_id' => $this->customer->id,
        'lines' => collect($billable)->map(fn ($b) => ['delivery_line_id' => $b['delivery_line_id'], 'quantity' => $b['quantity'] ?? $b['quantity_uninvoiced'] ?? '0'])->all(),
    ])->assertSessionHasNoErrors();

    $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
    $sum = fn (string $code) => (int) JournalLine::query()->where('account_id', Account::query()->where('code', $code)->value('id'))->sum('amount_minor');
    $cogs = (int) JournalLine::query()->where('account_id', Account::bySlug('cost_of_goods_sold')->id)->sum('amount_minor');

    expect((string) $invoice->total)->toContain('600')
        ->and(abs($sum('6331')))->toBe(4000)
        ->and(abs($cogs))->toBe(40000)
        ->and((int) JournalLine::query()->sum('amount_minor'))->toBe(0);
});

it('holds the order discount to the person\'s cap, on top of the line discounts', function () {
    // Staff: 2% of Tk 3,000 is Tk 60.
    ($this->post)(['channel' => 'phone', 'order_discount' => '100'], $this->staff)->assertSessionHasErrors('order_discount');
    ($this->post)(['channel' => 'phone', 'order_discount' => '50'], $this->staff)->assertSessionHasNoErrors();
    expect(($this->latest)()->agreedTotal())->toBe('2950.00');

    ($this->post)(['channel' => 'phone', 'order_discount' => '500'])->assertSessionHasNoErrors();
    expect(($this->latest)()->agreedTotal())->toBe('2500.00');
});

it('takes the courier charge from the zone, fixed for staff, changed only by a Manager or the Owner', function () {
    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier', 'address' => $this->address], $this->staff)->assertSessionHasNoErrors();
    $order = ($this->latest)();
    expect((string) $order->delivery_charge)->toBe('60.00')->and($order->delivery_zone)->toBe('Inside Dhaka');

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier', 'delivery_charge' => '40', 'address' => $this->address], $this->staff)
        ->assertSessionHasErrors('delivery_charge');
    expect(session('errors')->first('delivery_charge'))->toContain('Only a Manager or the Owner');

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier', 'delivery_charge' => '40', 'address' => $this->address], $this->manager)->assertSessionHasNoErrors();
    expect((string) ($this->latest)()->delivery_charge)->toBe('40.00')->and(($this->latest)()->delivery_charge_set_by)->toBe($this->manager->id);

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier', 'address' => [...$this->address, 'district' => 'Bagerhat', 'thana' => 'Mongla']], $this->staff)->assertSessionHasNoErrors();
    expect((string) ($this->latest)()->delivery_charge)->toBe('120.00');

    $this->actingAs($this->staff)->getJson('/sales/orders/channel-check?district=Gazipur')->assertJsonPath('courier_charge', '90.00');

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier'], $this->staff)->assertSessionHasErrors('address.district');
});

it('types the charge for a rider or our own person, and asks nothing for a hand delivery', function () {
    $cash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Office cash', 'type' => MoneyAccountType::Cash, 'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true]);

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'rider', 'delivery_charge' => '150', 'address' => $this->address], $this->staff)->assertSessionHasNoErrors();
    expect((string) ($this->latest)()->delivery_charge)->toBe('150.00')->and(($this->latest)()->delivery_method)->toBe(DeliveryMethod::Rider);

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'own_staff', 'delivery_charge' => '0', 'cod_money_account_id' => $cash->id, 'address' => $this->address], $this->staff)->assertSessionHasNoErrors();
    expect(($this->latest)()->delivery_charge)->toBeNull()->and(($this->latest)()->cod_money_account_id)->toBe($cash->id);

    ($this->post)(['channel' => 'walk_in', 'delivery_method' => 'hand', 'delivery_charge' => '99', 'address' => $this->address], $this->staff)->assertSessionHasNoErrors();
    expect(($this->latest)()->delivery_charge)->toBeNull()->and(($this->latest)()->address)->toBeNull();
});

it('sends what our own person collects into his own Employee cash account unless another is chosen', function () {
    // ⭐ IP1 meets IP16 (integration, 25 Oct 2026).
    $rakib = Employee::create([
        'business_id' => $this->business->id, 'employee_code' => 'BDG-000071', 'first_name' => 'Rakib', 'last_name' => 'Hasan',
        'employment_type' => 'permanent', 'status' => 'active',
    ]);
    $petty = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Petty Cash - Rakib', 'type' => MoneyAccountType::EmployeeCash, 'custodian_employee_id' => $rakib->id]);
    MoneyLedgers::ensureOwnLedger($petty);

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'own_staff', 'delivery_charge' => '0', 'rider_employee_id' => $rakib->id, 'address' => $this->address], $this->staff)->assertSessionHasNoErrors();
    $order = ($this->latest)();
    expect($order->cod_money_account_id)->toBe($petty->id);

    $this->actingAs($this->owner)->get(route('sales.orders.show', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('channel_info.cod_account_id', $petty->id));

    $this->actingAs($this->owner)->get(route('sales.orders.index'))
        ->assertInertia(fn ($page) => $page->where('order_options.cash_accounts', fn ($accounts) => collect($accounts)->contains(fn ($a) => $a['id'] === $petty->id && $a['holder_employee_id'] === $rakib->id)));
});

it('dates a staff order today only; a Manager or the Owner any day of an open month', function () {
    ($this->post)(['ordered_at' => '2026-10-20'], $this->staff)->assertSessionHasErrors('ordered_at');
    expect(session('errors')->first('ordered_at'))->toContain('today');
    ($this->post)(['ordered_at' => '2026-10-20'], $this->manager)->assertSessionHasNoErrors();
    expect(($this->latest)()->ordered_at->toDateString())->toBe('2026-10-20');

    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->owner);
    ($this->post)(['ordered_at' => '2026-09-20'], $this->owner)->assertSessionHasErrors('ordered_at');
    expect(session('errors')->first('ordered_at'))->toContain('closed month');
});

it('marks a paid-online order paid into the bank it settles into, never into the gateway account', function () {
    $bank = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'City Bank', 'type' => MoneyAccountType::Bank, 'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true]);
    $gateway = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway, 'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true]);

    ($this->post)(['channel' => 'website', 'channel_site' => 'laladia.com', 'payment' => 'ssl_paid', 'paid_money_account_id' => $gateway->id])->assertSessionHasErrors('paid_money_account_id');
    expect(SalesOrder::query()->count())->toBe(0);

    ($this->post)(['channel' => 'website', 'channel_site' => 'laladia.com', 'payment' => 'ssl_paid', 'paid_money_account_id' => $bank->id, 'paid_reference' => 'SSL-77'])->assertSessionHasNoErrors();
    $order = ($this->latest)();

    expect($order->payment_method)->toBe('ssl_paid')
        ->and($order->stillToPay()->toTaka())->toBe('0.00')
        ->and(CustomerReceipt::query()->sole()->money_account_id)->toBe($bank->id);
});

it('says where each order stands: waiting, reserved, packed, cancelled', function () {
    ($this->post)(['channel' => 'phone'], $this->staff);
    $waiting = ($this->latest)();
    ($this->post)(['channel' => 'phone']);
    $reserved = ($this->latest)();

    $this->actingAs($this->owner)->get("/sales/orders/{$waiting->id}")->assertInertia(fn (Assert $page) => $page->where('stage', 'waiting_confirmation'));
    $this->actingAs($this->owner)->get("/sales/orders/{$reserved->id}")->assertInertia(fn (Assert $page) => $page->where('stage', 'reserved'));

    $this->actingAs($this->owner)->post("/sales/orders/{$reserved->id}/deliver", [
        'warehouse_id' => $this->warehouse->id, 'delivered_at' => '2026-10-25',
        'lines' => [['sales_order_line_id' => $reserved->lines()->first()->id, 'quantity' => '4']],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get("/sales/orders/{$reserved->id}")->assertInertia(fn (Assert $page) => $page->where('stage', 'packed'));
});
