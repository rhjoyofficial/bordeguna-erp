<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductFamily;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B22 (24 Oct 2026; R10, Q9 = A): product families.
 * "Miniket Rice" groups 1 kg, 25 kg and 50 kg, each its own product with its
 * own SKU, stock, batches and prices; a single product works as before, and
 * "Product can be easily changeable to single variants to multiple variants."
 *
 * ⚠⚠ The invariant under every test: joining or leaving a family moves no
 * stock, batch or price. Only the product's two family columns change.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 11:00:00');
    // The pages are new; the built assets may predate them.
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Rice Co', 'slug' => 'rice-co-b22']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->pcs = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $this->bag = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Bag', 'abbreviation' => 'bag']);
    $this->category = Category::create(['business_id' => $this->business->id, 'name' => 'Rice', 'slug' => 'rice']);
    $this->brand = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic', 'slug' => 'bionic']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);

    $this->user = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->owner = ($this->user)('Owner');
    $this->as = fn (?User $user = null) => $this->actingAs($user ?? $this->owner)->withSession(['current_business_id' => $this->business->id]);

    $this->oneKg = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $this->pcs->id,
        'category_id' => $this->category->id, 'brand_id' => $this->brand->id,
        'sku' => 'BNC-MINIKET-1KG', 'name' => 'Miniket Rice 1 kg', 'type' => ProductType::FinishedGood,
        'tracks_expiry' => true, 'shelf_life_days' => 365, 'mrp' => '95',
    ]);

    app(RecordStockIn::class)->handle($this->oneKg, $this->warehouse, '10', '80', $this->owner);
    $this->retail = PriceGroup::query()->where('code', 'RETAIL')->first()
        ?? PriceGroup::create(['business_id' => $this->business->id, 'code' => 'RETAIL', 'name' => 'Retail', 'price_basis' => PriceGroup::BASIS_LIST, 'is_active' => true]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->oneKg->id, 'price_group_id' => $this->retail->id, 'unit_price' => '92', 'effective_from' => '2026-01-01']);

    $this->makeFamily = fn (array $over = []) => ($this->as)()->post("/inventory/products/{$this->oneKg->id}/make-family", array_merge([
        'name' => 'Miniket Rice', 'sku' => 'BNC-MINIKET', 'attribute_name' => 'Packaging size', 'value' => '1 kg',
    ], $over));

    // What must never move: the stock and the prices behind every size.
    $this->snapshot = fn () => [
        'batches' => StockBatch::query()->orderBy('id')->get(['id', 'product_id', 'current_quantity', 'unit_cost'])->toArray(),
        'prices' => ProductPrice::query()->orderBy('id')->get(['id', 'product_id', 'unit_price'])->toArray(),
    ];
});

afterEach(fn () => Carbon::setTestNow());

it('makes a single product the first size of a new family, moving nothing', function () {
    $before = ($this->snapshot)();

    ($this->makeFamily)()->assertRedirect()->assertSessionHas('success');

    $family = ProductFamily::sole();
    expect($family->name)->toBe('Miniket Rice')
        ->and($family->sku)->toBe('BNC-MINIKET')
        ->and($family->category_id)->toBe($this->category->id)
        ->and($family->brand_id)->toBe($this->brand->id)
        ->and($this->oneKg->fresh()->product_family_id)->toBe($family->id)
        ->and($this->oneKg->fresh()->family_attribute_value)->toBe('1 kg')
        ->and($this->oneKg->fresh()->pickerName())->toBe('Miniket Rice - 1 kg')
        ->and(($this->snapshot)())->toBe($before);
});

it('refuses a second Make it a family on a product already in one (double click)', function () {
    ($this->makeFamily)()->assertSessionHas('success');
    ($this->makeFamily)(['sku' => 'BNC-MINIKET-B'])->assertSessionHasErrors('value');

    expect(ProductFamily::count())->toBe(1);
});

it('refuses a family SKU another live family uses', function () {
    ($this->makeFamily)();
    $other = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->pcs->id, 'sku' => 'NZ-1', 'name' => 'Nazirshail 1 kg', 'type' => ProductType::FinishedGood]);

    ($this->as)()->post("/inventory/products/{$other->id}/make-family", ['name' => 'Nazirshail', 'sku' => 'bnc-miniket', 'value' => '1 kg'])
        ->assertSessionHasErrors('sku');
});

