<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\database\seeders\InventoryUnitsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\InventorySetting;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\Models\PriceGroup;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A fresh business works on day one (21 Sep 2026 audit, A.1 / B.5 / B.9).
 *
 * ⚠⚠ Production was set up by hand and every one of these was a dead end
 * there: no units (the only seeder that planted any skips production), no way
 * to choose the default warehouse, an opening-stock screen that told a business
 * with NO warehouses that every warehouse was done, an approval limit with no
 * screen so the Owner's own corrections queued behind themselves, and master
 * data screens offering Staff buttons that answered 403.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Day One Co', 'slug' => 'day-one-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->userAs = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->userAs)('Owner');
    $this->staff = ($this->userAs)('Staff');
    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
});

// ── Units ────────────────────────────────────────────────────────────────

it('plants the everyday units in every business, and only once', function () {
    $second = Business::create(['name' => 'Second Co', 'slug' => 'second-co']);

    (new InventoryUnitsSeeder)->run();
    (new InventoryUnitsSeeder)->run();

    foreach ([$this->business->id, $second->id] as $businessId) {
        expect(UnitOfMeasure::withoutGlobalScopes()->where('business_id', $businessId)->orderBy('id')->pluck('abbreviation')->all())
            ->toBe(['pcs', 'kg', 'g', 'L', 'mL', 'box', 'jar', 'pack', 'bottle', 'packet']);
    }

    // ⚠ It puts the caller back in the business it found it in.
    expect(app(BusinessContext::class)->id())->toBe($this->business->id);
});

it('never adds a second litre or piece to a business that typed its own', function () {
    UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'Ltr']);
    UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilo', 'abbreviation' => 'KG']);

    (new InventoryUnitsSeeder)->run();

    $units = UnitOfMeasure::query()->orderBy('id')->get();

    expect($units->pluck('abbreviation')->all())->toBe(['Ltr', 'pc', 'KG', 'g', 'mL', 'box', 'jar', 'pack', 'bottle', 'packet'])
        // ⚠ Additive only: the business's own units are untouched.
        ->and($units->first()->name)->toBe('Litre');
});

