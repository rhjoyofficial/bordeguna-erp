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
 * The Sales UI and its permission layer.
 *
 * ⚠ The split every module here uses: Staff may serve a customer and take
 * money; deciding what things cost and editing the customer master (where the
 * credit limit lives) stays with Owner and Manager.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Screen Co', 'slug' => 'screen-co']);
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
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
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
        'product_id' => $this->product->id,
        'unit_price' => '850',
        'effective_from' => '2026-01-01',
    ]);

    Carbon::setTestNow('2026-08-04 10:00:00');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    app(RecordStockIn::class)->handle(
        $this->product, $this->warehouse, '50', '500',
        User::factory()->create(), occurredAt: '2026-08-01 09:00:00'
    );
});

afterEach(fn () => Carbon::setTestNow());

it('shows the counter to someone who may sell', function () {
    ($this->asRole)('Staff')->get('/sales/counter')->assertOk();
});

it('sells over the counter and lands on the invoice', function () {
    $response = ($this->asRole)('Staff')->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '3']],
    ]);

    $invoice = SalesInvoice::first();

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->total)->toBe(2550.0);

    $response->assertRedirect(route('sales.invoices.show', $invoice->id));
});

// ⚠ The counter must quote from the SAME resolver the sale uses, or the price
// on the screen and the price on the invoice can disagree.
it('answers a price check from the real resolver', function () {
    ($this->asRole)('Staff')
        ->postJson('/sales/counter/price', [
            'product_id' => $this->product->id,
            'quantity' => '1',
        ])
        ->assertOk()
        ->assertJson(['unit_price' => '850.0000']);
});

it('lets a sale go on account when no money account is chosen', function () {
    ($this->asRole)('Staff')->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ]);

    expect(SalesInvoice::first()->outstanding()->toTaka())->toBe('1700.00');
});

describe('who may do what', function () {
    // ⚠⚠ Setting a price is a margin decision, deliberately not given to Staff.
    it('stops Staff from setting a selling price', function () {
        ($this->asRole)('Staff')->post('/sales/prices', [
            'product_id' => $this->product->id,
            'unit_price' => '1',
            'effective_from' => '2026-08-04',
        ])->assertForbidden();
    });

    it('lets a Manager set a selling price', function () {
        ($this->asRole)('Manager')->post('/sales/prices', [
            'product_id' => $this->product->id,
            'unit_price' => '900',
            'effective_from' => '2026-08-05',
        ])->assertRedirect();

        expect(ProductPrice::count())->toBe(2);
    });

    // ⚠ The customer master carries the credit limit, so editing it is a
    // financial decision rather than an operational one.
    it('stops Staff from editing the customer master', function () {
        ($this->asRole)('Staff')->post('/sales/customers', [
            'name' => 'New Customer',
            'type' => 'retail',
        ])->assertForbidden();
    });

    it('lets an Owner add a customer', function () {
        ($this->asRole)('Owner')->post('/sales/customers', [
            'name' => 'Karim Traders',
            'type' => 'distributor',
            'code' => 'DIST-001',
        ])->assertRedirect();

        expect(Customer::where('code', 'DIST-001')->exists())->toBeTrue();
    });

    it('stops a user with no sales role from seeing anything', function () {
        $outsider = User::factory()->create();
        $outsider->businesses()->attach($this->business->id);

        $this->actingAs($outsider)->get('/sales/invoices')->assertForbidden();
    });
});

// ⚠⚠ Every counter sale in the business's history points at the walk-in row.
it('refuses to deactivate the walk-in customer', function () {
    ($this->asRole)('Owner')
        ->delete(route('sales.customers.destroy', Customer::walkIn()->id))
        ->assertSessionHasErrors('customer');

    expect(Customer::walkIn()->fresh()->is_active)->toBeTrue();
});

it('shows the journal on the invoice, so the books can be checked', function () {
    ($this->asRole)('Staff')->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ]);

    ($this->asRole)('Manager')
        ->get(route('sales.invoices.show', SalesInvoice::first()->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/invoices/show')
            ->has('journal.lines', 4));
});

it('lists customers, prices, invoices and challans', function () {
    foreach (['/sales/customers', '/sales/prices', '/sales/invoices', '/sales/deliveries'] as $url) {
        ($this->asRole)('Manager')->get($url)->assertOk();
    }
});