it('adds a size pre-filled from the family with its own SKU, and nothing else moves', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();
    $before = ($this->snapshot)();

    ($this->as)()->post("/inventory/product-families/{$family->id}/sizes", [
        'value' => '25 kg', 'sku' => ProductFamily::proposeSizeSku($family->sku, '25 kg'), 'name' => 'Miniket Rice 25 kg Bosta', 'mrp' => '2100',
    ])->assertSessionHas('success');

    $bosta = Product::where('sku', 'BNC-MINIKET-25KG')->firstOrFail();

    expect($bosta->product_family_id)->toBe($family->id)
        ->and($bosta->family_attribute_value)->toBe('25 kg')
        ->and($bosta->category_id)->toBe($this->category->id)
        ->and($bosta->brand_id)->toBe($this->brand->id)
        ->and($bosta->unit_of_measure_id)->toBe($this->pcs->id)
        ->and($bosta->type)->toBe(ProductType::FinishedGood)
        ->and($bosta->tracks_expiry)->toBeTrue()
        ->and($bosta->shelf_life_days)->toBe(365)
        ->and($bosta->can_be_sold)->toBeTrue()
        ->and((string) $bosta->mrp)->toBe('2100.0000')
        ->and($bosta->batches()->count())->toBe(0)
        ->and(($this->snapshot)())->toBe($before);
});

it('refuses a size the family already has, ignoring case and spaces, and a taken SKU', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();

    ($this->as)()->post("/inventory/product-families/{$family->id}/sizes", ['value' => '1KG', 'sku' => 'X-1', 'name' => 'Again'])
        ->assertSessionHasErrors('value');
    ($this->as)()->post("/inventory/product-families/{$family->id}/sizes", ['value' => '5 kg', 'sku' => 'bnc-miniket-1kg', 'name' => 'Clash'])
        ->assertSessionHasErrors('sku');

    expect(Product::count())->toBe(1);
});

it('asks for the unit and the kind when an empty family gets its first size', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();
    ($this->as)()->post("/inventory/products/{$this->oneKg->id}/leave-family")->assertSessionHas('success');

    ($this->as)()->post("/inventory/product-families/{$family->id}/sizes", ['value' => '50 kg', 'sku' => 'BNC-MINIKET-50KG', 'name' => 'Miniket Rice 50 kg'])
        ->assertSessionHasErrors('unit_of_measure_id');
    ($this->as)()->post("/inventory/product-families/{$family->id}/sizes", ['value' => '50 kg', 'sku' => 'BNC-MINIKET-50KG', 'name' => 'Miniket Rice 50 kg', 'unit_of_measure_id' => $this->bag->id, 'type' => 'finished_good'])
        ->assertSessionHas('success');

    expect(Product::where('sku', 'BNC-MINIKET-50KG')->value('unit_of_measure_id'))->toBe($this->bag->id);
});

it('lets an existing single product join a family, of the same kind only', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();
    $fifty = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->bag->id, 'sku' => 'MR-50', 'name' => 'Miniket 50kg bosta', 'type' => ProductType::FinishedGood]);
    $paddy = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->bag->id, 'sku' => 'RM-PADDY', 'name' => 'Paddy', 'type' => ProductType::RawMaterial]);

    ($this->as)()->post("/inventory/products/{$paddy->id}/join-family", ['product_family_id' => $family->id, 'value' => '40 kg'])
        ->assertSessionHasErrors('product_family_id');
    ($this->as)()->post("/inventory/products/{$fifty->id}/join-family", ['product_family_id' => $family->id, 'value' => '50 kg'])
        ->assertSessionHas('success');

    expect($fifty->fresh()->product_family_id)->toBe($family->id)
        ->and($fifty->fresh()->sku)->toBe('MR-50')
        ->and($paddy->fresh()->product_family_id)->toBeNull();
});

it('Leave family turns a size back into a single product with its stock and prices', function () {
    ($this->makeFamily)();
    $before = ($this->snapshot)();

    ($this->as)()->post("/inventory/products/{$this->oneKg->id}/leave-family")->assertSessionHas('success');
    ($this->as)()->post("/inventory/products/{$this->oneKg->id}/leave-family")->assertSessionHasErrors('product');

    expect($this->oneKg->fresh()->product_family_id)->toBeNull()
        ->and($this->oneKg->fresh()->family_attribute_value)->toBeNull()
        ->and($this->oneKg->fresh()->pickerName())->toBe('Miniket Rice 1 kg')
        ->and(($this->snapshot)())->toBe($before);
});

