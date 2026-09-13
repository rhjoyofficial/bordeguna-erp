<?php

use App\Models\User;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Website slice 2.5a: the screen where a shop's bundles are composed and priced.
 *
 * ⚠⚠ Two kinds of decision on one screen, two permissions. Composing is content
 * work (Staff hold it); a bundle PRICE is money a customer is charged, gated by
 * `sales.prices.manage` - the permission that already guards the price list.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    (new CommercePermissionsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    };

    $retail = PriceGroup::create(['code' => 'RETAIL', 'name' => 'Retail']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $make = function (string $sku, string $name, string $price, ProductType $type = ProductType::FinishedGood) use ($unit): Product {
        $product = Product::create([
            'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
            'sku' => $sku, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
        ProductPrice::create(['product_id' => $product->id, 'unit_price' => $price, 'effective_from' => '2026-01-01']);

        return $product;
    };

    $this->hilsa = $make('PIC-HIL-400', 'Hilsa Fish Pickle 400g', '999');
    $this->beef = $make('PIC-BEF-400', 'Royal Beef Pickle 400g', '1350');
    $this->courier = $make('SRV-COURIER', 'Courier charge', '120', ProductType::Service);

    $this->store = Store::create([
        'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD', 'price_group_id' => $retail->id,
    ]);

    $this->payload = fn (array $overrides = []) => array_merge([
        'title' => 'Pickle Duo',
        'summary' => 'Two pickles.',
        'price' => '',
        'items' => [
            ['product_id' => $this->hilsa->id, 'quantity' => 1],
            ['product_id' => $this->beef->id, 'quantity' => 1],
        ],
    ], $overrides);
});

it('shows the bundles screen with each bundle priced by the server', function () {
    $combo = StoreCombo::create(['store_id' => $this->store->id, 'slug' => 'pickle-duo', 'title' => 'Pickle Duo', 'price' => '1998']);
    $combo->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 1]);
    $combo->items()->create(['product_id' => $this->beef->id, 'quantity' => 1]);

    ($this->asRole)('Staff')
        ->get("/commerce/stores/{$this->store->id}/combos")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('commerce/stores/combos')
            ->where('combos.0.parts_total', '2,349.00')
            ->where('combos.0.total', '1,998.00')
            ->where('combos.0.saving', '351.00')
            ->where('combos.0.refusal', null)
            // ⚠ A service can never be delivered, so it is never offered.
            ->where('products', fn ($products) => collect($products)->pluck('id')->doesntContain($this->courier->id))
            ->where('can_set_price', false));
});

it('lets content staff compose a bundle as a draft', function () {
    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos", ($this->payload)())
        ->assertSessionHasNoErrors();

    $combo = StoreCombo::with('items')->sole();

    expect($combo->slug)->toBe('pickle-duo')
        ->and($combo->price)->toBeNull()
        ->and($combo->items)->toHaveCount(2)
        // ⚠ A draft: publishing is a separate act that asks if it can be sold.
        ->and($combo->isPublished())->toBeFalse();
});

it('refuses a bundle price from somebody who may not set selling prices', function () {
    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos", ($this->payload)(['price' => '1998']))
        ->assertSessionHasErrors(['price' => 'Setting a bundle price needs permission to manage selling prices.']);

    expect(StoreCombo::count())->toBe(0);
});

it('lets content staff edit a bundle without touching a price they cannot set', function () {
    $combo = StoreCombo::create(['store_id' => $this->store->id, 'slug' => 'pickle-duo', 'title' => 'Pickle Duo', 'price' => '1998']);
    $combo->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 2]);

    ($this->asRole)('Staff')
        ->put("/commerce/stores/{$this->store->id}/combos/{$combo->id}", ($this->payload)([
            'title' => 'Pickle Duo Deluxe', 'slug' => 'pickle-duo', 'position' => 0, 'price' => '1998.00',
        ]))
        ->assertSessionHasNoErrors();

    expect($combo->fresh()->title)->toBe('Pickle Duo Deluxe')
        ->and((string) $combo->fresh()->price)->toBe('1998.00');

    // ...but clearing it is still changing it.
    ($this->asRole)('Staff')
        ->put("/commerce/stores/{$this->store->id}/combos/{$combo->id}", ($this->payload)([
            'slug' => 'pickle-duo', 'position' => 0, 'price' => '',
        ]))
        ->assertSessionHasErrors('price');
});

