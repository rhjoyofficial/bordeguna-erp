<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The catalogue findings of the owner's practice run (17 Oct 2026).
 *
 * F2/F8: after "New raw material" the list showed 4 rows while the header said
 * "3 raw materials ... 3 active" and the chip "All 3", until a full reload. The
 * popup reloads the page partially; it asked for `products` only, so `counts`
 * stayed as they were. The test reads the keys the popup REALLY asks for from
 * product-dialog.tsx and replays that partial reload, so a popup that forgets
 * `counts` again fails here.
 *
 * F5: Reactivate names the item in its toast.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Practice Co', 'slug' => 'practice-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    foreach (['RM-A' => 'Ghee (bulk)', 'RM-B' => 'Glass jar 500 ml', 'RM-C' => 'Jar lid 500 ml'] as $sku => $name) {
        Product::create([
            'business_id' => $this->business->id, 'sku' => $sku, 'name' => $name,
            'type' => 'raw_material', 'unit_of_measure_id' => $this->unit->id,
        ]);
    }

    $user = User::factory()->create();
    $user->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $user->assignRole('Manager');
    $this->manager = $user;
});

/** The `only` keys of every router.reload in the product popup. */
function productDialogReloadKeys(): array
{
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/js/components/inventory/product-dialog.tsx');
    preg_match_all('/router\.reload\(\{\s*only:\s*\[([^\]]*)\]/', $source, $matches);

    expect($matches[1])->not->toBeEmpty();

    return array_map(
        fn (string $list) => array_map(fn ($key) => trim($key, " '\""), array_filter(explode(',', $list), fn ($key) => trim($key) !== '')),
        $matches[1],
    );
}

it('reloads the header counts and the chips with the list after the popup saves', function () {
    $page = $this->actingAs($this->manager)->get('/inventory/raw-materials')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.all', 3));
    $version = (string) $page->viewData('page')['version'];

    // The popup's own save (JSON), then the popup's own partial reload.
    $this->actingAs($this->manager)->postJson('/inventory/products', [
        'sku' => 'PK-LBL-GHEE-500G',
        'name' => 'Ghee label 500 g',
        'type' => 'raw_material',
        'unit_of_measure_id' => $this->unit->id,
    ])->assertCreated();

    foreach (productDialogReloadKeys() as $keys) {
        expect($keys)->toContain('products')->toContain('counts');

        $props = $this->actingAs($this->manager)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'inventory/products/index',
                'X-Inertia-Partial-Data' => implode(',', $keys),
            ])
            ->get('/inventory/raw-materials')
            ->assertOk()
            ->json('props');

        expect($props['products']['total'])->toBe(4)
            ->and($props['counts']['all'])->toBe(4)
            ->and($props['counts']['inactive'])->toBe(0);
    }
});

it('says which item was switched back on', function () {
    $ghee = Product::where('sku', 'RM-A')->firstOrFail();
    $ghee->update(['is_active' => false]);

    $this->actingAs($this->manager)
        ->from('/inventory/raw-materials')
        ->post(route('inventory.products.activate', $ghee))
        ->assertRedirect('/inventory/raw-materials')
        ->assertSessionHas('success', 'Ghee (bulk) switched back on.');

    // And the toast's text reaches the page that comes back (shared flash prop).
    $this->actingAs($this->manager)->get('/inventory/raw-materials')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.success', 'Ghee (bulk) switched back on.'));

    expect($ghee->fresh()->is_active)->toBeTrue();
});
