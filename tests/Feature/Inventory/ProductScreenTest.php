<?php

use App\Models\User;
use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The product screen, through the real HTTP layer.
 *
 * ⚠⚠ WHY THIS FILE EXISTS. `products.barcode` shipped to production on
 * 2026-08-04 with six passing tests behind it - and no way to set it. Every one
 * of those tests called `Product::create()` directly, so all of them proved the
 * column, the unique index and the null handling, and none of them touched the
 * controller. The field was in `$fillable`, absent from the validation rules,
 * absent from the form, and therefore dead in production for a day.
 *
 * The same rule as suppliers applies here (Joy, 2026-08-05): the UI and the
 * catalogue import must produce the SAME business object. The structural guard
 * below is what makes that rule self-enforcing.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Catalogue Co', 'slug' => 'product-screen-co']);
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
    $this->purchaseUnit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg',
    ]);
    $this->category = Category::create([
        'business_id' => $this->business->id, 'name' => 'Honey', 'slug' => 'honey',
    ]);
    $this->brand = Brand::create([
        'business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia',
    ]);
    $this->taxClass = TaxClass::create([
        'business_id' => $this->business->id, 'code' => 'STANDARD', 'name' => 'Standard',
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->full = [
        'sku' => 'HON-MG-500',
        'barcode' => '8801234567890',
        'name' => 'Mustard Flower Honey 500g',
        'type' => 'finished_good',
        'category_id' => $this->category->id,
        // ⚠ The structural guard below caught the absence of this the moment
        // brand_id was added to Product::$fillable, which is exactly what it is
        // for. Brand must be settable from the screen, not only by the import.
        'brand_id' => $this->brand->id,
        'unit_of_measure_id' => $this->unit->id,
        // ⚠ Caught by the structural guard below the moment tax_class_id was
        // added to Product::$fillable, exactly as brand_id was before it. The
        // VAT engine is dormant, but a field the model can hold and the screen
        // cannot set is the failure this guard exists to prevent.
        'tax_class_id' => $this->taxClass->id,
        'description' => 'Sealed jar, 500g net.',
        'low_stock_threshold' => '12',
        // ⚠ Caught by the structural guard below the moment the pair was added
        // to Product::$fillable for the production yield check (C-MFG-14) -
        // the third time the guard has done exactly its job.
        'content_quantity' => '0.5',
        'content_unit_id' => $this->purchaseUnit->id,
        // ⚠ Added when the website's availability policy gained a per-product
        // override (Phase 2, slice 2.1). The structural guard below caught the
        // omission the moment the pair joined Product::$fillable - the fourth
        // time it has done exactly its job.
        'availability_override' => 'strict',
        'lead_time_days' => 3,
        // 26 Sep 2026 (Q8/Q9): which products must carry a batch and expiry on receipt.
        'tracks_expiry' => true,
        'shelf_life_warning_days' => 60,
        // ⚠ The printed MRP (21 Sep 2026), caught by the guard below the moment
        // it joined Product::$fillable - the fifth time it has done its job.
        'mrp' => '520',
        // ⭐ Courier hub slice 3 (11 Oct 2026): the weight of one unit as it
        // ships. Typing it sets weight_source to manual, which the guard also
        // needs filled - the sixth time it has done its job.
        'weight_grams' => 560,
    ];
});

/**
 * ⚠⚠ THE STRUCTURAL GUARD, driven by the model rather than a hand-copied list.
 * Add a fillable field to Product and forget the controller, and this fails.
 * That is the whole point: the list cannot drift silently, which is exactly
 * how barcode came to be unreachable.
 */
it('lets the screen set every field the model can hold', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);

    $product = Product::where('sku', 'HON-MG-500')->firstOrFail();

    // business_id is set by the tenant scope, is_active by deactivation.
    $expected = collect((new Product)->getFillable())
        ->reject(fn ($f) => in_array($f, ['business_id', 'is_active'], true))
        ->values();

    expect($expected)->not->toBeEmpty();

    $unsettable = $expected->reject(fn ($f) => filled($product->{$f}))->values()->all();

    expect($unsettable)->toBe([], 'these fields cannot be set from the screen: '.implode(', ', $unsettable));
});

