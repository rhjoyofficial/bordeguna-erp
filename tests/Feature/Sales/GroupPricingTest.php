<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Actions\SetProductGroupPrices;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Prices are entered per group, never generated, and no group falls back to
 * another (Joy, 21 Sep 2026). The one default: a group that pays MRP (Retail,
 * and every website) is charged the product's MRP when nothing is set.
 *
 * ⚠ What matters most here is the REFUSAL: a dealer with no dealer price must
 * not be sold to at a retail figure, however many retail prices exist.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-21 10:00:00');

    $this->business = Business::create(['name' => 'Price Co', 'slug' => 'price-co']);
    TenantScope::enter($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Jar', 'abbreviation' => 'jar']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HON-500', 'name' => 'Sundarban Honey 500g', 'type' => ProductType::FinishedGood, 'mrp' => '500',
    ]);

    $this->retail = PriceGroup::create(['business_id' => $this->business->id, 'code' => 'RETAIL', 'name' => 'Retail', 'falls_back_to_mrp' => true]);
    $this->dealer = PriceGroup::create(['business_id' => $this->business->id, 'code' => 'DEALER', 'name' => 'Dealer']);

    $this->dealerCustomer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Traders', 'price_group_id' => $this->dealer->id]);
    $this->consumer = Customer::create(['business_id' => $this->business->id, 'name' => 'Walk-in']);

    $permissions = ['sales.prices.manage', 'inventory.catalog.manage', 'inventory.reports.view'];
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);
    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole($role);

    $this->resolver = app(ResolveSellingPrice::class);
});

afterEach(fn () => Carbon::setTestNow());

// ─── THE RESOLVER ───────────────────────────────────────────────────────────

it('refuses a dealer with no dealer price, whatever the retail price is', function () {
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'price_group_id' => $this->retail->id, 'unit_price' => '480', 'effective_from' => '2026-01-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '470', 'effective_from' => '2026-01-01']);

    expect($this->resolver->find($this->product, $this->dealerCustomer))->toBeNull();
});

it('charges a consumer the MRP when no retail price is set, and says so', function () {
    $resolved = $this->resolver->resolve($this->product, $this->consumer);

    expect($resolved->unitPrice)->toBe('500.0000')
        ->and($resolved->tier())->toBe('mrp')
        ->and($resolved->explain())->toBe('MRP (no price set for this group)')
        ->and(ProductPrice::count())->toBe(0);
});

it('charges a retail price over the MRP, and a dealer price to a dealer', function () {
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'price_group_id' => $this->retail->id, 'unit_price' => '480', 'effective_from' => '2026-01-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'price_group_id' => $this->dealer->id, 'unit_price' => '430', 'effective_from' => '2026-01-01']);

    expect($this->resolver->resolve($this->product, $this->consumer)->unitPrice)->toBe('480.0000')
        ->and($this->resolver->resolve($this->product, $this->dealerCustomer)->unitPrice)->toBe('430.0000');
});

it('lets a group the owner marks as paying MRP fall back to it', function () {
    $shop = PriceGroup::create(['business_id' => $this->business->id, 'code' => 'RETAIL_SHOP', 'name' => 'Retail shop', 'falls_back_to_mrp' => true]);
    $shopCustomer = Customer::create(['business_id' => $this->business->id, 'name' => 'Corner Store', 'price_group_id' => $shop->id]);

    expect($this->resolver->resolve($this->product, $shopCustomer)->unitPrice)->toBe('500.0000');
});

it('prices a website at MRP when its group has no price, because a website sells to consumers', function () {
    expect($this->resolver->resolve($this->product, null, '1', null, $this->dealer->id)->unitPrice)->toBe('500.0000');
});

it('has no price for a consumer when there is neither a price nor an MRP', function () {
    $this->product->update(['mrp' => null]);

    expect($this->resolver->find($this->product->fresh(), $this->consumer))->toBeNull();
});

// ─── SETTING PRICES FROM THE PRODUCT DIALOG ─────────────────────────────────

it('sets a fixed price and a percentage off MRP, to the paisa', function () {
    $this->product->update(['mrp' => '333']);

    $result = app(SetProductGroupPrices::class)->handle($this->product->fresh(), [
        $this->retail->id => ['method' => 'fixed', 'value' => '320'],
        $this->dealer->id => ['method' => 'percent_off_mrp', 'value' => '12.5'],
    ], $this->owner);

    $dealerRow = ProductPrice::where('price_group_id', $this->dealer->id)->sole();

    expect($result['set'])->toBe(2)
        // 333 less 12.5% is 291.375: half a paisa rounds up.
        ->and((string) $dealerRow->unit_price)->toBe('291.3800')
        ->and($dealerRow->price_method)->toBe('percent_off_mrp')
        ->and((string) $dealerRow->percent_off)->toBe('12.5000')
        ->and((string) $dealerRow->basis_mrp)->toBe('333.0000')
        ->and($this->resolver->resolve($this->product, $this->dealerCustomer)->unitPrice)->toBe('291.3800');
});

