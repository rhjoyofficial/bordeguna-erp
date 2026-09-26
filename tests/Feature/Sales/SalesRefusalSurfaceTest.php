<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * C-SLS-E1 and C-SLS-E2: what a Sales refusal looks like from the browser.
 *
 * ⚠⚠ Every assertion here is about the SURFACE, never the decision. Each of
 * these writes was already refused correctly; the engine tests prove that. What
 * was missing is that the refusal reached the user as a bare 500 with no text on
 * it, because Sales is the one module that never caught its own domain
 * exceptions the way Manufacturing, Assets, Accounting and Purchasing all do.
 *
 * ⚠ `assertStatus(500)` is what these asserted BEFORE the fix. A 302 back with
 * the exception's own message is what they assert now. Nothing about which
 * writes succeed changed.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Refusal Co', 'slug' => 'refusal-co']);
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

    $this->warehouse = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN',
    ]);
    $unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);

    // Two products on purpose: one the price file reached, one it did not.
    // The unpriced one is the realistic 1 September case.
    $this->priced = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-PRICED',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->unpriced = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-UNPRICED',
        'name' => 'Mustard Oil 1L',
        'type' => ProductType::FinishedGood,
    ]);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Till',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->priced->id,
        'unit_price' => '850',
        'effective_from' => '2026-01-01',
    ]);

    Carbon::setTestNow('2026-09-01 10:00:00');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        // Cash at the counter needs the seller's own open shift (26 Sep 2026).
        if (isset($this->cash)) {
            openTill($user, $this->cash);
        }

        return $this->actingAs($user);
    };

    $this->stocker = User::factory()->create();
    app(RecordStockIn::class)->handle(
        $this->priced, $this->warehouse, '20', '500',
        $this->stocker, occurredAt: '2026-08-01 09:00:00'
    );
    app(RecordStockIn::class)->handle(
        $this->unpriced, $this->warehouse, '20', '400',
        $this->stocker, occurredAt: '2026-08-01 09:00:00'
    );
});

afterEach(fn () => Carbon::setTestNow());

// ▶▶ THE GO-LIVE CASE. `product_prices` is empty on production today; the price
// file writes 216 rules and the catalogue holds 54 active finished goods. A
// product outside those rules refuses its first sale, and this is the screen it
// refuses on.
it('tells the seller which product has no price instead of failing blank', function () {
    $response = ($this->asRole)('Staff')->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->unpriced->id, 'quantity' => '1']],
    ]);

    $response->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->toContain('Mustard Oil 1L')
        ->and(SalesInvoice::count())->toBe(0);
});

it('tells the seller the stock is short instead of failing blank', function () {
    $response = ($this->asRole)('Staff')->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->priced->id, 'quantity' => '999']],
    ]);

    $response->assertSessionHasErrors('lines');

    expect(SalesInvoice::count())->toBe(0);
});

it('tells the seller the month is closed instead of failing blank', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-09-30'), $this->stocker);

    $response = ($this->asRole)('Staff')->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->priced->id, 'quantity' => '1']],
    ]);

    $response->assertSessionHasErrors();

    expect(SalesInvoice::count())->toBe(0);
});

it('refuses an unpriced product on a sales order with a readable message', function () {
    $response = ($this->asRole)('Manager')->post('/sales/orders', [
        'customer_id' => Customer::walkIn()->id,
        'lines' => [['product_id' => $this->unpriced->id, 'quantity' => '5']],
    ]);

    $response->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->toContain('Mustard Oil 1L');
});

it('refuses a direct delivery that is short of stock with a readable message', function () {
    $response = ($this->asRole)('Staff')->post('/sales/deliveries', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['product_id' => $this->priced->id, 'quantity' => '999']],
    ]);

    $response->assertSessionHasErrors('lines');
});

it('refuses an invoice dated into a closed month with a readable message', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-08-31'), $this->stocker);

    $response = ($this->asRole)('Manager')->post('/sales/invoices', [
        'customer_id' => Customer::walkIn()->id,
        'invoice_date' => '2026-08-15',
        'lines' => [['description' => 'Carriage', 'quantity' => '1', 'unit_price' => '500']],
    ]);

    $response->assertSessionHasErrors('invoice_date');

    expect(SalesInvoice::count())->toBe(0);
});

// ⚠⚠ C-SLS-E2. A line billing no delivery never reaches the resolver, so before
// this it fell through `$unitPrice ?? '0'` and billed the customer NOTHING while
// the books balanced perfectly around it. A service line releases no cost, so
// there is no COGS to make it look wrong. Silent, and it reconciles.
it('refuses a service line that carries no price at all', function () {
    $response = ($this->asRole)('Manager')->post('/sales/invoices', [
        'customer_id' => Customer::walkIn()->id,
        'lines' => [['description' => 'Delivery charge', 'quantity' => '1']],
    ]);

    $response->assertSessionHasErrors('lines.0.unit_price');

    expect(SalesInvoice::count())->toBe(0);
});

// ⚠ The refusal is on ABSENCE, not on value. A deliberate zero is a real thing a
// business does (a free sample, a charge written off at the counter) and is not
// mine to forbid. Typing 0 still works.
it('still accepts a service line priced at zero on purpose', function () {
    ($this->asRole)('Manager')->post('/sales/invoices', [
        'customer_id' => Customer::walkIn()->id,
        'lines' => [['description' => 'Free sample', 'quantity' => '1', 'unit_price' => '0']],
    ]);

    $invoice = SalesInvoice::first();

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->total)->toBe(0.0);
});