it('creates a product with its barcode through the screen', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full)->assertRedirect();

    $product = Product::where('sku', 'HON-MG-500')->firstOrFail();

    expect($product->barcode)->toBe('8801234567890')
        ->and($product->description)->toBe('Sealed jar, 500g net.')
        ->and($product->is_active)->toBeTrue();
});

it('updates every field through the screen', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);
    $product = Product::where('sku', 'HON-MG-500')->firstOrFail();

    $changed = array_merge($this->full, [
        'name' => 'Mustard Flower Honey 500g jar',
        'barcode' => '8809999999999',
        'description' => 'Relabelled pack.',
    ]);

    ($this->asRole)('Manager')
        ->put("/inventory/products/{$product->id}", $changed)
        ->assertRedirect();

    $product->refresh();

    expect($product->name)->toBe('Mustard Flower Honey 500g jar')
        ->and($product->barcode)->toBe('8809999999999')
        ->and($product->description)->toBe('Relabelled pack.')
        // ⚠ The SKU is permanent: re-barcoding must not create a second product.
        ->and($product->sku)->toBe('HON-MG-500')
        ->and(Product::count())->toBe(1);
});

// ⚠ The `ignore` branch of the unique rules. Without it, saving a product
// without changing its SKU or barcode would report it as a duplicate of itself.
it('lets a product keep its own sku and barcode when edited', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);
    $product = Product::where('sku', 'HON-MG-500')->firstOrFail();

    ($this->asRole)('Manager')
        ->put("/inventory/products/{$product->id}", array_merge($this->full, ['name' => 'Renamed']))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->name)->toBe('Renamed');
});

// ⚠⚠ A duplicate barcode must be a validation error, not a 1062 and a 500.
// The database guarantee stays; this is the screen refusing first, in words.
it('refuses a duplicate barcode with a validation error', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);

    ($this->asRole)('Manager')
        ->post('/inventory/products', array_merge($this->full, ['sku' => 'HON-MG-250']))
        ->assertSessionHasErrors('barcode');

    expect(Product::count())->toBe(1);
});

it('refuses a duplicate sku with a validation error', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);

    ($this->asRole)('Manager')
        ->post('/inventory/products', array_merge($this->full, ['barcode' => '8800000000000']))
        ->assertSessionHasErrors('sku');

    expect(Product::count())->toBe(1);
});

/**
 * ⚠⚠ THE TRAP THE BLANK BOX SETS. The unique index is on
 * (business_id, barcode). MySQL and SQLite both permit many NULLs but only one
 * '', so a form that posts an empty string stores '' on the first product and
 * then fails on a duplicate key for the second - on the ordinary path of a
 * business that does not use barcodes at all.
 *
 * ⚠ What actually saves us here is the framework's global
 * ConvertEmptyStringsToNull middleware, not anything in the controller. This
 * test was written expecting controller code to do it, and passed with that
 * code deleted - so it is deliberately left asserting the OUTCOME. If the
 * middleware is ever skipped for this route, this fails, which is the point.
 */
it('stores a blank barcode as null, so many products may have none', function () {
    $noBarcode = array_merge($this->full, ['barcode' => '']);

    ($this->asRole)('Manager')->post('/inventory/products', $noBarcode);
    ($this->asRole)('Manager')->post('/inventory/products', array_merge($noBarcode, ['sku' => 'HON-MG-250']));

    expect(Product::whereNull('barcode')->count())->toBe(2)
        ->and(Product::where('barcode', '')->count())->toBe(0);
});

/**
 * ⚠⚠ A bare `exists:` rule accepts another business's id. Unlike the sales and
 * purchasing screens - which re-fetch through findOrFail and so fail closed on
 * the global scope - these two ids are written straight onto the row by
 * Product::create(). A foreign unit_of_measure_id would persist and then 500
 * the product index, because the global scope hides the row that
 * `$product->unitOfMeasure->abbreviation` reads.
 */
it('refuses a unit of measure belonging to another business', function () {
    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-catalogue-co']);
    $theirUnit = UnitOfMeasure::create([
        'business_id' => $other->id, 'name' => 'Kilogram', 'abbreviation' => 'kg',
    ]);

    ($this->asRole)('Manager')
        ->post('/inventory/products', array_merge($this->full, ['unit_of_measure_id' => $theirUnit->id]))
        ->assertSessionHasErrors('unit_of_measure_id');

    expect(Product::count())->toBe(0);
});

