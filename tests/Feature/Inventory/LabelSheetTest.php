<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Printing the barcodes that were already on every product.
 *
 * ⚠⚠ Products have carried a `barcode` column since 4 August and NOTHING ever
 * rendered one - data entered that had no way back out of the system. The
 * encoding itself is proven in Code128Test against the symbology; these pin the
 * behaviour around it, and above all that a product WITHOUT a barcode is NAMED
 * rather than silently dropped from the sheet.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Label Co', 'slug' => 'label-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs',
    ]);

    $this->product = fn (string $sku, string $name, ?string $barcode) => Product::create([
        'business_id' => $this->business->id,
        'sku' => $sku,
        'name' => $name,
        'barcode' => $barcode,
        'unit_of_measure_id' => $this->unit->id,
        'type' => ProductType::FinishedGood,
        'is_active' => true,
    ]);
});

it('lists products and counts how many still have no barcode', function () {
    ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');
    ($this->product)('HN-250', 'Honey 250g', null);

    ($this->asRole)('Manager')->get('/inventory/labels')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/labels/index')
            ->has('products', 2)
            ->where('with_barcode', 1)
            ->where('without_barcode', 1)
            ->has('sizes', 3)
        );
});

it('prints one label per unit asked for', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');

    ($this->asRole)('Manager')->get("/inventory/labels/print?size=small&qty[{$oil->id}]=3")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/labels/print')
            ->has('labels', 3)
            ->where('labels.0.name', 'Mustard Oil 500ml')
            ->where('labels.0.barcode', '8901234567890')
            ->has('skipped', 0)
        );
});

/**
 * ⚠⚠ THE ONE THAT MATTERS. Asking for forty labels and getting thirty, with no
 * explanation, is how a print run goes wrong quietly. The product is named.
 */
it('names a product it could not print instead of dropping it', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');
    $honey = ($this->product)('HN-250', 'Honey 250g', null);

    ($this->asRole)('Manager')->get("/inventory/labels/print?size=small&qty[{$oil->id}]=2&qty[{$honey->id}]=5")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('labels', 2)
            ->has('skipped', 1)
            ->where('skipped.0', 'HN-250 - Honey 250g')
        );
});

it('ignores a quantity of nothing', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');

    ($this->asRole)('Manager')->get("/inventory/labels/print?size=small&qty[{$oil->id}]=0")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('labels', 0));
});

/** ⚠ 500 a product, so a stray keypress cannot ask for a hundred thousand. */
it('refuses an absurd quantity', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');

    ($this->asRole)('Manager')->get("/inventory/labels/print?size=small&qty[{$oil->id}]=99999")
        ->assertSessionHasErrors();
});

it('falls back to the small label rather than failing on an unknown size', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');

    ($this->asRole)('Manager')->get("/inventory/labels/print?size=enormous&qty[{$oil->id}]=1")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('size.w', 38));
});

it('puts a real barcode svg on the label', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');

    $response = ($this->asRole)('Manager')->get("/inventory/labels/print?size=small&qty[{$oil->id}]=1");

    $svg = $response->viewData('page')['props']['labels'][0]['svg'];

    expect($svg)->toContain('<svg')
        ->and($svg)->toContain('<rect')
        // The small label is 38mm wide with 3mm of padding each side.
        ->and($svg)->toContain('width="32mm"');
});

it('keeps someone who may not manage the catalogue away from the labels', function () {
    ($this->asRole)('Staff')->get('/inventory/labels')->assertForbidden();
    ($this->asRole)('Staff')->get('/inventory/labels/print?qty[1]=1')->assertForbidden();
});

// ⚠ Bug hunt 16 Oct 2026: one barcode a label cannot encode threw, and no
// label printed at all.
it('prints the rest of the sheet and names a product whose barcode a label cannot print', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', '8901234567890');
    $ghee = ($this->product)('GH-400', 'Ghee 400g', '৮৯০১২৩');

    ($this->asRole)('Manager')->get('/inventory/labels/print?'.http_build_query(['qty' => [$oil->id => 2, $ghee->id => 1]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('labels', 2)
            ->where('skipped.0', fn ($line) => str_contains($line, 'GH-400')));
});

it('refuses a barcode a label cannot print when the product is saved', function () {
    $oil = ($this->product)('MO-500', 'Mustard Oil 500ml', null);

    ($this->asRole)('Owner')->put("/inventory/products/{$oil->id}", [
        'sku' => 'MO-500', 'name' => 'Mustard Oil 500ml', 'barcode' => "8901\u{00A0}234",
        'unit_of_measure_id' => $this->unit->id, 'type' => 'finished_good',
    ])->assertSessionHasErrors('barcode');
});

// ⚠ Bug hunt 16 Oct 2026 (02 L1): the route asked for the import permission
// and the controller for another, so a role with import alone got a 403.
it('opens the labels for a role that holds the import permission alone', function () {
    Permission::findOrCreate('inventory.catalogue.import', 'web');
    Role::create(['name' => 'Importer', 'guard_name' => 'web', 'business_id' => $this->business->id])
        ->givePermissionTo('inventory.catalogue.import');

    ($this->asRole)('Importer')->get('/inventory/labels')->assertOk();
});
