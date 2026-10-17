<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⚠⚠ WHY THIS FILE EXISTS. `ProductUnitConversion::record()` has been built,
 * tested and deployed since 9 August and had NO CALLER outside its own tests -
 * no route, no controller, no screen. `products.purchase_unit_id` is
 * deliberately not fillable so that method is the only way in, and nothing led
 * to it. The fourth finished-and-unreachable engine on this project.
 *
 * The mustard oil numbers below are the real ones: 1 kg is 1.0905 L and 1 L is
 * 0.917 kg. They are not each other's obvious inverse, which is exactly why both
 * are typed and checked rather than one being calculated.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Conv Co', 'slug' => 'unit-conversion-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->litre = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Litre', 'abbreviation' => 'L']);
    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);

    $this->oil = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->litre->id,
        'sku' => 'RM-OIL-MUS',
        'name' => 'Mustard Oil (খাঁটি সরিষার তেল) - bulk',
        'type' => 'raw_material',
        'is_active' => true,
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->declare = fn (array $over = []) => ($this->asRole)('Owner')->post(route('inventory.unit-conversions.store'), array_merge([
        'product_id' => $this->oil->id,
        'from_unit_id' => $this->kg->id,
        'stock_units_per_purchase_unit' => '1.0905',
        'purchase_units_per_stock_unit' => '0.917',
        'effective_from' => '2026-09-01',
        'source' => 'Razzak Oil Mill delivery note',
    ], $over));
});

it('is reachable and records a conversion', function () {
    ($this->asRole)('Owner')->get(route('inventory.unit-conversions.index'))->assertOk();

    ($this->declare)()->assertRedirect();

    $conversion = ProductUnitConversion::first();

    expect($conversion)->not->toBeNull()
        ->and((float) $conversion->stock_units_per_purchase_unit)->toBe(1.0905)
        ->and($conversion->from_unit_id)->toBe($this->kg->id)
        ->and($conversion->to_unit_id)->toBe($this->litre->id)
        // ⚠ Recording the conversion is what sets the purchase unit. Setting the
        // unit without a factor would leave every receipt throwing for ever with
        // no screen able to undo it, which is why they are declared together.
        ->and($this->oil->fresh()->purchase_unit_id)->toBe($this->kg->id);
});

it('refuses two numbers that are not reciprocals, which is an inverted factor', function () {
    // ⚠⚠ THE ONE THAT MATTERS. 0.917 entered where 1.0905 belongs is 21% wrong,
    // permanently, and every ledger still balances. Asking for both directions
    // is the only cheap way to catch it.
    ($this->declare)([
        'stock_units_per_purchase_unit' => '0.917',
        'purchase_units_per_stock_unit' => '0.917',
    ])->assertSessionHasErrors('stock_units_per_purchase_unit');

    expect(ProductUnitConversion::count())->toBe(0)
        ->and($this->oil->fresh()->purchase_unit_id)->toBeNull();
});

it('refuses a negative factor even though two negatives multiply to one', function () {
    ($this->declare)([
        'stock_units_per_purchase_unit' => '-1.0905',
        'purchase_units_per_stock_unit' => '-0.917',
    ])->assertSessionHasErrors();

    expect(ProductUnitConversion::count())->toBe(0);
});

it('refuses to convert a product into a unit it is not stocked in', function () {
    // Buying in litres a product stocked in litres converts nothing.
    ($this->declare)(['from_unit_id' => $this->litre->id])
        ->assertSessionHasErrors('stock_units_per_purchase_unit');

    expect(ProductUnitConversion::count())->toBe(0);
});

it('refuses to re-point a product at a different purchase unit', function () {
    // ⚠ Existing batches froze a factor describing the old unit. Changing it is
    // not a conversion, it is a new product.
    ($this->declare)()->assertRedirect();

    $gram = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Gram', 'abbreviation' => 'g']);

    ($this->declare)([
        'from_unit_id' => $gram->id,
        'stock_units_per_purchase_unit' => '0.0010905',
        'purchase_units_per_stock_unit' => '917',
    ])->assertSessionHasErrors('stock_units_per_purchase_unit');

    expect(ProductUnitConversion::count())->toBe(1);
});

