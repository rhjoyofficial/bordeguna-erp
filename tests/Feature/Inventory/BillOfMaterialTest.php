<?php

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\BomSuggestion;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Slice D: the Bill of Materials. C-BOM-1..9.
 *
 * ⚠⚠ THE FIRST TEST IS THE WHOLE POINT OF THE SLICE. A BOM is a template: it
 * posts no journal, writes no movement and creates no batch. The production run
 * remains the sixth and last document that can move stock, and if that ever
 * stops being true this file fails first.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Recipe Co', 'slug' => 'recipe-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $make = fn (string $sku, string $name, UnitOfMeasure $unit, ProductType $type, bool $active = true) => Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => $sku,
        'name' => $name,
        'type' => $type,
        'is_active' => $active,
    ]);

    // Joy's first real case: the pickle is BOUGHT ready in bulk by weight, and
    // Bor De Guna packs it. Note the output is counted in pcs and the bulk is
    // measured in kg, and the recipe converts nothing.
    $this->bulk = $make('RM-PIC-BEF', 'Royal Beef Pickle bulk', $this->kg, ProductType::RawMaterial);
    $this->jar = $make('PK-JAR-400G', 'Jar 400 g', $this->piece, ProductType::RawMaterial);
    $this->cap = $make('PK-CAP-63MM', 'Jar lid 63 mm', $this->piece, ProductType::RawMaterial);
    $this->pack = $make('LLD-PIC-BEF-400G', 'Royal Beef Pickle 400 g', $this->piece, ProductType::FinishedGood);
    $this->giftBox = $make('LLD-GFT-BOX', 'Gift box', $this->piece, ProductType::FinishedGood);
    $this->retired = $make('PK-JAR-OLD', 'Old jar', $this->piece, ProductType::RawMaterial, false);
    $this->service = $make('SVC-DEL', 'Delivery', $this->piece, ProductType::Service);

    $this->save = fn (array $args) => app(SaveBillOfMaterial::class)->handle(
        outputProduct: $args['output'],
        lines: $args['lines'],
        by: $this->user,
        editing: $args['editing'] ?? null,
        name: $args['name'] ?? null,
        outputQuantity: $args['outputQuantity'] ?? '1',
    );

    $this->pickleRecipe = fn (?BillOfMaterial $editing = null) => ($this->save)([
        'output' => $this->pack,
        'editing' => $editing,
        'lines' => [
            ['product_id' => $this->bulk->id, 'quantity_per_output' => '0.4'],
            ['product_id' => $this->jar->id, 'quantity_per_output' => '1'],
            ['product_id' => $this->cap->id, 'quantity_per_output' => '1'],
        ],
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };
});

