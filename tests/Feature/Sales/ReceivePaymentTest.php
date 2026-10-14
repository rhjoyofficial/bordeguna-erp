<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Receive payment and the Receipts list (21 Sep 2026): money in from the
 * customer's side, over several invoices at once, oldest first, and a list of
 * every receipt, which did not exist.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Receipt Co', 'slug' => 'receipt-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders']);

    foreach (['sales.receipt.record', 'sales.reports.view'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role = Role::firstOrCreate(['name' => 'Cashier', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo(['sales.receipt.record', 'sales.reports.view']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole($role);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Main Cash', 'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    Carbon::setTestNow('2026-08-10 10:00:00');
    app(RecordStockIn::class)->handle($product, $warehouse, '100', '500', $this->user, occurredAt: '2026-08-01 09:00:00');

    $this->invoiceFor = function (string $qty, string $price, string $date) use ($warehouse, $product) {
        $delivery = app(RecordDelivery::class)->handle(
            customer: $this->customer, warehouse: $warehouse,
            lines: [['product' => $product, 'quantity' => $qty]],
            createdBy: $this->user, deliveredAt: $date.' 09:00:00',
        );

        return app(RecordSalesInvoice::class)->handle(
            customer: $this->customer,
            lines: [['deliveryLine' => $delivery->lines->first(), 'quantity' => $qty, 'unitPrice' => $price]],
            createdBy: $this->user, invoiceDate: $date,
        );
    };

    $this->older = ($this->invoiceFor)('10', '850', '2026-08-02');   // 8,500
    $this->newer = ($this->invoiceFor)('4', '850', '2026-08-05');    // 3,400

    $this->post = fn (array $allocations, array $extra = []) => $this->actingAs($this->user)
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('sales.receipts.store'), array_merge([
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->cash->id,
            'received_on' => '2026-08-10',
            'reference' => 'bKash 8891',
            'allocations' => $allocations,
        ], $extra));
});

afterEach(fn () => Carbon::setTestNow());

it('shows the unpaid invoices, oldest first, to spread a payment over', function () {
    $this->actingAs($this->user)->get(route('sales.receipts.create', ['customer_id' => $this->customer->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/receipts/create')
            ->where('invoices.0.id', $this->older->id)
            ->where('invoices.0.outstanding', '8500.00')
            ->where('invoices.1.id', $this->newer->id)
            ->has('moneyAccounts', 1));
});

it('takes one payment against several invoices, in one receipt and one posting', function () {
    ($this->post)([
        ['invoice_id' => $this->older->id, 'amount' => '8500'],
        ['invoice_id' => $this->newer->id, 'amount' => '1500'],
    ])->assertRedirect(route('sales.receipts.index'))->assertSessionHasNoErrors();

    $receipt = CustomerReceipt::sole();

    expect((float) $receipt->amount)->toBe(10000.0)
        ->and($receipt->allocations)->toHaveCount(2)
        ->and($this->older->fresh()->outstanding()->toTaka())->toBe('0.00')
        ->and($this->newer->fresh()->outstanding()->toTaka())->toBe('1900.00');
});

it('refuses more than an invoice still owes, under that invoice', function () {
    ($this->post)([['invoice_id' => $this->newer->id, 'amount' => '5000']])
        ->assertSessionHasErrors('allocations.0.amount');

    expect(CustomerReceipt::count())->toBe(0);
});

it('refuses an invoice of another customer', function () {
    $other = Customer::create(['business_id' => $this->business->id, 'name' => 'Somebody Else']);

    ($this->post)([['invoice_id' => $this->older->id, 'amount' => '100']], ['customer_id' => $other->id])
        ->assertSessionHasErrors();

    expect(CustomerReceipt::count())->toBe(0);
});

it('lists every receipt with the invoices it paid, and totals what is shown', function () {
    ($this->post)([['invoice_id' => $this->older->id, 'amount' => '8500']]);

    $this->actingAs($this->user)->get(route('sales.receipts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/receipts/index')
            ->where('receipts.data.0.customer', 'Karim Traders')
            ->where('receipts.data.0.invoices.0.id', $this->older->id)
            ->where('receipts.data.0.received_on', '10 Aug 2026')
            ->where('total', '8,500.00'));

    $this->actingAs($this->user)->get(route('sales.receipts.index', ['from' => '2026-08-11']))
        ->assertInertia(fn (Assert $page) => $page->has('receipts.data', 0)->where('total', '0.00'));
});

it('keeps Receive payment from somebody who may not take money', function () {
    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);

    $this->actingAs($clerk)->get(route('sales.receipts.create'))->assertForbidden();
});

// ⚠ SAL-009 (audit 14 Oct 2026): Receive payment loaded every invoice the
// customer ever had and ran SUM queries per invoice. Settled ones now cost
// nothing and the open ones are answered in the same query.
it('opens Receive payment in the same number of queries however many settled invoices the customer has (SAL-009)', function () {
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('sales.receipts.create', ['customer_id' => $this->customer->id]))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // Each count is taken on a second request: the first warms the
    // permission and settings caches.
    $queries();
    $before = $queries();

    foreach (range(1, 5) as $day) {
        $settled = ($this->invoiceFor)('1', '850', '2026-08-0'.$day);
        ($this->post)([['invoice_id' => $settled->id, 'amount' => '850']])->assertSessionHasNoErrors();
    }

    $queries();

    expect($queries())->toBe($before);

    $this->actingAs($this->user)->get(route('sales.receipts.create', ['customer_id' => $this->customer->id]))
        ->assertInertia(fn (Assert $page) => $page->has('invoices', 2)->where('invoices.1.outstanding', '3400.00'));
});
