<?php

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B5 (24 Oct 2026; R25, R26): the recipe screen shows what
 * a recipe costs, laid out as he wrote his mustard oil example: Raw materials,
 * Packaging materials, a rate and a total per line, "Subtotal: Direct
 * materials", and the cost of one unit of output. An estimate for reading:
 * nothing is stored or posted.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Oil Co', 'slug' => 'oil-co-b5']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->pc = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);

    $this->make = fn (string $sku, string $name, UnitOfMeasure $unit, ProductType $type, array $extra = []) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => $sku, 'name' => $name, 'type' => $type,
    ] + $extra);
    $this->stock = fn (Product $product, string $quantity, string $cost) => app(RecordStockIn::class)
        ->handle($product, $this->warehouse, $quantity, $cost, $this->manager, occurredAt: now()->subDay());

    $this->props = function (TestResponse $response): array {
        $response->assertOk();

        return $response->viewData('page')['props'];
    };
    $this->recipes = fn () => ($this->props)($this->actingAs($this->manager)->get(route('inventory.bills-of-material.index')));
});

it("costs Joy's mustard oil bottle as he wrote it: raw, packaging, direct materials", function () {
    $oil = ($this->make)('RAW-OIL-MST-BULK', 'Bulk Mustard Oil', $this->kg, ProductType::RawMaterial);
    // His codes start PKG-, which the PK- rule does not read, so the kind is set
    // in the popup; the carton's PK- code is read on its own.
    $bottle = ($this->make)('PKG-PET-BTL-1L', '1L PET Bottle (Empty)', $this->pc, ProductType::RawMaterial, ['material_kind' => 'packaging']);
    $cap = ($this->make)('PKG-CAP-28MM', '28mm Plastic Cap', $this->pc, ProductType::RawMaterial, ['material_kind' => 'packaging']);
    $label = ($this->make)('PKG-LBL-LAL-1L', 'LaLaDia Front & Back Label', $this->pc, ProductType::RawMaterial, ['material_kind' => 'packaging']);
    // 0.083 of a carton per bottle: the carton is shared, so it may be split.
    $carton = ($this->make)('PK-CRT-MST-12', 'Master Carton (Holds 12)', $this->pc, ProductType::RawMaterial, ['allows_fractional_stock' => true]);
    $bottled = ($this->make)('BNC-OIL-MST-1L', 'Bionic Mustard Oil 1L', $this->pc, ProductType::FinishedGood);

    ($this->stock)($oil, '100', '180');
    // Two bottle deliveries, 11 then 12. Each batch keeps its exact cost (Joy,
    // Q20): the rate is the latest batch's 12; the 11.50 average is a hint only.
    ($this->stock)($bottle, '100', '11');
    ($this->stock)($bottle, '100', '12');
    ($this->stock)($label, '500', '2.5');
    ($this->stock)($carton, '50', '36');
    // The caps were all used: no stock, so there is no average to hint at.
    ($this->stock)($cap, '200', '1.5');
    StockBatch::where('product_id', $cap->id)->update(['current_quantity' => 0, 'status' => StockBatchStatus::Depleted]);

    app(SaveBillOfMaterial::class)->handle(
        outputProduct: $bottled,
        lines: [
            ['product_id' => $oil->id, 'quantity_per_output' => '0.91'],
            ['product_id' => $bottle->id, 'quantity_per_output' => '1'],
            ['product_id' => $cap->id, 'quantity_per_output' => '1'],
            ['product_id' => $label->id, 'quantity_per_output' => '1'],
            ['product_id' => $carton->id, 'quantity_per_output' => '0.083'],
        ],
        by: $this->manager,
        outputQuantity: '1',
        effectiveFrom: '2026-10-24',
    );

    $bom = ($this->recipes)()['boms'][0];
    $line = fn (Product $product) => collect($bom['lines'])->firstWhere('component_product_id', $product->id);

    expect($line($oil))->toMatchArray(['section' => 'raw', 'unit_rate' => '180.0000', 'rate_basis' => 'latest_batch', 'on_hand_average' => '180.0000', 'line_total' => '163.80'])
        ->and($line($bottle))->toMatchArray(['section' => 'packaging', 'unit_rate' => '12.0000', 'rate_basis' => 'latest_batch', 'on_hand_average' => '11.5000', 'line_total' => '12.00'])
        ->and($line($cap))->toMatchArray(['section' => 'packaging', 'unit_rate' => '1.5000', 'rate_basis' => 'latest_batch', 'on_hand_average' => null, 'line_total' => '1.50'])
        ->and($line($label))->toMatchArray(['section' => 'packaging', 'line_total' => '2.50'])
        // 0.083 of a 36.00 carton is 2.988: 2.99 to the paisa (his sheet showed 3.00).
        ->and($line($carton))->toMatchArray(['section' => 'packaging', 'line_total' => '2.99'])
        ->and($bom['raw_total'])->toBe('163.80')
        ->and($bom['packaging_total'])->toBe('18.99')
        ->and($bom['direct_materials'])->toBe('182.79')
        ->and($bom['cost_per_unit'])->toBe('182.79')
        ->and($bom['cost_complete'])->toBeTrue()
        ->and($bom['effective_from'])->toBe('2026-10-24');

    // Reading a recipe's cost moves nothing and posts nothing.
    expect(Journal::count())->toBe(0);
});

