<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The order form (21 Sep 2026): each line's price is asked of the server as it
 * is typed, a product with no price for this customer is named before submit,
 * and a typed price needs the same permission as a changed price at the counter.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Entry Co', 'slug' => 'entry-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['sales.reports.view', 'sales.orders.record', 'sales.price.override', 'sales.customers.manage'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.reports.view', 'sales.orders.record']);
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.reports.view', 'sales.orders.record', 'sales.price.override', 'sales.customers.manage']);

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    $this->dealers = PriceGroup::create(['business_id' => $this->business->id, 'code' => 'DEALER', 'name' => 'Dealer', 'falls_back_to_mrp' => false, 'is_active' => true]);

    Carbon::setTestNow('2026-09-21 10:00:00');
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-09-01']);

    $this->shop = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Store', 'phone' => '01711-000000', 'type' => CustomerType::Retail]);
    $this->dealer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Dealer', 'type' => CustomerType::Dealer, 'price_group_id' => $this->dealers->id]);

    $this->price = fn (Customer $customer, User $as) => $this->actingAs($as)
        ->getJson(route('sales.orders.price', ['product_id' => $this->product->id, 'quantity' => 2, 'customer_id' => $customer->id]));
});

afterEach(fn () => Carbon::setTestNow());

it('answers the price a line will be ordered at, for whoever may take an order', function () {
    ($this->price)($this->shop, $this->staff)
        ->assertOk()
        ->assertJson(['unit_price' => '850.0000', 'refusal' => null]);
});

it('names a product that has no price for this customer before the order is refused for it', function () {
    ($this->price)($this->dealer, $this->staff)
        ->assertOk()
        ->assertJsonPath('unit_price', null)
        ->assertJsonPath('refusal', fn (string $refusal) => str_contains($refusal, 'Rahim Dealer') && str_contains($refusal, 'Sundarban Honey 500g'));
});

it('refuses a typed price from somebody who may not change prices', function () {
    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_price' => '1']],
    ])->assertSessionHasErrors('lines.0.unit_price');

    expect(SalesOrder::count())->toBe(0);
});

it('takes the listed price when nobody typed one, and a typed price from a manager', function () {
    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    // ⚠ Inside the Manager's 3% cap (audit, 13 Oct): 830 against a list of
    // 850 is 2.4% off. 800 (5.9%) is now refused like it is at the counter.
    $this->actingAs($this->manager)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_price' => '830']],
    ])->assertSessionHasNoErrors();

    expect(SalesOrder::with('lines')->orderBy('id')->get()->map(fn ($order) => (string) $order->lines->first()->unit_price)->all())
        ->toBe(['850.0000', '830.0000']);
});

it('gives the form phones to search by and what a quick new customer needs', function () {
    $this->actingAs($this->manager)->get(route('sales.orders.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers', fn ($customers) => collect($customers)->firstWhere('name', 'Karim Store')['phone'] === '01711-000000')
            ->where('can_add_customer', true)
            ->where('can_override_price', true)
            ->where('customer_types', fn ($types) => ! collect($types)->contains('value', 'walk_in'))
            ->where('price_groups.0.code', 'DEALER'));

    $this->actingAs($this->staff)->get(route('sales.orders.index'))
        ->assertInertia(fn (Assert $page) => $page->where('can_add_customer', false)->where('can_override_price', false));
});