it('refuses a category belonging to another business', function () {
    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-category-co']);
    $theirCategory = Category::create([
        'business_id' => $other->id, 'name' => 'Spices', 'slug' => 'spices',
    ]);

    ($this->asRole)('Manager')
        ->post('/inventory/products', array_merge($this->full, ['category_id' => $theirCategory->id]))
        ->assertSessionHasErrors('category_id');

    expect(Product::count())->toBe(0);
});

/**
 * ⚠⚠ Brand joins the same family, and it matters more than the other two: a
 * product pointing at another business's brand would quietly corrupt a margin
 * report grouped by brand, rather than merely 500 a page.
 */
it('refuses a brand belonging to another business', function () {
    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-brand-co']);
    $theirBrand = Brand::create([
        'business_id' => $other->id, 'code' => 'BNC', 'name' => 'Theirs', 'slug' => 'theirs',
    ]);

    ($this->asRole)('Manager')
        ->post('/inventory/products', array_merge($this->full, ['brand_id' => $theirBrand->id]))
        ->assertSessionHasErrors('brand_id');

    expect(Product::count())->toBe(0);
});

// ⚠ D-5. A raw material with no brand is a valid product, not a validation error.
it('creates a product with no brand at all', function () {
    ($this->asRole)('Manager')
        ->post('/inventory/products', array_merge($this->full, [
            'sku' => 'SUG-RAW', 'barcode' => null, 'type' => 'raw_material', 'brand_id' => null,
        ]))
        ->assertRedirect();

    expect(Product::where('sku', 'SUG-RAW')->firstOrFail()->brand_id)->toBeNull();
});

it('offers the brands to the screen', function () {
    ($this->asRole)('Manager')
        ->get('/inventory/products')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('brands', 1)
            ->where('brands.0.code', 'LLD'));
});

// ⚠ Viewing the catalogue is open to the business; changing it is not.
it('refuses to let staff without the catalogue permission create a product', function () {
    ($this->asRole)('Staff')->post('/inventory/products', $this->full)->assertForbidden();

    expect(Product::count())->toBe(0);
});

// --- Raw materials kept separate from products (Joy, 27 Sep 2026) -------------

it('lists finished goods on the products screen and hides raw materials', function () {
    Product::create([
        'business_id' => $this->business->id, 'sku' => 'FG-1', 'name' => 'Finished Honey',
        'type' => 'finished_good', 'unit_of_measure_id' => $this->unit->id,
    ]);
    Product::create([
        'business_id' => $this->business->id, 'sku' => 'RM-1', 'name' => 'Raw Beeswax',
        'type' => 'raw_material', 'unit_of_measure_id' => $this->unit->id,
    ]);

    ($this->asRole)('Manager')->get('/inventory/products')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/products/index')
            ->where('kind', 'products')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Finished Honey'));
});

it('lists only raw materials on the raw-materials screen', function () {
    Product::create([
        'business_id' => $this->business->id, 'sku' => 'FG-2', 'name' => 'Finished Honey',
        'type' => 'finished_good', 'unit_of_measure_id' => $this->unit->id,
    ]);
    Product::create([
        'business_id' => $this->business->id, 'sku' => 'RM-2', 'name' => 'Raw Beeswax',
        'type' => 'raw_material', 'unit_of_measure_id' => $this->unit->id,
    ]);

    ($this->asRole)('Manager')->get('/inventory/raw-materials')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/products/index')
            ->where('kind', 'raw')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Raw Beeswax'));
});