it('closes the old price and starts a new one from today, and writes nothing when nothing changed', function () {
    $set = app(SetProductGroupPrices::class);
    Carbon::setTestNow('2026-09-10 10:00:00');
    $set->handle($this->product, [$this->dealer->id => ['method' => 'fixed', 'value' => '440']]);

    Carbon::setTestNow('2026-09-21 10:00:00');
    $again = $set->handle($this->product, [$this->dealer->id => ['method' => 'fixed', 'value' => '440']]);
    $changed = $set->handle($this->product, [$this->dealer->id => ['method' => 'fixed', 'value' => '430']]);

    $rows = ProductPrice::where('price_group_id', $this->dealer->id)->orderBy('id')->get();

    expect($again['unchanged'])->toBe(1)
        ->and($changed['set'])->toBe(1)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->effective_to->toDateString())->toBe('2026-09-20')
        ->and($rows[1]->effective_from->toDateString())->toBe('2026-09-21')
        // The old price still explains a sale made on the 15th.
        ->and($this->resolver->resolve($this->product, $this->dealerCustomer, '1', '2026-09-15')->unitPrice)->toBe('440.0000');
});

it('stops selling to a group when its price is taken away', function () {
    $set = app(SetProductGroupPrices::class);
    Carbon::setTestNow('2026-09-10 10:00:00');
    $set->handle($this->product, [$this->dealer->id => ['method' => 'fixed', 'value' => '440']]);
    Carbon::setTestNow('2026-09-21 10:00:00');

    $result = $set->handle($this->product, [$this->dealer->id => ['method' => 'none']]);

    expect($result['ended'])->toBe(1)
        ->and($this->resolver->find($this->product, $this->dealerCustomer))->toBeNull();
});

it('refuses a percentage off MRP when the product has no MRP', function () {
    $this->product->update(['mrp' => null]);

    expect(fn () => app(SetProductGroupPrices::class)->handle($this->product->fresh(), [
        $this->dealer->id => ['method' => 'percent_off_mrp', 'value' => '10'],
    ]))->toThrow(ValidationException::class);
});

it('refuses another business price group', function () {
    $other = Business::create(['name' => 'Other', 'slug' => 'other']);
    $theirs = PriceGroup::withoutGlobalScopes()->create(['business_id' => $other->id, 'code' => 'DEALER', 'name' => 'Dealer']);

    expect(fn () => app(SetProductGroupPrices::class)->handle($this->product, [
        $theirs->id => ['method' => 'fixed', 'value' => '1'],
    ]))->toThrow(ValidationException::class);
});

// ─── THE SCREENS ────────────────────────────────────────────────────────────

it('saves a product with its MRP and answers the dialog with its id', function () {
    $unit = UnitOfMeasure::first();

    $response = $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)->postJson(route('inventory.products.store'), [
        'sku' => 'GHE-250', 'name' => 'Cow Ghee 250g', 'type' => 'finished_good', 'unit_of_measure_id' => $unit->id, 'mrp' => '650',
    ])->assertCreated();

    expect(Product::find($response->json('id'))->mrp)->toBe('650.0000');
});

it('reads and saves group prices as JSON for the dialog', function () {
    $this->actingAs($this->owner)->getJson(route('sales.group-prices.show', ['product_id' => $this->product->id]))
        ->assertOk()
        ->assertJsonPath('mrp', '500.0000')
        ->assertJsonPath('groups.0.code', 'RETAIL')
        ->assertJsonPath('groups.0.falls_back_to_mrp', true)
        ->assertJsonPath('groups.1.falls_back_to_mrp', false);

    $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->putJson(route('sales.group-prices.update', $this->product), ['prices' => [
            ['price_group_id' => $this->dealer->id, 'method' => 'percent_off_mrp', 'value' => '10'],
        ]])
        ->assertOk()
        ->assertJsonPath('set', 1);

    expect($this->resolver->resolve($this->product, $this->dealerCustomer)->unitPrice)->toBe('450.0000');
});

it('keeps the prices section away from somebody who does not set prices', function () {
    $clerk = User::factory()->create();
    $clerk->businesses()->attach($this->business->id);

    $this->actingAs($clerk)->getJson(route('sales.group-prices.show'))->assertForbidden();
});

it('adds a price group from its screen and never lets Retail stop paying MRP', function () {
    $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('sales.price-groups.store'), ['code' => 'RETAIL_SHOP', 'name' => 'Retail shop', 'prices_include_tax' => true, 'falls_back_to_mrp' => true])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->put(route('sales.price-groups.update', $this->retail), ['name' => 'Retail', 'prices_include_tax' => true, 'falls_back_to_mrp' => false, 'is_active' => false])
        ->assertSessionHasNoErrors();

    expect(PriceGroup::where('code', 'RETAIL_SHOP')->sole()->falls_back_to_mrp)->toBeTrue()
        ->and($this->retail->fresh()->falls_back_to_mrp)->toBeTrue()
        ->and($this->retail->fresh()->is_active)->toBeTrue();
});

it('refuses a selling price for nobody in particular', function () {
    $this->actingAs($this->owner)->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('sales.prices.store'), ['product_id' => $this->product->id, 'unit_price' => '470', 'effective_from' => '2026-09-21'])
        ->assertSessionHasErrors('price_group_id');

    expect(ProductPrice::count())->toBe(0);
});

it('refuses a retail shop with no shop price even though the product has an MRP', function () {
    // 26 Sep 2026, Q15: "not MRP, will be below it but authority will add retail price".
    PriceGroup::create(['business_id' => $this->business->id, 'code' => 'SHOP', 'name' => 'Retail shop']);
    $shop = Customer::create(['business_id' => $this->business->id, 'name' => 'Corner Store', 'type' => CustomerType::Shop]);

    expect($this->product->mrp)->toBe('500.0000')
        ->and($this->resolver->find($this->product, $shop))->toBeNull()
        ->and($this->resolver->resolve($this->product, $this->consumer)->unitPrice)->toBe('500.0000');
});