it('lets somebody who manages prices set a bundle price', function () {
    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/combos", ($this->payload)(['price' => '1998']))
        ->assertSessionHasNoErrors();

    expect((string) StoreCombo::sole()->price)->toBe('1998.00');
});

it('refuses to publish a bundle priced above its contents', function () {
    $combo = StoreCombo::create(['store_id' => $this->store->id, 'slug' => 'pickle-duo', 'title' => 'Pickle Duo', 'price' => '2500']);
    $combo->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 1]);
    $combo->items()->create(['product_id' => $this->beef->id, 'quantity' => 1]);

    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos/{$combo->id}/publish")
        ->assertSessionHasErrors('published_at');

    expect($combo->fresh()->isPublished())->toBeFalse();

    $combo->update(['price' => '1998']);

    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos/{$combo->id}/publish")
        ->assertSessionHasNoErrors();

    expect($combo->fresh()->isPublished())->toBeTrue();
});

it('refuses a service, a repeated product and a one-jar bundle', function () {
    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos", ($this->payload)(['items' => [
            ['product_id' => $this->hilsa->id, 'quantity' => 1],
            ['product_id' => $this->courier->id, 'quantity' => 1],
        ]]))
        ->assertSessionHasErrors(['items.1.product_id' => 'Courier charge is a service and cannot be sold in a bundle.']);

    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos", ($this->payload)(['items' => [
            ['product_id' => $this->hilsa->id, 'quantity' => 1],
            ['product_id' => $this->hilsa->id, 'quantity' => 1],
        ]]))
        ->assertSessionHasErrors('items.0.product_id');

    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos", ($this->payload)(['items' => [
            ['product_id' => $this->hilsa->id, 'quantity' => 1],
        ]]))
        ->assertSessionHasErrors(['items' => 'A bundle needs at least two items in it.']);

    expect(StoreCombo::count())->toBe(0);
});

it('refuses a product that belongs to another business', function () {
    $other = Business::create(['name' => 'Other', 'slug' => 'other']);
    $unit = UnitOfMeasure::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $theirs = Product::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'THEIRS', 'name' => 'Their jar', 'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);

    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/combos", ($this->payload)(['items' => [
            ['product_id' => $this->hilsa->id, 'quantity' => 1],
            ['product_id' => $theirs->id, 'quantity' => 1],
        ]]))
        ->assertSessionHasErrors(['items.1.product_id' => 'That product is not in this business.']);
});

it('will not delete a bundle somebody has put in a basket', function () {
    $combo = StoreCombo::create(['store_id' => $this->store->id, 'slug' => 'pickle-duo', 'title' => 'Pickle Duo']);
    $combo->items()->create(['product_id' => $this->hilsa->id, 'quantity' => 2]);

    $cart = Cart::create(['store_id' => $this->store->id, 'token' => Cart::newToken()]);
    $cart->comboLines()->create(['store_combo_id' => $combo->id, 'quantity' => 1]);

    ($this->asRole)('Staff')
        ->delete("/commerce/stores/{$this->store->id}/combos/{$combo->id}")
        ->assertSessionHasErrors(['combo' => 'Pickle Duo has been put in 1 basket, so it cannot be deleted. Take it off sale instead.']);

    expect(StoreCombo::count())->toBe(1);
});

it('does not reach another shop\'s bundle through this shop\'s address', function () {
    $other = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);
    $theirs = StoreCombo::create(['store_id' => $other->id, 'slug' => 'theirs', 'title' => 'Theirs']);

    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/combos/{$theirs->id}/unpublish")
        ->assertNotFound();
});
