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
 * Module 3, Slice 4 (POS spec §19): the thermal slip.
 *
 * The paper a counter customer walks away with: 80mm, the shop name, the
 * lines, what was paid and how, who sold it, and a barcode of the invoice
 * number - the same number the returns screen accepts, so a scan closes the
 * loop. Reprint is just visiting the page again; the slip never computes a
 * number itself.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Slip Co', 'slug' => 'slip-co']);
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

    $this->sale = app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '2']],
        createdBy: $this->owner,
        moneyAccount: $this->cash,
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

it('hands the slip everything the paper needs: lines, payment, seller, barcode', function () {
    ($this->asOwner)()->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/invoices/slip')
            ->where('invoice.invoice_number', $this->sale->invoice->invoice_number)
            ->where('invoice.lines.0.product.name', 'Sundarban Honey 500g')
            ->where('soldBy', $this->owner->name)
            // Paid in full through the till, and the slip says so.
            ->where('payments.0.account', 'Till')
            ->where('outstanding', fn ($value) => (float) $value === 0.0)
            // The barcode carries the invoice number the returns screen accepts.
            ->where('barcodeSvg', fn ($value) => is_string($value) && str_contains($value, '<svg'))
            ->has('issuer.name'));
});

it('keeps the slip off limits without the reading permission', function () {
    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);

    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertForbidden();
});
