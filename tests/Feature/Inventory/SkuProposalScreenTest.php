<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The SKU proposal endpoint, through the real HTTP layer.
 *
 * ⚠⚠ The unit tests prove the convention. This file proves the things only the
 * HTTP layer can get wrong: that it is reachable, that it writes nothing, that
 * it is gated, and above all that a category code from ANOTHER business can
 * never reach this business's SKU.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Sku Co', 'slug' => 'sku-proposal-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs',
    ]);
    $this->category = Category::create([
        'business_id' => $this->business->id, 'name' => 'Honey', 'slug' => 'honey', 'code' => 'HON',
    ]);
    $this->uncoded = Category::create([
        'business_id' => $this->business->id, 'name' => 'Nuts', 'slug' => 'nuts',
    ]);
    $this->brand = Brand::create([
        'business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia',
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->ask = fn (array $query) => ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->getJson(route('inventory.products.sku-proposal', $query));
});

it('proposes a SKU in the shape this business uses', function () {
    ($this->ask)([
        'name' => 'Mangrove Gold Honey (ম্যানগ্রোভ গোল্ড হানি) - 500 g',
        'type' => 'finished_good',
        'brand_id' => $this->brand->id,
        'category_id' => $this->category->id,
    ])->assertOk()->assertJson(['sku' => 'LLD-HON-MAN-500G', 'reason' => null]);
});

it('writes absolutely nothing', function () {
    // ⚠⚠ The whole safety claim of C-SKU-2 in one assertion. If this endpoint
    // ever created so much as a category, a person browsing the form would be
    // editing the catalogue.
    $before = [Product::count(), Category::count(), Brand::count()];

    ($this->ask)([
        'name' => 'Mangrove Gold Honey - 500 g',
        'type' => 'finished_good',
        'brand_id' => $this->brand->id,
        'category_id' => $this->category->id,
    ])->assertOk();

    expect([Product::count(), Category::count(), Brand::count()])->toBe($before);
});

it('adopts the abbreviation this business already uses', function () {
    // The catalogue says Black is BLK. A new size of the same oil must not come
    // back as BLA and quietly become a second product family.
    Product::create([
        'business_id' => $this->business->id,
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'LLD-HON-BLK-200ML',
        'name' => 'Black Seed Oil (কালোজিরার তেল) - 200 ml',
        'type' => 'finished_good',
    ]);

    ($this->ask)([
        'name' => 'Black Seed Oil (কালোজিরার তেল) - 500 ml',
        'type' => 'finished_good',
        'brand_id' => $this->brand->id,
        'category_id' => $this->category->id,
    ])->assertOk()->assertJson(['sku' => 'LLD-HON-BLK-500ML']);
});

it('numbers around a SKU that is already taken', function () {
    Product::create([
        'business_id' => $this->business->id,
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'LLD-HON-MAN-500G',
        'name' => 'Something Else Entirely',
        'type' => 'finished_good',
    ]);

    ($this->ask)([
        'name' => 'Mangrove Gold Honey - 500 g',
        'type' => 'finished_good',
        'brand_id' => $this->brand->id,
        'category_id' => $this->category->id,
    ])->assertOk()->assertJson(['sku' => 'LLD-HON-MAN-500G-2']);
});

it('does not count a product against its own SKU when it is being edited', function () {
    $product = Product::create([
        'business_id' => $this->business->id,
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'LLD-HON-MAN-500G',
        'name' => 'Mangrove Gold Honey - 500 g',
        'type' => 'finished_good',
    ]);

    // Without product_id this would come back numbered against itself, which
    // reads as "your SKU is wrong" to whoever pressed the button.
    ($this->ask)([
        'name' => 'Mangrove Gold Honey - 500 g',
        'type' => 'finished_good',
        'brand_id' => $this->brand->id,
        'category_id' => $this->category->id,
        'product_id' => $product->id,
    ])->assertOk()->assertJson(['sku' => 'LLD-HON-MAN-500G']);
});

it('says what is missing rather than inventing a category segment', function () {
    ($this->ask)([
        'name' => 'Mixed Nuts - 205 g',
        'type' => 'finished_good',
        'brand_id' => $this->brand->id,
        'category_id' => $this->uncoded->id,
    ])->assertOk()->assertJson(['sku' => null]);
});