it('runs the units and price-group seeders from DatabaseSeeder, which is how production gets them', function () {
    $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

    expect($source)->toContain('$this->call(InventoryUnitsSeeder::class);')
        ->and($source)->toContain('$this->call(PriceGroupSeeder::class);')
        // Units BEFORE the demo seeder, so both agree on the same rows.
        ->and(strpos($source, '$this->call(InventoryUnitsSeeder::class);'))->toBeLessThan(strpos($source, '$this->call(InventoryDemoDataSeeder::class);'));

    (new PriceGroupSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    expect(PriceGroup::query()->pluck('code')->sort()->values()->all())->toBe(['CORPORATE', 'DEALER', 'DISTRIBUTOR', 'OWNER', 'RETAIL', 'SHOP']);
});

it('lets a unit be renamed, and refuses a repeated abbreviation in words instead of a 500', function () {
    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilgoram', 'abbreviation' => 'kg']);
    UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Gram', 'abbreviation' => 'g']);

    ($this->as)($this->owner)->put(route('inventory.units.update', $kg), ['name' => 'Kilogram', 'abbreviation' => 'kg'])
        ->assertSessionHasNoErrors();
    expect($kg->fresh()->name)->toBe('Kilogram');

    ($this->as)($this->owner)->put(route('inventory.units.update', $kg), ['name' => 'Kilogram', 'abbreviation' => 'g'])
        ->assertSessionHasErrors('abbreviation');
    ($this->as)($this->owner)->post(route('inventory.units.store'), ['name' => 'Kilo again', 'abbreviation' => 'kg'])
        ->assertSessionHasErrors('abbreviation');

    // Another business's "kg" is no clash.
    $other = Business::create(['name' => 'Other', 'slug' => 'other']);
    UnitOfMeasure::create(['business_id' => $other->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    ($this->as)($this->owner)->post(route('inventory.units.store'), ['name' => 'Pieces', 'abbreviation' => 'pcs'])
        ->assertSessionHasNoErrors();

    // Staff read the list (with how many products use each unit) and cannot write it.
    ($this->as)($this->staff)->get(route('inventory.units.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('units.0.products_count', 0)->where('inventoryAbilities.manageCatalog', false));
    ($this->as)($this->staff)->put(route('inventory.units.update', $kg), ['name' => 'x', 'abbreviation' => 'x'])->assertForbidden();
});

// Add and edit are one popup over the units list since 14 Oct 2026 (Add was a
// form above it and Edit turned the row into inputs). The page carries what the
// popup draws, and the popup saves through the same routes.
it('carries what the unit popup needs on the list, and saves through the same routes', function () {
    ($this->as)($this->owner)->post(route('inventory.units.store'), ['name' => 'Dozen', 'abbreviation' => 'dz'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $dozen = UnitOfMeasure::where('abbreviation', 'dz')->sole();

    ($this->as)($this->owner)->put(route('inventory.units.update', $dozen), ['name' => 'Dozens', 'abbreviation' => 'doz'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    ($this->as)($this->owner)->get(route('inventory.units.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/units/index')
            ->where('inventoryAbilities.manageCatalog', true)
            ->where('units', fn ($units) => collect($units)->contains(
                fn ($unit) => $unit['id'] === $dozen->id && $unit['name'] === 'Dozens' && $unit['abbreviation'] === 'doz')));
});

// ── The default warehouse ────────────────────────────────────────────────

it('makes the first warehouse the default, and lets the owner move it', function () {
    ($this->as)($this->owner)->post(route('inventory.warehouses.store'), ['name' => 'Mirpur', 'code' => 'MIR'])->assertSessionHasNoErrors();
    ($this->as)($this->owner)->post(route('inventory.warehouses.store'), ['name' => 'Adabor', 'code' => 'ADB'])->assertSessionHasNoErrors();

    $mirpur = Warehouse::where('code', 'MIR')->sole();
    $adabor = Warehouse::where('code', 'ADB')->sole();

    expect($mirpur->is_default)->toBeTrue()->and($adabor->is_default)->toBeFalse();

    ($this->as)($this->owner)->patch(route('inventory.warehouses.make-default', $adabor))
        ->assertSessionHas('success', 'Adabor is now the default warehouse.');

    expect($adabor->fresh()->is_default)->toBeTrue()
        ->and($mirpur->fresh()->is_default)->toBeFalse()
        ->and(Warehouse::where('is_default', true)->count())->toBe(1);

    // ⚠ Logged like any other edit of the flag.
    expect(Activity::query()->where('subject_type', $mirpur->getMorphClass())->where('subject_id', $mirpur->id)->where('event', 'updated')->exists())->toBeTrue();
});

it('refuses to make a switched-off warehouse the default, and refuses Staff', function () {
    $main = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    $off = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Old', 'code' => 'OLD', 'is_active' => false]);

    ($this->as)($this->owner)->patch(route('inventory.warehouses.make-default', $off))->assertSessionHas('error');
    ($this->as)($this->staff)->patch(route('inventory.warehouses.make-default', $main))->assertForbidden();

    expect($main->fresh()->is_default)->toBeTrue()->and($off->fresh()->is_default)->toBeFalse();
});

it('refuses a repeated warehouse code in words instead of a 500', function () {
    Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);

    ($this->as)($this->owner)->post(route('inventory.warehouses.store'), ['name' => 'Another', 'code' => 'MAIN'])
        ->assertSessionHasErrors('code');
});

// ── Opening stock on a fresh business ────────────────────────────────────

it('tells a business with no warehouse to add one, instead of saying every warehouse is done', function () {
    ($this->as)($this->owner)->get(route('inventory.opening-stock.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/opening-stock/index')
            ->where('hasWarehouses', false)
            ->where('canManageWarehouses', true)
            ->has('warehouses', 0));
});

it('opens opening stock on the default warehouse, with each product\'s unit', function () {
    Warehouse::create(['business_id' => $this->business->id, 'name' => 'Adabor', 'code' => 'ADB']);
    $default = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mirpur', 'code' => 'MIR', 'is_default' => true]);
    $jar = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Jar', 'abbreviation' => 'jar']);
    Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $jar->id,
        'sku' => 'HONEY-1', 'name' => 'Honey', 'type' => ProductType::FinishedGood,
    ]);

    ($this->as)($this->owner)->get(route('inventory.opening-stock.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('hasWarehouses', true)
            ->where('defaultWarehouseId', $default->id)
            ->where('countSheetDefaultId', $default->id)
            ->where('products.0.unit', 'jar'));
});

// ── The approval limit ───────────────────────────────────────────────────

it('shows the approval limit empty until the owner sets one, and never invents one', function () {
    ($this->as)($this->owner)->get(route('inventory.settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/settings/index')
            ->where('adjustment_approval_threshold', null));

    // ⚠ Opening the screen writes nothing.
    expect(InventorySetting::query()->count())->toBe(0);

    ($this->as)($this->owner)->put(route('inventory.settings.update'), ['adjustment_approval_threshold' => '2500'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Approval limit saved.');

    expect((float) InventorySetting::query()->value('adjustment_approval_threshold'))->toBe(2500.0)
        ->and(InventorySetting::forCurrentBusiness()->allowsSelfApprovalOf('2500'))->toBeTrue()
        ->and(InventorySetting::forCurrentBusiness()->allowsSelfApprovalOf('2500.01'))->toBeFalse();

    // Who moved the limit, from what, to what.
    $logged = Activity::query()->where('subject_type', (new InventorySetting)->getMorphClass())->latest('id')->first();
    expect($logged)->not->toBeNull()
        ->and($logged->causer_id)->toBe($this->owner->id);

    // Emptying it is a real answer: back to "everything waits".
    ($this->as)($this->owner)->put(route('inventory.settings.update'), ['adjustment_approval_threshold' => ''])
        ->assertSessionHas('success', 'Approval limit cleared. Every stock correction waits for approval.');

    expect(InventorySetting::query()->value('adjustment_approval_threshold'))->toBeNull()
        ->and(InventorySetting::forCurrentBusiness()->allowsSelfApprovalOf('1'))->toBeFalse();
});

it('refuses a negative or non-numeric limit, and refuses Staff the screen', function () {
    ($this->as)($this->owner)->put(route('inventory.settings.update'), ['adjustment_approval_threshold' => '-5'])
        ->assertSessionHasErrors('adjustment_approval_threshold');
    ($this->as)($this->owner)->put(route('inventory.settings.update'), ['adjustment_approval_threshold' => 'lots'])
        ->assertSessionHasErrors(['adjustment_approval_threshold' => 'Enter an amount in taka, or leave it empty.']);

    ($this->as)($this->staff)->get(route('inventory.settings.edit'))->assertForbidden();
    ($this->as)($this->staff)->put(route('inventory.settings.update'), ['adjustment_approval_threshold' => '999999'])->assertForbidden();

    expect(InventorySetting::query()->value('adjustment_approval_threshold'))->toBeNull();
});

// ── What each person is offered ──────────────────────────────────────────

it('shares what each person may write, so Staff stop being offered 403s', function () {
    ($this->as)($this->owner)->get(route('inventory.warehouses.index'))
        ->assertInertia(fn ($page) => $page
            ->where('inventoryAbilities.manageCatalog', true)
            ->where('inventoryAbilities.adjustStock', true)
            ->where('inventoryAbilities.approveStock', true)
            ->where('inventoryAbilities.manageProduction', true));

    ($this->as)($this->staff)->get(route('inventory.warehouses.index'))
        ->assertInertia(fn ($page) => $page
            ->where('inventoryAbilities.manageCatalog', false)
            ->where('inventoryAbilities.adjustStock', false)
            ->where('inventoryAbilities.requestStock', true)
            ->where('inventoryAbilities.approveStock', false)
            ->where('inventoryAbilities.manageProduction', false));
});

it('gates the menu rows that answer 403 on the same flags', function () {
    // ⚠ File-based, like EveryModuleIsReachableTest: the navigation is a React
    // component, and reading its source is the honest check. Since the
    // Inventory redesign (15 Oct 2026) the sidebar rows and the tab rows are
    // both built from inventory-sections.ts, so that is where the gates live.
    $sections = file_get_contents(resource_path('js/components/inventory/inventory-sections.ts'));

    expect($sections)->toContain("url: '/inventory/production-runs', can: production")
        ->and($sections)->toContain("url: '/inventory/stock-requests', can: approve")
        ->and($sections)->toContain("url: '/inventory/unit-conversions', can: catalog")
        ->and($sections)->toContain("url: '/inventory/settings', can: approve")
        ->and($sections)->toContain('f.inventoryAbilities?.manageProduction')
        ->and($sections)->toContain('f.inventoryAbilities?.approveStock')
        ->and($sections)->toContain('f.inventoryAbilities?.manageCatalog');
});

it('offers New, Edit and Deactivate on the products page only to whoever may manage the catalogue (INV-005)', function () {
    ($this->as)($this->staff)->get(route('inventory.products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('inventoryAbilities.manageCatalog', false));
    ($this->as)($this->owner)->get(route('inventory.products.index'))
        ->assertInertia(fn ($page) => $page->where('inventoryAbilities.manageCatalog', true));

    // File-based, as above: the buttons live in the React page.
    $page = file_get_contents(resource_path('js/pages/inventory/products/index.tsx'));
    // (17 Oct 2026: the button also shows a spinner while the list reloads, practice run F6.)
    expect($page)->toMatch('/\{manageCatalog && \(\s*<Button onClick=\{openNew\} disabled=\{reloading\}/')
        ->and($page)->toContain('{manageCatalog && product.is_active && (')
        ->and($page)->toMatch('/\{manageCatalog && \(\s*<Button variant="ghost" size="sm" onClick=\{\(\) => startEdit\(product\)\}>/');

    // The dialog says a refusal is a refusal, not a fault to retry.
    expect(file_get_contents(resource_path('js/components/inventory/product-dialog.tsx')))->toContain('saved.status === 403');
});