it('deletes an unused family to the Recycle bin with a reason, and refuses one with sizes (Q5)', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();

    ($this->as)()->delete("/inventory/product-families/{$family->id}", [])->assertSessionHasErrors('reason_text');
    ($this->as)()->delete("/inventory/product-families/{$family->id}", ['reason_text' => 'Made by mistake'])
        ->assertSessionHasErrors('reason_text');
    expect(ProductFamily::count())->toBe(1);

    ($this->as)()->post("/inventory/products/{$this->oneKg->id}/leave-family");
    ($this->as)()->delete("/inventory/product-families/{$family->id}", ['reason_text' => 'Made by mistake'])
        ->assertRedirect('/inventory/product-families');

    expect(ProductFamily::count())->toBe(0)
        ->and(ProductFamily::onlyTrashed()->sole()->delete_reason)->toBe('Made by mistake');

    // Only the Owner restores, from Settings > Recycle bin.
    ($this->as)()->post("/settings/recycle-bin/product_family/{$family->id}/restore", ['reason_text' => 'Needed again'])->assertSessionHas('success');
    expect(ProductFamily::count())->toBe(1);
});

it('edits the family only: its sizes keep their own category, brand and SKU', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();
    $other = Category::create(['business_id' => $this->business->id, 'name' => 'Grains', 'slug' => 'grains']);

    ($this->as)()->put("/inventory/product-families/{$family->id}", ['name' => 'Miniket', 'sku' => 'mnk', 'category_id' => $other->id, 'brand_id' => null, 'attribute_name' => ''])
        ->assertSessionHas('success');

    expect($family->fresh()->only(['name', 'sku', 'category_id', 'attribute_name']))->toBe(['name' => 'Miniket', 'sku' => 'MNK', 'category_id' => $other->id, 'attribute_name' => 'Packaging size'])
        ->and($this->oneKg->fresh()->category_id)->toBe($this->category->id)
        ->and($this->oneKg->fresh()->sku)->toBe('BNC-MINIKET-1KG');
});

it('keeps every change behind catalogue management and the pages behind stock reports', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();
    $staff = ($this->user)('Staff');

    ($this->as)($staff)->post("/inventory/product-families/{$family->id}/sizes", ['value' => '5 kg', 'sku' => 'X', 'name' => 'X'])->assertForbidden();
    ($this->as)($staff)->post("/inventory/products/{$this->oneKg->id}/leave-family")->assertForbidden();
    ($this->as)($staff)->put("/inventory/product-families/{$family->id}", ['name' => 'X', 'sku' => 'X'])->assertForbidden();
    ($this->as)($staff)->delete("/inventory/product-families/{$family->id}", ['reason_text' => 'Not needed'])->assertForbidden();
    ($this->as)($staff)->get("/inventory/product-families/{$family->id}")->assertOk();
    ($this->as)($staff)->get('/inventory/product-families')->assertOk();
});

it('never lets one business see or change another business\'s family', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co-b22']);
    app(BusinessContext::class)->set($other->id);
    $theirs = Product::create(['business_id' => $other->id, 'unit_of_measure_id' => UnitOfMeasure::create(['business_id' => $other->id, 'name' => 'Piece', 'abbreviation' => 'pcs'])->id, 'sku' => 'T-1', 'name' => 'Theirs', 'type' => ProductType::FinishedGood]);
    app(BusinessContext::class)->set($this->business->id);

    ($this->as)()->post("/inventory/products/{$theirs->id}/join-family", ['product_family_id' => $family->id, 'value' => '2 kg'])->assertNotFound();
    expect(Product::withoutGlobalScopes()->find($theirs->id)->product_family_id)->toBeNull();
});

it('shows the family page with every size, its stock, value and price by group', function () {
    ($this->makeFamily)();
    $family = ProductFamily::sole();

    ($this->as)()->get("/inventory/product-families/{$family->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/product-families/show')
            ->where('family.name', 'Miniket Rice')
            ->where('sizes.0.value', '1 kg')
            ->where('sizes.0.label', 'Miniket Rice - 1 kg')
            ->where('sizes.0.on_hand', '10')
            ->where('sizes.0.stock_value', '800.00')
            ->where("sizes.0.prices.{$this->retail->id}", '92.00')
            ->where('totals.on_hand', '10 pcs')
            ->where('totals.value', '800.00')
            ->where('can.delete', false));

    ($this->as)()->get('/inventory/product-families')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inventory/product-families/index')
            ->where('families.data.0.sizes', 1)
            ->where('families.data.0.stock_value', '800.00'));
});

it('labels sizes for the pickers in one query', function () {
    ($this->makeFamily)();
    $single = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->pcs->id, 'sku' => 'S-1', 'name' => 'Chinigura', 'type' => ProductType::FinishedGood]);

    expect(Product::familyLabels([$this->oneKg->id, $single->id]))->toBe([$this->oneKg->id => 'Miniket Rice - 1 kg']);
});