it('counts wastage and works out the cost of one unit when a recipe makes many', function () {
    $bulk = ($this->make)('RM-PIC-BEF', 'Beef pickle bulk', $this->kg, ProductType::RawMaterial);
    $jar = ($this->make)('PK-JAR-400G', 'Jar 400 g', $this->pc, ProductType::RawMaterial);
    $pack = ($this->make)('LLD-PIC-BEF-400G', 'Beef pickle 400 g', $this->pc, ProductType::FinishedGood);

    ($this->stock)($bulk, '50', '500');
    ($this->stock)($jar, '100', '10');

    app(SaveBillOfMaterial::class)->handle(
        outputProduct: $pack,
        lines: [
            ['product_id' => $bulk->id, 'quantity_per_output' => '4.8', 'wastage_percent' => '2'],
            ['product_id' => $jar->id, 'quantity_per_output' => '12'],
        ],
        by: $this->manager,
        outputQuantity: '12',
    );

    $bom = ($this->recipes)()['boms'][0];

    // 4.8 kg plus 2% = 4.896 kg at 500 = 2,448.00; 12 jars at 10 = 120.00.
    expect($bom['raw_total'])->toBe('2448.00')
        ->and($bom['packaging_total'])->toBe('120.00')
        ->and($bom['direct_materials'])->toBe('2568.00')
        ->and($bom['cost_per_unit'])->toBe('214.00');
});

it('says a never-stocked item has no cost yet instead of counting it as zero', function () {
    $bulk = ($this->make)('RM-GHE', 'Ghee bulk', $this->kg, ProductType::RawMaterial);
    $lid = ($this->make)('PK-LID-63', 'Lid 63 mm', $this->pc, ProductType::RawMaterial);
    $jar = ($this->make)('LLD-GHE-500G', 'Ghee 500 g', $this->pc, ProductType::FinishedGood);

    ($this->stock)($bulk, '10', '1000');

    app(SaveBillOfMaterial::class)->handle(
        outputProduct: $jar,
        lines: [
            ['product_id' => $bulk->id, 'quantity_per_output' => '0.5'],
            ['product_id' => $lid->id, 'quantity_per_output' => '1'],
        ],
        by: $this->manager,
    );

    $bom = ($this->recipes)()['boms'][0];
    $lidLine = collect($bom['lines'])->firstWhere('component_product_id', $lid->id);

    expect($lidLine)->toMatchArray(['unit_rate' => null, 'rate_basis' => null, 'line_total' => null, 'section' => 'packaging'])
        ->and($bom['direct_materials'])->toBe('500.00')
        ->and($bom['cost_complete'])->toBeFalse();
});

it('gives the recipe popup each item rate and section for its live preview', function () {
    $oil = ($this->make)('RAW-OIL-MST-BULK', 'Bulk Mustard Oil', $this->kg, ProductType::RawMaterial);
    $bottle = ($this->make)('PK-BTL-1L', 'Bottle 1 L', $this->pc, ProductType::RawMaterial);
    ($this->stock)($oil, '10', '180');

    $products = collect(($this->recipes)()['products'])->keyBy('id');

    expect($products[$oil->id])->toMatchArray(['unit_rate' => '180.0000', 'rate_basis' => 'latest_batch', 'on_hand_average' => '180.0000', 'is_packaging' => false, 'can_be_made' => true])
        ->and($products[$bottle->id])->toMatchArray(['unit_rate' => null, 'rate_basis' => null, 'is_packaging' => true, 'can_be_made' => false]);
});
