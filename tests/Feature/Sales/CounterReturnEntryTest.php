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
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 3, Slice 3: the counter return ENTRY. The return engine, its caps and
 * the one-transaction cash refund already exist - what a till lacks is the
 * way in. A customer stands there holding a receipt with an INVOICE number;
 * the returns picker speaks only in challan numbers. This slice teaches the
 * existing screens the number the customer is actually holding. No new
 * documents, no parallel path.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Counter Return Co', 'slug' => 'counter-return-co']);
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

    Carbon::setTestNow('2026-08-24 10:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SH']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '500', $this->owner, occurredAt: '2026-08-01 09:00:00');
    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $this->product->id,
        'unit_price' => '850', 'effective_from' => '2026-01-01',
    ]);

    // A counter sale: one invoice, one delivery, both born together.
    $this->sale = app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '3']],
        createdBy: $this->owner,
        moneyAccount: $this->cash,
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

it('finds the delivery from the invoice number the customer is holding', function () {
    ($this->asOwner)()->get(route('sales.returns.create', ['invoice_number' => $this->sale->invoice->invoice_number]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/returns/create')
            ->where('delivery.id', $this->sale->delivery->id)
            ->where('lines.0.quantity_returnable', fn ($value) => (float) $value === 3.0));
});

it('says plainly when no sale carries that number, instead of a blank screen', function () {
    ($this->asOwner)()->get(route('sales.returns.create', ['invoice_number' => 'INV-NOPE']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/returns/create')
            ->where('delivery', null)
            ->where('invoiceLookupError', fn ($value) => is_string($value) && $value !== ''));
});

it('hands the invoice page the delivery to return against', function () {
    ($this->asOwner)()->get(route('sales.invoices.show', $this->sale->invoice->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/invoices/show')
            ->where('returnDeliveryId', $this->sale->delivery->id));
});