it('refuses to read another business category code', function () {
    // ⚠⚠ THE ONE THAT MATTERS. An unscoped lookup here would put a foreign
    // business's segment into this business's product identity, and every
    // importer matches on that string for ever after.
    $other = Business::create(['name' => 'Other Co', 'slug' => 'sku-other-co']);
    $foreign = Category::create([
        'business_id' => $other->id, 'name' => 'Dates', 'slug' => 'dates-other', 'code' => 'DAT',
    ]);

    ($this->ask)([
        'name' => 'Ajwa Date - 1 kg',
        'type' => 'finished_good',
        'brand_id' => $this->brand->id,
        'category_id' => $foreign->id,
    ])->assertOk()->assertJson(['sku' => null]);
});

it('saves a category code, and a family path, from the screen', function (string $typed, ?string $stored) {
    ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->put(route('inventory.categories.update', $this->uncoded->id), ['name' => 'Nuts', 'code' => $typed])
        ->assertRedirect();

    expect($this->uncoded->fresh()->code)->toBe($stored);
})->with([
    'plain segment' => ['MIX', 'MIX'],
    'lower case is stored upper' => ['mix', 'MIX'],
    // ⚠ Packaging carries its own family, which is what makes PK-JAR-500G come
    // out with no RM prefix and no repeated item segment.
    'family path' => ['PK-JAR', 'PK-JAR'],
    // ⚠⚠ Blank must land as NULL, not ''. The generator reads null as "this
    // category has not said yet" and declines; an empty string would sail
    // through the same check and produce a SKU with a hole in it.
    'blank is null' => ['', null],
]);

it('refuses a category code that would put a hole in a SKU', function (string $typed) {
    ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->put(route('inventory.categories.update', $this->uncoded->id), ['name' => 'Nuts', 'code' => $typed])
        ->assertSessionHasErrors('code');

    expect($this->uncoded->fresh()->code)->toBeNull();
})->with([
    'trailing hyphen' => ['PK-'],
    'leading hyphen' => ['-JAR'],
    'double hyphen' => ['PK--JAR'],
    'one letter' => ['J'],
    'punctuation' => ['P*K'],
    'too long' => ['ABCDEFGHI'],
]);

it('is closed to anyone who cannot manage the catalogue', function () {
    ($this->asRole)('Staff')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->getJson(route('inventory.products.sku-proposal', [
            'name' => 'Mangrove Gold Honey - 500 g',
            'type' => 'finished_good',
            'brand_id' => $this->brand->id,
            'category_id' => $this->category->id,
        ]))
        ->assertForbidden();
});

it('keeps a category that still has products, and deletes an unused one', function () {
    $product = Product::create([
        'business_id' => $this->business->id,
        'category_id' => $this->category->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => 'HON-KEEP-1',
        'name' => 'Honey 500 g',
        'type' => 'finished_good',
    ]);

    ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->delete(route('inventory.categories.destroy', $this->category->id))
        ->assertRedirect()->assertSessionHas('error');

    expect(Category::find($this->category->id))->not->toBeNull()
        ->and($product->fresh()->category_id)->toBe($this->category->id);

    ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->delete(route('inventory.categories.destroy', $this->uncoded->id))
        ->assertRedirect()->assertSessionHas('success');

    expect(Category::find($this->uncoded->id))->toBeNull();
});

// Add and edit are a popup over the categories list since 14 Oct 2026 (the form
// no longer sits above it). The page carries what the popup draws - each
// category's name and code - and the popup saves through the same routes, a
// blank code still landing as NULL.
it('carries what the category popup needs on the list, and saves through the same routes', function () {
    ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('inventory.categories.store'), ['name' => 'Seeds', 'code' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $seeds = Category::where('slug', 'seeds')->sole();
    expect($seeds->code)->toBeNull();

    ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->put(route('inventory.categories.update', $seeds->id), ['name' => 'Seeds and Grains', 'code' => 'sed'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    ($this->asRole)('Owner')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('inventory.categories.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/categories/index')
            ->where('inventoryAbilities.manageCatalog', true)
            ->where('categories', fn ($categories) => collect($categories)->contains(
                fn ($category) => $category['id'] === $seeds->id && $category['name'] === 'Seeds and Grains' && $category['code'] === 'SED')));

    // Someone who may only look gets the list, not the popup.
    ($this->asRole)('Staff')
        ->withHeader('X-Business-Id', (string) $this->business->id)
        ->get(route('inventory.categories.index'))
        ->assertInertia(fn ($page) => $page->where('inventoryAbilities.manageCatalog', false));
});