it('posts nothing, moves nothing and creates no batch', function () {
    // ⚠⚠ C-BOM-1. The production run stays the sixth and LAST sanctioned stock
    // document. A recipe that could post would be a seventh way to move stock
    // that nobody audits.
    $bom = ($this->pickleRecipe)();

    expect($bom->lines)->toHaveCount(3)
        ->and(Journal::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and(StockBatch::count())->toBe(0);
});

it('keeps each quantity in its own unit and converts nothing', function () {
    // C-BOM-4. 0.4 kg of bulk per 1 pcs of jar is a stated recipe quantity, not
    // arithmetic. Mustard oil, whose two directions are 21% apart and not
    // reciprocals, is why this rule is absolute.
    $bom = ($this->pickleRecipe)();

    $bulkLine = $bom->lines->firstWhere('component_product_id', $this->bulk->id);

    expect((float) $bulkLine->quantity_per_output)->toBe(0.4)
        ->and($bulkLine->componentProduct->unitOfMeasure->abbreviation)->toBe('kg')
        ->and($bom->outputProduct->unitOfMeasure->abbreviation)->toBe('pcs');
});

it('refuses a recipe that would contain itself', function () {
    // C-BOM-7, the direct case.
    expect(fn () => ($this->save)([
        'output' => $this->pack,
        'lines' => [['product_id' => $this->pack->id, 'quantity_per_output' => '1']],
    ]))->toThrow(DomainException::class, 'contain itself');
});

it('refuses an indirect cycle at any depth', function () {
    // C-BOM-7. A gift box really does contain finished jars, so the box may
    // contain the pack - but then the pack may never contain the box.
    ($this->save)([
        'output' => $this->giftBox,
        'lines' => [['product_id' => $this->pack->id, 'quantity_per_output' => '4']],
    ]);

    expect(fn () => ($this->save)([
        'output' => $this->pack,
        'lines' => [
            ['product_id' => $this->bulk->id, 'quantity_per_output' => '0.4'],
            ['product_id' => $this->giftBox->id, 'quantity_per_output' => '1'],
        ],
    ]))->toThrow(DomainException::class, 'contain itself');
});

it('refuses a service at either end, and an inactive product as a new component', function () {
    // C-BOM-8.
    expect(fn () => ($this->save)([
        'output' => $this->service,
        'lines' => [['product_id' => $this->jar->id, 'quantity_per_output' => '1']],
    ]))->toThrow(DomainException::class, 'service');

    expect(fn () => ($this->save)([
        'output' => $this->pack,
        'lines' => [['product_id' => $this->service->id, 'quantity_per_output' => '1']],
    ]))->toThrow(DomainException::class, 'service');

    expect(fn () => ($this->save)([
        'output' => $this->pack,
        'lines' => [['product_id' => $this->retired->id, 'quantity_per_output' => '1']],
    ]))->toThrow(DomainException::class, 'inactive');
});

it('keeps an inactive component that a recipe already had', function () {
    // C-BOM-8. Breaking an old recipe is worse than an odd-looking one, so an
    // existing line is left alone and only a NEW one is refused.
    $bom = ($this->save)([
        'output' => $this->pack,
        'lines' => [['product_id' => $this->jar->id, 'quantity_per_output' => '1']],
    ]);

    $this->jar->update(['is_active' => false]);
    $bom->load('lines');

    $again = ($this->save)([
        'output' => $this->pack,
        'editing' => $bom,
        'lines' => [
            ['product_id' => $this->jar->id, 'quantity_per_output' => '2'],
        ],
    ]);

    expect((float) $again->lines->first()->quantity_per_output)->toBe(2.0);
});

it('refuses the same component twice, and a quantity of zero', function () {
    expect(fn () => ($this->save)([
        'output' => $this->pack,
        'lines' => [
            ['product_id' => $this->jar->id, 'quantity_per_output' => '1'],
            ['product_id' => $this->jar->id, 'quantity_per_output' => '1'],
        ],
    ]))->toThrow(DomainException::class, 'twice');

    expect(fn () => ($this->save)([
        'output' => $this->pack,
        'lines' => [['product_id' => $this->jar->id, 'quantity_per_output' => '0']],
    ]))->toThrow(DomainException::class, 'greater than zero');
});

it('edits a recipe no run has used in place, without spawning a version', function () {
    // C-BOM-6. During setup a typo should not leave a trail of dead versions.
    $bom = ($this->pickleRecipe)();
    $bom->load('lines');

    $edited = ($this->save)([
        'output' => $this->pack,
        'editing' => $bom,
        'lines' => [['product_id' => $this->jar->id, 'quantity_per_output' => '1']],
    ]);

    expect($edited->id)->toBe($bom->id)
        ->and($edited->version)->toBe(1)
        ->and($edited->lines)->toHaveCount(1)
        ->and(BillOfMaterial::count())->toBe(1);
});

it('keeps only one active version per product', function () {
    // C-BOM-6. Two active recipes would make the run screen pick one at random.
    $first = ($this->pickleRecipe)();

    // A second save with no `editing` is a new version by definition.
    $second = ($this->save)([
        'output' => $this->pack,
        'lines' => [['product_id' => $this->jar->id, 'quantity_per_output' => '1']],
    ]);

    expect($second->version)->toBe(2)
        ->and($second->is_active)->toBeTrue()
        ->and($first->fresh()->is_active)->toBeFalse()
        ->and(BillOfMaterial::where('output_product_id', $this->pack->id)->where('is_active', true)->count())->toBe(1);
});

it('scales a suggestion by the output quantity and the wastage allowance', function () {
    // C-BOM-5. 0.4 kg per pack, 250 packs, 2% allowance.
    expect(BomSuggestion::forOutput('0.4', '250', '1', '2'))->toBe('102.0000')
        ->and(BomSuggestion::forOutput('0.4', '250', '1'))->toBe('100.0000')
        // A recipe written over a 20 kg drum keeps its own arithmetic.
        ->and(BomSuggestion::forOutput('20', '50', '100'))->toBe('10.0000')
        // Half away from zero at four places, because bcmath truncates and a
        // truncated allowance is a smaller allowance than the one typed.
        ->and(BomSuggestion::forOutput('0.33335', '1', '1'))->toBe('0.3334');
});

it('is closed to Staff and open to a Manager', function () {
    ($this->asRole)('Staff')->get(route('inventory.bills-of-material.index'))->assertForbidden();
    ($this->asRole)('Manager')->get(route('inventory.bills-of-material.index'))->assertOk();
});

it('saves a recipe from the screen and reads the refusal as a sentence', function () {
    ($this->asRole)('Owner')->post(route('inventory.bills-of-material.store'), [
        'output_product_id' => $this->pack->id,
        'output_quantity' => '1',
        'lines' => [
            ['product_id' => $this->bulk->id, 'quantity_per_output' => '0.4'],
            ['product_id' => $this->jar->id, 'quantity_per_output' => '1'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(BillOfMaterial::count())->toBe(1);

    // ⚠ The action's refusals are written for a person to read, so the screen
    // surfaces them verbatim rather than replacing them with "invalid input".
    ($this->asRole)('Owner')->post(route('inventory.bills-of-material.store'), [
        'output_product_id' => $this->pack->id,
        'output_quantity' => '1',
        'lines' => [['product_id' => $this->pack->id, 'quantity_per_output' => '1']],
    ])->assertSessionHasErrors('lines');

    expect(session('errors')->first('lines'))->toContain('contain itself');
});
