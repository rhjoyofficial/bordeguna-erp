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
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 3, Slice 5 (POS spec §12): Buy X Get Y free.
 *
 * The one promotion a Bangladeshi shop actually runs. It is a CART rule, not
 * a price: the discounts engine already handles every cheaper-price shape
 * through the resolver, and the resolver must keep knowing nothing about
 * this either. The free line is added SERVER-SIDE at the counter with a unit
 * price of zero - revenue 0, cost of goods still posts, the books tell the
 * truth about giving stock away.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Promo Co', 'slug' => 'promo-co']);
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
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);
    $this->shutki = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-SHUTKI', 'name' => 'Churi Shutki 125g',
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
    openTill($this->owner, $this->cash);

    app(RecordStockIn::class)->handle($this->honey, $this->warehouse, '20', '500', $this->owner, occurredAt: '2026-08-01 09:00:00');
    app(RecordStockIn::class)->handle($this->shutki, $this->warehouse, '20', '100', $this->owner, occurredAt: '2026-08-01 09:00:00');

    foreach ([$this->honey->id => '850', $this->shutki->id => '340'] as $productId => $price) {
        ProductPrice::create([
            'business_id' => $this->business->id, 'product_id' => $productId,
            'unit_price' => $price, 'effective_from' => '2026-01-01',
        ]);
    }

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

function makePromotion(array $overrides = []): int
{
    return (int) DB::table('promotions')->insertGetId(array_merge([
        'business_id' => test()->business->id,
        'name' => 'Buy 3 honey get 1 shutki',
        'buy_product_id' => test()->honey->id,
        'buy_quantity' => 3,
        'get_product_id' => test()->shutki->id,
        'get_quantity' => 1,
        'starts_on' => '2026-08-01',
        'ends_on' => '2026-08-31',
        'status' => 'active',
        'created_by' => test()->owner->id,
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

it('adds the free line server-side when the cart earns it, costed but at zero price', function () {
    makePromotion();

    ($this->asOwner)()->post(route('sales.counter.store'), [
        'customer_id' => DB::table('customers')->where('is_system', true)->value('id'),
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '3']],
    ])->assertSessionHasNoErrors();

    $invoice = DB::table('sales_invoices')->first();
    $lines = DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->id)->get();

    // Two lines: 3 honey at 850, 1 shutki free. The total is honey only.
    expect($lines)->toHaveCount(2)
        ->and((float) $invoice->total)->toBe(2550.0)
        ->and((float) $lines->firstWhere('product_id', $this->shutki->id)->unit_price)->toBe(0.0)
        // The free item still left the shelf - stock tells the truth.
        ->and($this->shutki->fresh()->currentStockQuantity())->toBe('19');
});

it('multiplies whole times earned and ignores a cart that falls short', function () {
    makePromotion();

    // 7 honey = the deal twice (6), the 7th earns nothing.
    ($this->asOwner)()->post(route('sales.counter.store'), [
        'customer_id' => DB::table('customers')->where('is_system', true)->value('id'),
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '7']],
    ])->assertSessionHasNoErrors();

    $freeLine = DB::table('sales_invoice_lines')->where('product_id', $this->shutki->id)->first();
    expect((float) $freeLine->quantity)->toBe(2.0);

    // A cart below the threshold earns nothing at all.
    ($this->asOwner)()->post(route('sales.counter.store'), [
        'customer_id' => DB::table('customers')->where('is_system', true)->value('id'),
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    expect(DB::table('sales_invoice_lines')->where('product_id', $this->shutki->id)->count())->toBe(1);
});

it('sleeps outside its dates and when paused', function () {
    makePromotion(['ends_on' => '2026-08-20']);
    makePromotion(['name' => 'Paused deal', 'status' => 'paused', 'ends_on' => '2026-08-31']);

    ($this->asOwner)()->post(route('sales.counter.store'), [
        'customer_id' => DB::table('customers')->where('is_system', true)->value('id'),
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->honey->id, 'quantity' => '3']],
    ])->assertSessionHasNoErrors();

    expect(DB::table('sales_invoice_lines')->where('product_id', $this->shutki->id)->count())->toBe(0);
});

it('manages promotions from a gated screen', function () {
    ($this->asOwner)()->get(route('sales.promotions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/promotions'));

    ($this->asOwner)()->post(route('sales.promotions.store'), [
        'name' => 'Eid deal',
        'buy_product_id' => $this->honey->id,
        'buy_quantity' => 2,
        'get_product_id' => $this->honey->id,
        'get_quantity' => 1,
        'starts_on' => '2026-08-24',
        'ends_on' => '2026-09-10',
    ])->assertSessionHasNoErrors();

    expect(DB::table('promotions')->where('name', 'Eid deal')->exists())->toBeTrue();

    $bystander = User::factory()->create();
    $bystander->businesses()->attach($this->business->id);
    $this->actingAs($bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('sales.promotions'))
        ->assertForbidden();
});

// Starting a deal is a popup over the list since 14 Oct 2026 (the form no
// longer sits open above it); pausing and resuming stay on the row. The page
// carries the products both pickers offer, and the popup saves through the
// same route with the boxes it sends.
it('carries what the add-promotion popup needs on the list, and saves through the same route', function () {
    ($this->asOwner)()->get(route('sales.promotions'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('sales/promotions')
            ->where('products', fn ($products) => collect($products)->pluck('id')->intersect([$this->honey->id, $this->shutki->id])->count() === 2));

    ($this->asOwner)()->post(route('sales.promotions.store'), [
        'name' => 'Buy three honey, get a shutki',
        'buy_product_id' => (string) $this->honey->id,
        'buy_quantity' => '3',
        'get_product_id' => (string) $this->shutki->id,
        'get_quantity' => '1',
        'starts_on' => '2026-08-24',
        'ends_on' => '2026-08-31',
    ])->assertRedirect()->assertSessionHasNoErrors();

    ($this->asOwner)()->get(route('sales.promotions'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('promotions', fn ($promotions) => collect($promotions)->contains(
            fn ($p) => $p['name'] === 'Buy three honey, get a shutki'
                && $p['buy_product'] === 'Sundarban Honey 500g'
                && $p['get_product'] === 'Churi Shutki 125g'
                && $p['status'] === 'active')));
});
