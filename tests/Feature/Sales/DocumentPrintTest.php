<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Actions\StoreAttachment;
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
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The two documents that leave the building.
 *
 * ⚠⚠ Until this slice the system could invoice and deliver and had NO WAY to
 * put either on paper. The ledger was right and the customer got nothing.
 */
beforeEach(function () {
    $this->business = Business::create([
        'name' => 'Print Co',
        'slug' => 'print-co',
        'legal_name' => 'Print Company Limited',
        'address' => "12 Adabor\nDhaka 1207",
        'phone' => '01711000000',
        'email' => 'hello@printco.test',
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
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
        'code' => 'CUS-0001',
        'name' => 'Karim Traders',
        'type' => CustomerType::Distributor,
        'address' => 'Mirpur 10, Dhaka',
        'phone' => '01722000000',
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    Carbon::setTestNow('2026-09-01 10:00:00');

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '20', '250', $this->owner, occurredAt: '2026-08-30 09:00:00');

    $this->delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '3']],
        createdBy: $this->owner,
    );

    $this->invoice = app(RecordSalesInvoice::class)->handle(
        customer: $this->customer,
        lines: [[
            'deliveryLine' => $this->delivery->lines()->first(),
            'quantity' => '3',
            'unitPrice' => '690',
        ]],
        createdBy: $this->owner,
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('prints an invoice the customer can be handed', function () {
    ($this->asOwner)()->get(route('sales.invoices.print', $this->invoice))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/invoices/print')
            ->where('invoice.invoice_number', $this->invoice->invoice_number)
            ->where('invoice.total', fn ($total) => (float) $total === 2070.0)
            ->where('issuer.legal_name', 'Print Company Limited')
            ->where('issuer.phone', '01711000000')
            ->where('issuer.missing', [])
        );
});

it('prints a challan for the goods that left', function () {
    ($this->asOwner)()->get(route('sales.deliveries.print', $this->delivery))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/deliveries/print')
            ->where('delivery.delivery_number', $this->delivery->delivery_number)
            ->where('delivery.customer.name', 'Karim Traders')
            ->where('delivery.warehouse.name', 'Savar')
        );
});

it('keeps money off the challan entirely', function () {
    // ⚠⚠ A challan states what left the warehouse. If a price ever reaches this
    // page the recipient will read it as a bill, and they will be reading a
    // number nobody agreed to bill them.
    $props = ($this->asOwner)()->get(route('sales.deliveries.print', $this->delivery))
        ->viewData('page')['props'];

    $serialised = json_encode($props['delivery']);

    expect($serialised)->not->toContain('unit_price')
        ->and($serialised)->not->toContain('690');
});

it('never invents a company detail the business has not saved', function () {
    // A plausible address printed on a customer's paper is worse than a gap.
    $this->business->update(['address' => null, 'phone' => null, 'email' => null]);

    ($this->asOwner)()->get(route('sales.invoices.print', $this->invoice))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('issuer.address', null)
            ->where('issuer.phone', null)
            ->where('issuer.missing', ['address', 'phone', 'email'])
        );
});

it('will not print a document belonging to another business', function () {
    $other = Business::create(['name' => 'Rival', 'slug' => 'rival-print']);

    // ⚠ The real test, not a missing id: these documents EXIST and belong to
    // somebody else. Moving them is the cheapest way to say that, and it
    // exercises the tenant scope on route-model binding rather than the
    // ordinary not-found path, which would pass with no scoping at all.
    DB::table('sales_invoices')->where('id', $this->invoice->id)->update(['business_id' => $other->id]);
    DB::table('deliveries')->where('id', $this->delivery->id)->update(['business_id' => $other->id]);

    ($this->asOwner)()->get("/sales/invoices/{$this->invoice->id}/print")->assertNotFound();
    ($this->asOwner)()->get("/sales/deliveries/{$this->delivery->id}/print")->assertNotFound();
});

it('opens both documents to anyone who may already read the sale', function () {
    // ⚠ Staff DOES hold sales.reports.view, and the print pages sit behind
    // exactly that: a document is not more secret than the record it prints.
    // Asserted rather than assumed - the first draft of this test guessed the
    // other way round.
    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $staff->assignRole('Staff');

    $asStaff = fn () => $this->actingAs($staff)->withSession(['current_business_id' => $this->business->id]);

    $asStaff()->get(route('sales.invoices.print', $this->invoice))->assertOk();
    $asStaff()->get(route('sales.deliveries.print', $this->delivery))->assertOk();
});

it('shuts both documents to somebody with no sales role at all', function () {
    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);

    $asOutsider = fn () => $this->actingAs($outsider)->withSession(['current_business_id' => $this->business->id]);

    $asOutsider()->get(route('sales.invoices.print', $this->invoice))->assertForbidden();
    $asOutsider()->get(route('sales.deliveries.print', $this->delivery))->assertForbidden();
});

it('prints a money receipt from the payment, not from one of its invoices', function () {
    // ⚠⚠ One payment can settle several bills. Printed through an invoice, the
    // paper would name that bill and state an amount covering all of them.
    $account = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Cash box',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    $receipt = app(RecordCustomerReceipt::class)->handle(
        customer: $this->customer,
        moneyAccount: $account,
        receivedOn: '2026-09-01',
        allocations: [['invoice' => $this->invoice, 'amount' => '2070']],
        createdBy: $this->owner,
        reference: 'bKash 4471',
    );

    ($this->asOwner)()->get(route('sales.receipts.print', $receipt))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/receipts/print')
            ->where('receipt.amount', fn ($amount) => (float) $amount === 2070.0)
            ->where('receipt.reference', 'bKash 4471')
            ->where('receipt.money_account.name', 'Cash box')
            ->where('receipt.allocations.0.invoice.invoice_number', $this->invoice->invoice_number)
        );
});

it('puts the uploaded logo on the documents, which is what it was for', function () {
    // ⚠⚠ The logo could be uploaded from the day Settings shipped and appeared
    // NOWHERE except the settings screen that uploaded it.
    Storage::fake('local');

    app(StoreAttachment::class)->handle(
        attachable: $this->business,
        file: UploadedFile::fake()->image('logo.png', 200, 80),
        collection: 'logo',
        uploadedBy: $this->owner,
        singleton: true,
    );

    ($this->asOwner)()->get(route('sales.invoices.print', $this->invoice))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('issuer.logo_url', fn ($url) => $url !== null));
});

it('prints documents with no logo at all when none was uploaded', function () {
    ($this->asOwner)()->get(route('sales.deliveries.print', $this->delivery))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('issuer.logo_url', null));
});