// ⚠⚠ Every movement and batch is a quantity in the unit the product had then.
// Changing the unit after stock has moved relabels all of it (10 kg becomes
// 10 pcs), and a switch to service strands the stock.
it('fixes the stock unit and the service choice once stock has moved', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);
    $product = Product::where('sku', 'HON-MG-500')->firstOrFail();

    // Before any stock: the unit may still be corrected.
    ($this->asRole)('Manager')
        ->put("/inventory/products/{$product->id}", array_merge($this->full, ['unit_of_measure_id' => $this->purchaseUnit->id]))
        ->assertSessionHasNoErrors();
    ($this->asRole)('Manager')
        ->put("/inventory/products/{$product->id}", $this->full)
        ->assertSessionHasNoErrors();

    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $user = User::factory()->create();
    app(RecordStockIn::class)->handle($product->fresh(), $warehouse, '10', '100', $user);

    ($this->asRole)('Manager')
        ->put("/inventory/products/{$product->id}", array_merge($this->full, ['unit_of_measure_id' => $this->purchaseUnit->id]))
        ->assertSessionHasErrors('unit_of_measure_id');

    ($this->asRole)('Manager')
        ->put("/inventory/products/{$product->id}", array_merge($this->full, ['type' => 'service']))
        ->assertSessionHasErrors('type');

    expect($product->fresh()->unit_of_measure_id)->toBe($this->unit->id)
        ->and($product->fresh()->type->value)->toBe('finished_good');

    // Finished good to raw material is still a stocked product: allowed.
    ($this->asRole)('Manager')
        ->put("/inventory/products/{$product->id}", array_merge($this->full, ['type' => 'raw_material']))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->type->value)->toBe('raw_material');

    ($this->asRole)('Manager')
        ->get('/inventory/raw-materials')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('products.data.0.has_stock_history', true));
});

// ⚠ 15 Oct 2026: a raw material was switched off by one stray click on
// "Deactivate" and there was no way back from the screen. Reactivation is a
// catalogue change like any other, behind the same permission.
it('reactivates a deactivated product through the screen', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);
    $product = Product::where('sku', 'HON-MG-500')->firstOrFail();

    ($this->asRole)('Manager')->delete("/inventory/products/{$product->id}")->assertRedirect();
    expect($product->refresh()->is_active)->toBeFalse();

    ($this->asRole)('Manager')->post("/inventory/products/{$product->id}/activate")->assertRedirect();
    expect($product->refresh()->is_active)->toBeTrue();
});

it('refuses to let staff without the catalogue permission reactivate a product', function () {
    ($this->asRole)('Manager')->post('/inventory/products', $this->full);
    $product = Product::where('sku', 'HON-MG-500')->firstOrFail();
    $product->update(['is_active' => false]);

    ($this->asRole)('Staff')->post("/inventory/products/{$product->id}/activate")->assertForbidden();
    expect($product->refresh()->is_active)->toBeFalse();
});

// ⚠ Bug hunt 16 Oct 2026 (02 L6): three stock queries per row, and the "low
// stock" pill counted a product with nothing on hand while the Low chip did not.
it('lists stock, cost and the low pill from one query, with the Low chip rule', function () {
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $make = fn (string $sku) => Product::create([
        'business_id' => $this->business->id, 'sku' => $sku, 'name' => "Honey {$sku}",
        'type' => 'finished_good', 'unit_of_measure_id' => $this->unit->id, 'low_stock_threshold' => '5',
    ]);
    $low = $make('FG-LOW');
    $make('FG-NONE');
    $user = User::factory()->create();
    app(RecordStockIn::class)->handle($low, $warehouse, '2', '100', $user);
    app(RecordStockIn::class)->handle($low, $warehouse, '2', '200', $user);

    $manager = ($this->asRole)('Manager');
    $rows = fn () => collect($manager->get('/inventory/products')->assertOk()->original->getData()['page']['props']['products']['data'])->keyBy('sku');

    $rows();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $listed = $rows();
    $few = count(DB::getQueryLog());
    foreach (range(1, 5) as $i) {
        app(RecordStockIn::class)->handle($make("FG-X{$i}"), $warehouse, '9', '50', $user);
    }
    DB::flushQueryLog();
    $rows();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBe($few)
        ->and($listed['FG-LOW']['current_quantity'])->toBe('4')
        ->and((float) $listed['FG-LOW']['average_unit_cost'])->toBe(150.0)
        ->and($listed['FG-LOW']['is_below_threshold'])->toBeTrue()
        ->and($listed['FG-NONE']['current_quantity'])->toBe('0')
        ->and($listed['FG-NONE']['is_below_threshold'])->toBeFalse();
});