it('takes a new rate for the same unit, so a changed factor never rewrites old batches', function () {
    ($this->declare)()->assertRedirect();

    ($this->declare)([
        'stock_units_per_purchase_unit' => '1.1',
        'purchase_units_per_stock_unit' => '0.909090909',
        'effective_from' => '2026-12-01',
        'source' => 'new supplier, different density',
    ])->assertRedirect();

    expect(ProductUnitConversion::count())->toBe(2);
});

it('requires a source, because a factor nobody can defend is a factor nobody should trust', function () {
    ($this->declare)(['source' => ''])->assertSessionHasErrors('source');

    expect(ProductUnitConversion::count())->toBe(0);
});

it('is closed to Staff', function () {
    ($this->asRole)('Staff')->get(route('inventory.unit-conversions.index'))->assertForbidden();

    ($this->asRole)('Staff')->post(route('inventory.unit-conversions.store'), [
        'product_id' => $this->oil->id,
        'from_unit_id' => $this->kg->id,
        'stock_units_per_purchase_unit' => '1.0905',
        'purchase_units_per_stock_unit' => '0.917',
        'effective_from' => '2026-09-01',
        'source' => 'should not land',
    ])->assertForbidden();

    expect(ProductUnitConversion::count())->toBe(0);
});

// ⚠ Bug hunt 16 Oct 2026: a PO line keeps no unit, and the first conversion
// switches how every later receipt is read, so 100 ordered as litres would be
// received as 100 kg.
it('refuses a first conversion while an open purchase order still waits for the product', function () {
    $owner = User::factory()->create();
    $owner->businesses()->attach($this->business->id);
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: Supplier::create(['business_id' => $this->business->id, 'name' => 'Razzak Oil Mill']),
        warehouse: Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']),
        lines: [['product' => $this->oil, 'quantity' => '100', 'unit_cost' => '300']],
        createdBy: $owner,
    );

    ($this->declare)()->assertSessionHasErrors('stock_units_per_purchase_unit');

    expect(session('errors')->first('stock_units_per_purchase_unit'))->toContain($order->po_number)
        ->and(ProductUnitConversion::count())->toBe(0)
        ->and($this->oil->fresh()->purchase_unit_id)->toBeNull();
});

it('refuses a first conversion that takes effect in the future', function () {
    ($this->declare)(['effective_from' => now()->addDays(5)->toDateString()])->assertSessionHasErrors('stock_units_per_purchase_unit');

    expect(ProductUnitConversion::count())->toBe(0)
        ->and($this->oil->fresh()->purchase_unit_id)->toBeNull();
});

// ⭐ Phase 1 Part B (17 Oct 2026): a RECIPE conversion through the same screen.
// One direction only, stated on the row, no reciprocal asked for; it never
// declares a purchase unit and the receipt never reads it.
it('records a recipe conversion in one direction and shows purpose and dimension on the page', function () {
    ($this->declare)([
        'purpose' => 'recipe',
        'stock_units_per_purchase_unit' => '1.1',
        'purchase_units_per_stock_unit' => null,
        'source' => 'Razzak Oil Mill, 1 kg = 1.1 L',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $row = ProductUnitConversion::sole();

    expect($row->purpose)->toBe('recipe')
        ->and((float) $row->stock_units_per_purchase_unit)->toBe(1.1)
        ->and($this->oil->fresh()->purchase_unit_id)->toBeNull()
        ->and(ProductUnitConversion::forProductOn($this->oil->id, now()))->toBeNull();

    ($this->asRole)('Owner')->get(route('inventory.unit-conversions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversions.0.purpose', 'recipe')
            ->where('conversions.0.from_dimension', 'mass')
            ->where('conversions.0.to_dimension', 'volume')
            ->where('units', fn ($units) => collect($units)->firstWhere('id', $this->kg->id)['dimension'] === 'mass'));

    // A purchase row still needs both directions typed.
    ($this->declare)(['purpose' => 'purchase', 'purchase_units_per_stock_unit' => null])
        ->assertSessionHasErrors('purchase_units_per_stock_unit');
});
