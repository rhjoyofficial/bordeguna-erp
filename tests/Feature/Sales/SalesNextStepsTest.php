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
use App\Modules\Sales\Actions\RecordCustomerReceipt;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Small truths on the sales screens (21 Sep 2026): a part-paid invoice says so,
 * "Invoice this challan" starts with only that challan, and accepting a
 * quotation uses the default warehouse, not whichever sorts first.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Steps Co', 'slug' => 'steps-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['sales.sell', 'sales.reports.view', 'sales.orders.record'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Seller', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.sell', 'sales.reports.view', 'sales.orders.record']);
    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Seller');

    $this->aWarehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'A Savar', 'code' => 'SAVAR']);
    $this->main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main Mirpur', 'code' => 'MIRPUR', 'is_default' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders']);

    Carbon::setTestNow('2026-09-10 10:00:00');
    app(RecordStockIn::class)->handle($this->product, $this->main, '100', '500', $this->user, occurredAt: '2026-09-01 09:00:00');

    $this->challan = fn (string $qty) => app(RecordDelivery::class)->handle(
        customer: $this->customer, warehouse: $this->main,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $this->user, deliveredAt: '2026-09-02 09:00:00',
    );
});

afterEach(fn () => Carbon::setTestNow());

it('calls an invoice with some money in part paid, not unpaid', function () {
    $invoice = app(RecordSalesInvoice::class)->handle(
        customer: $this->customer,
        lines: [['deliveryLine' => ($this->challan)('2')->lines->first(), 'quantity' => '2', 'unitPrice' => '850']],
        createdBy: $this->user, invoiceDate: '2026-09-03',
    );
    $cash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Main Cash', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);
    app(RecordCustomerReceipt::class)->handle($this->customer, $cash, '2026-09-04', [['invoice' => $invoice, 'amount' => '500']], $this->user);

    $this->actingAs($this->user)->get(route('sales.invoices.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.is_settled', false)
            ->where('invoices.data.0.is_part_paid', true));
});

it('starts "Invoice this challan" with only that challan included', function () {
    $first = ($this->challan)('2');
    $second = ($this->challan)('3');

    $this->actingAs($this->user)->get(route('sales.invoices.create', ['customer_id' => $this->customer->id, 'delivery_id' => $second->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onlyDeliveryId', $second->id)
            ->where('billable', fn ($lines) => collect($lines)->pluck('delivery_id')->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all()));
});

it('offers the default warehouse first when a quotation is accepted', function () {
    $this->actingAs($this->user)->get(route('sales.quotations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('warehouses.0.id', $this->main->id)
            ->where('warehouses.0.is_default', true));
});
