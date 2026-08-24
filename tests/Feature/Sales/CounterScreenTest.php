<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The counter as a screen, in the POS anatomy: a card grid needs categories to
 * filter by and a price on every card. The engine behind the sale is already
 * pinned by CounterSaleTest - what only this screen can get wrong is feeding
 * the grid a price the resolver would not have given.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Till Co', 'slug' => 'till-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-08-24 10:00:00');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $this->dryFish = Category::create(['business_id' => $this->business->id, 'name' => 'Dry Fish', 'slug' => 'dry-fish', 'code' => 'DRY']);
    $this->emptyCategory = Category::create(['business_id' => $this->business->id, 'name' => 'Unused', 'slug' => 'unused', 'code' => 'UNU']);

    $this->retail = PriceGroup::create([
        'business_id' => $this->business->id, 'code' => 'RETAIL', 'name' => 'Retail', 'is_active' => true,
    ]);

    $this->priced = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'category_id' => $this->dryFish->id,
        'sku' => 'LLD-DRY-CHU-125G', 'name' => 'Churi Shutki - 125 g',
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);
    $this->unpriced = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'LLD-NEW-JAR', 'name' => 'Unpriced Jar',
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);

    // A general row AND a retail row: the walk-in belongs to RETAIL by
    // default (D-PRC-2), so the card must show 340, not 360.
    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $this->priced->id,
        'unit_price' => '360', 'effective_from' => '2026-01-01',
    ]);
    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $this->priced->id,
        'unit_price' => '340', 'price_group_id' => $this->retail->id, 'effective_from' => '2026-01-01',
    ]);

    $this->asOwner = function () {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole('Owner');

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('feeds the grid everything a card needs: category, categories in use, and the walk-in price', function () {
    ($this->asOwner)()->get(route('sales.counter.create'))
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('sales/counter/create')
            ->has('products', 2)
            ->where('products.0.category_id', $this->dryFish->id)
            // Only categories the grid can actually filter to - the unused one
            // would be a chip that never matches anything.
            ->has('categories', 1)
            ->where('categories.0.name', 'Dry Fish')
            // The card price is the walk-in's RETAIL price, not the general
            // row - the same answer the resolver gives the cart.
            ->where("cardPrices.{$this->priced->id}", 340)
            // A product nothing prices has NO card price, not a zero.
            ->missing("cardPrices.{$this->unpriced->id}"));
});
