<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Models\PrinterProfile;
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
 * The paper a counter customer walks away with: the shop name, the lines, what
 * was paid and how, who sold it, and a barcode of the invoice number - the same
 * number the returns screen accepts, so a scan closes the loop. Reprint is just
 * visiting the page again; the slip never computes a number itself.
 *
 * ⭐ REWRITTEN 8 SEP 2026 when the slip moved onto the printer standard. It no
 * longer receives an invoice model, it receives a PrintableDocument the server
 * has already formatted and a ThermalProfile describing the paper. These tests
 * follow that contract, which is why they assert on formatted strings: a figure
 * the paper has to interpret is the defect the change was making impossible.
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

it('hands the slip a finished document: lines, payment, seller, barcode', function () {
    ($this->asOwner)()->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/invoices/slip')
            ->where('document.number', $this->sale->invoice->invoice_number)
            ->where('document.lines.0.description', 'Sundarban Honey 500g')
            // ⚠ Formatted by the server, not by the paper: "2", never "2.0000".
            ->where('document.lines.0.quantity', '2')
            ->where('document.lines.0.unit_price', '850.00')
            ->where('document.meta.1.label', 'Sold by')
            ->where('document.meta.1.value', $this->owner->name)
            ->where('document.payments.0.label', 'Paid (Till)')
            // Paid in full through the till, so no DUE line is put on the paper.
            ->where('document.totals', fn ($totals) => collect($totals)->doesntContain(fn ($row) => $row['label'] === 'DUE'))
            ->where('barcode.value', $this->sale->invoice->invoice_number)
            ->where('barcode.fits', true)
            ->where('barcode.svg', fn ($value) => is_string($value) && str_contains($value, '<svg'))
            ->has('issuer.name')
            ->has('profile.print_width_mm'));
});

it('does not print the date as something the paper has to interpret', function () {
    // ⚠⚠ The invoice document shipped a Carbon serialised by Inertia as a UTC
    // ISO string, which printed the wrong DAY for a Dhaka business. Nothing on
    // a document may arrive needing to be parsed.
    ($this->asOwner)()->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('document.meta.0.label', 'Date')
            ->where('document.meta.0.value', '24 Aug 2026'));
});

it('leaves a walk-in customer off the paper', function () {
    // The row exists so the books balance. Printing "Walk-in customer" on a
    // receipt tells the person holding it nothing.
    ($this->asOwner)()->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertInertia(fn (Assert $page) => $page->where('document.party', null));
});

it('falls back to the built-in paper when no printer has been described', function () {
    expect(PrinterProfile::count())->toBe(0);

    ($this->asOwner)()->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.source', 'builtin')
            ->where('profile.paper_width_mm', 80)
            ->where('profile.print_width_mm', 72));
});

it('prefers the outlet profile over the business default', function () {
    PrinterProfile::create([
        'business_id' => $this->business->id, 'warehouse_id' => null,
        'name' => 'Head office', 'paper_width_mm' => 80, 'print_width_mm' => 72, 'dpi' => 203,
    ]);
    PrinterProfile::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->warehouse->id,
        'name' => 'Shop counter', 'paper_width_mm' => 58, 'print_width_mm' => 48, 'dpi' => 203,
    ]);

    // ⭐ The profile is found through the DELIVERY, because an invoice has no
    // warehouse of its own. This is the assertion that proves that lookup works.
    ($this->asOwner)()->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.source', 'outlet')
            ->where('profile.name', 'Shop counter')
            ->where('profile.print_width_mm', 48));
});

it('leaves the barcode off a roll too narrow to print it honestly', function () {
    // ⛔ A 16-character invoice number cannot be printed on 48mm at a bar width
    // the head can lay down. Squeezing it produces a symbol that scans from some
    // angles and not others, which is worse than none because the counter trusts
    // it. The number still goes on the paper as text.
    PrinterProfile::create([
        'business_id' => $this->business->id, 'warehouse_id' => $this->warehouse->id,
        'name' => 'Pocket printer', 'paper_width_mm' => 58, 'print_width_mm' => 48, 'dpi' => 203,
    ]);

    ($this->asOwner)()->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('barcode.fits', false)
            ->where('barcode.svg', null)
            ->where('barcode.value', $this->sale->invoice->invoice_number)
            ->where('barcode.reason', fn ($reason) => is_string($reason) && str_contains($reason, '48mm')));
});

it('puts the money receipt on the till roll too, without inventing a barcode', function () {
    // ⛔ The invoice slip carries a barcode because the returns screen accepts
    // that exact string. NOTHING in this ERP scans a receipt, so printing one
    // here would be decoration taking up a third of the paper on a financial
    // document.
    $receipt = $this->sale->invoice->allocations->first()->receipt;

    ($this->asOwner)()->get(route('sales.receipts.slip', $receipt->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/receipts/slip')
            ->where('document.title', 'Money receipt')
            ->where('barcode', null)
            ->where('document.barcode_value', null)
            // ⚠ Every settled invoice is a LINE. A receipt can cover several
            // bills, and one naming a single invoice while stating an amount
            // covering three is the defect the A4 version was written to avoid.
            ->where('document.lines.0.description', 'Against '.$this->sale->invoice->invoice_number)
            ->where('document.totals.0.label', 'RECEIVED'));
});

it('formats the receipt date on the server rather than slicing a UTC string', function () {
    // ⚠⚠ The page was taking the first ten characters of what Inertia
    // serialised, which is a UTC ISO string - so a receipt taken after 6am in
    // Dhaka printed the previous day. Same defect family as the invoice date.
    $receipt = $this->sale->invoice->allocations->first()->receipt;

    ($this->asOwner)()->get(route('sales.receipts.print', $receipt->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('receivedOn', '24 Aug 2026'));
});

it('keeps the slip off limits without the reading permission', function () {
    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);

    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('sales.invoices.slip', $this->sale->invoice->id))
        ->assertForbidden();
});
