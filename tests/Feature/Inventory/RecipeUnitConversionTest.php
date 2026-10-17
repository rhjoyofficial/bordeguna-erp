<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\database\seeders\InventoryUnitsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\UnitNotConvertibleException;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRunLine;
use App\Modules\Inventory\Models\ProductUnitConversion;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\UnitConverter;
use App\Modules\Inventory\Support\UnitDimension;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Phase 1 Part B (17 Oct 2026): UOM dimensions and recipe-line units, one
 * test per rule in PLAN-PHASE1 §B.7 and ChatGPT REPLY-1 Q2/Q3.
 *
 * ⚠⚠ The mustard-oil figures are the point of the file. 1 kg = 1.1 L is the
 * approved convention (Razzak Oil Mill); the old 1 L = 0.9 kg is NOT its
 * inverse. The ERP stores the direction the row names and never derives the
 * other, so a line in the reverse direction is refused until somebody
 * records that direction with a source.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-17 10:00:00');

    $this->business = Business::create(['name' => 'Units Co', 'slug' => 'units-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Factory', 'code' => 'FAC']);

    // Typed in as production has them, NOT through the seeder: the model must
    // classify "Kilogram/kg", "Gram/gm", "Litre/Ltr" and "Piece/pcs" on its own.
    $unit = fn (string $name, string $abbr) => UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => $name, 'abbreviation' => $abbr]);
    $this->kg = $unit('Kilogram', 'kg');
    $this->g = $unit('Gram', 'gm');
    $this->litre = $unit('Litre', 'Ltr');
    $this->ml = $unit('Millilitre', 'ml');
    $this->pcs = $unit('Piece', 'pcs');
    $this->jar = $unit('Jar', 'jar');

    $product = fn (string $sku, string $name, UnitOfMeasure $unit, array $extra = []) => Product::create(array_merge([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => $sku,
        'name' => $name,
        'type' => ProductType::RawMaterial,
    ], $extra));

    $this->salt = $product('RM-SALT', 'Salt bulk', $this->kg);
    $this->oilInLitres = $product('RM-OIL-L', 'Mustard oil (stocked in L)', $this->litre);
    $this->oilInKg = $product('RM-OIL-KG', 'Mustard oil (stocked in kg)', $this->kg);
    $this->lid = $product('PK-LID', 'Lid', $this->pcs);
    $this->picklePack = $product('LLD-PICKLE', 'Pickle 400 g', $this->pcs, ['type' => ProductType::FinishedGood]);

    $this->converter = app(UnitConverter::class);

    $this->save = fn (array $lines, ?BillOfMaterial $editing = null) => app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->picklePack,
        lines: $lines,
        by: $this->user,
        editing: $editing,
    );

    $this->oilRow = fn (Product $product, UnitOfMeasure $from, string $factor) => ProductUnitConversion::recordForRecipe(
        businessId: $this->business->id,
        product: $product,
        fromUnit: $from,
        toUnit: $product->unitOfMeasure,
        stockUnitsPerFromUnit: $factor,
        effectiveFrom: '2026-10-01',
        source: 'Razzak Oil Mill, 1 kg = 1.1 L',
    );
});

// ── Dimensions on the units themselves ──────────────────────────────────────

it('classifies hand-typed units by name and gives count units no factor', function () {
    expect($this->kg->dimension)->toBe(UnitDimension::MASS)
        ->and((string) $this->kg->factor_to_base)->toBe('1000.00000000')
        ->and($this->g->dimension)->toBe(UnitDimension::MASS)
        ->and((string) $this->g->factor_to_base)->toBe('1.00000000')
        ->and($this->litre->dimension)->toBe(UnitDimension::VOLUME)
        ->and((string) $this->litre->factor_to_base)->toBe('1000.00000000')
        ->and($this->ml->dimension)->toBe(UnitDimension::VOLUME)
        ->and($this->pcs->dimension)->toBe(UnitDimension::COUNT)
        ->and($this->pcs->factor_to_base)->toBeNull()
        ->and($this->jar->dimension)->toBe(UnitDimension::COUNT)
        ->and($this->jar->factor_to_base)->toBeNull();

    // A unit nobody recognises converts into nothing by itself.
    $dozen = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Dozen', 'abbreviation' => 'dz']);

    expect($dozen->dimension)->toBeNull()->and($dozen->factor_to_base)->toBeNull();
});

it('seeds the everyday units with dimensions and fills the ones typed by hand', function () {
    // The updater half: a unit that pre-dates the columns and sits at NULL.
    DB::table('units_of_measure')->where('id', $this->litre->id)->update(['dimension' => null, 'factor_to_base' => null]);

    (new InventoryUnitsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    expect($this->litre->fresh()->dimension)->toBe(UnitDimension::VOLUME)
        ->and((string) $this->litre->fresh()->factor_to_base)->toBe('1000.00000000')
        // Added only what was missing (box, pack, bottle, packet), classified.
        ->and(UnitOfMeasure::where('abbreviation', 'box')->sole()->dimension)->toBe(UnitDimension::COUNT)
        ->and(UnitOfMeasure::whereIn('abbreviation', ['kg', 'Kilogram'])->count())->toBe(1);
});

// ── The converter, rule by rule ──────────────────────────────────────────────

it('normalizes a 1,000 g recipe line on a kg component to 1.0000 kg', function () {
    $bom = ($this->save)([
        ['product_id' => $this->salt->id, 'quantity_per_output' => '1000', 'unit_id' => $this->g->id],
        ['product_id' => $this->lid->id, 'quantity_per_output' => '1'],
    ]);

    $line = $bom->lines->firstWhere('component_product_id', $this->salt->id);
    $lidLine = $bom->lines->firstWhere('component_product_id', $this->lid->id);

    expect((string) $line->quantity_per_output)->toBe('1.0000')
        ->and((string) $line->entered_quantity)->toBe('1000.0000')
        ->and($line->entered_unit_id)->toBe($this->g->id)
        // A line with no unit named is in the stock unit, as it always was.
        ->and((string) $lidLine->quantity_per_output)->toBe('1.0000')
        ->and($lidLine->entered_unit_id)->toBe($this->pcs->id);
});

it('converts 500 mL on an L component by the global factor and 250 g to 0.2500 kg', function () {
    $result = $this->converter->toStockUnit($this->oilInLitres, '500', $this->ml);

    expect($result->quantity)->toBe('0.5000')
        ->and($result->factor)->toBe('0.00100000')
        ->and($result->basis)->toBe('global');

    expect($this->converter->toStockUnit($this->salt, '250', $this->g)->quantity)->toBe('0.2500');
});

it('refuses a mL line on a count component', function () {
    expect(fn () => ($this->save)([
        ['product_id' => $this->lid->id, 'quantity_per_output' => '10', 'unit_id' => $this->ml->id],
    ]))->toThrow(UnitNotConvertibleException::class, 'no way to turn ml into pcs');
});

it('converts mustard oil kg into L only through the product row, in the direction the row states', function () {
    // No row yet: kg on an L-stocked oil is refused, never guessed.
    expect(fn () => $this->converter->toStockUnit($this->oilInLitres, '2', $this->kg))
        ->toThrow(UnitNotConvertibleException::class, 'Record a recipe conversion');

    ($this->oilRow)($this->oilInLitres, $this->kg, '1.1');

    $result = $this->converter->toStockUnit($this->oilInLitres, '2', $this->kg);

    expect($result->quantity)->toBe('2.2000')
        ->and($result->factor)->toBe('1.10000000')
        ->and($result->basis)->toBe('product');

    // ⚠⚠ The reverse is NEVER derived. The kg-stocked oil has no row from L,
    // and the 1.1 row on the other product says nothing about it either.
    expect(fn () => $this->converter->toStockUnit($this->oilInKg, '1', $this->litre))
        ->toThrow(UnitNotConvertibleException::class);

    // Only an explicit row for THAT direction, with its own source, converts it.
    ($this->oilRow)($this->oilInKg, $this->litre, '0.90909091');

    expect($this->converter->toStockUnit($this->oilInKg, '1', $this->litre)->quantity)->toBe('0.9091');
});

it('refuses a typed ratio between g and kg, which the ERP derives by itself', function () {
    expect(fn () => ($this->oilRow)($this->salt, $this->g, '0.001'))
        ->toThrow(InvalidArgumentException::class, 'convert by themselves');
});

it('refuses a positive quantity that rounds to zero', function () {
    // 0.00004 g on a kg component = 0.00000004 kg: the column holds 0.0000.
    expect(fn () => ($this->save)([
        ['product_id' => $this->salt->id, 'quantity_per_output' => '0.00004', 'unit_id' => $this->g->id],
    ]))->toThrow(UnitNotConvertibleException::class, 'round to zero');

    // 0.05 g is 0.00005 kg and rounds HALF UP to 0.0001, so it is kept.
    expect($this->converter->toStockUnit($this->salt, '0.05', $this->g)->quantity)->toBe('0.0001');
});

it('requires whole numbers on a count stock unit unless the product allows fractions', function () {
    expect(fn () => $this->converter->toStockUnit($this->lid, '0.5'))
        ->toThrow(UnitNotConvertibleException::class, 'whole');

    $this->lid->update(['allows_fractional_stock' => true]);

    expect($this->converter->toStockUnit($this->lid->fresh(), '0.5')->quantity)->toBe('0.5000');
});

it('lists only convertible units in the recipe editor picker', function () {
    ($this->oilRow)($this->oilInLitres, $this->kg, '1.1');

    $forOil = $this->converter->convertibleUnits($this->oilInLitres, Carbon::today())->keys()->all();
    $forLid = $this->converter->convertibleUnits($this->lid, Carbon::today())->keys()->all();

    sort($forOil);
    $expectedOil = [$this->litre->id, $this->ml->id, $this->kg->id];
    sort($expectedOil);

    expect($forOil)->toBe($expectedOil)
        // A count unit: itself only, until a product row says otherwise.
        ->and($forLid)->toBe([$this->pcs->id]);
});

it('lets a sourced purchase factor serve a recipe line, and never lets a recipe row touch the receipt', function () {
    // The purchase row for the L-stocked oil: bought in kg.
    ProductUnitConversion::record(
        businessId: $this->business->id,
        product: $this->oilInLitres,
        fromUnit: $this->kg,
        toUnit: $this->litre,
        stockUnitsPerPurchaseUnit: '1.0905',
        purchaseUnitsPerStockUnit: '0.917',
        effectiveFrom: '2026-09-01',
        source: 'Delivery note',
    );

    // A recipe line in kg uses the same fact about the oil.
    expect($this->converter->toStockUnit($this->oilInLitres, '10', $this->kg)->quantity)->toBe('10.9050');

    // A recipe row from a jar does not change what the goods receipt applies.
    ($this->oilRow)($this->oilInLitres, $this->jar, '5');

    expect(ProductUnitConversion::forProductOn($this->oilInLitres->id, Carbon::today())->purpose)->toBe('purchase')
        ->and((string) ProductUnitConversion::forProductOn($this->oilInLitres->id, Carbon::today())->stock_units_per_purchase_unit)->toBe('1.09050000');
});

// ── Production runs freeze the factor ────────────────────────────────────────

it('freezes the factor a production run line was drafted with and refuses an unvalidated one', function () {
    app(RecordStockIn::class)->handle($this->salt, $this->warehouse, '10', '50', $this->user, occurredAt: '2026-10-16 09:00:00');
    app(RecordStockIn::class)->handle($this->lid, $this->warehouse, '10', '2', $this->user, occurredAt: '2026-10-16 09:00:00');

    $run = app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->picklePack,
        outputQuantity: '2',
        lines: [
            ['product' => $this->salt, 'quantity' => '800', 'waste' => '50', 'unit' => $this->g],
            ['product' => $this->lid, 'quantity' => '2'],
        ],
        createdBy: $this->user,
        occurredAt: '2026-10-17',
    );

    $saltLine = $run->lines->firstWhere('product_id', $this->salt->id);

    expect((string) $saltLine->quantity_consumed)->toBe('0.8000')
        ->and((string) $saltLine->waste_quantity)->toBe('0.0500')
        ->and((string) $saltLine->entered_quantity)->toBe('800.0000')
        ->and($saltLine->entered_unit_id)->toBe($this->g->id)
        ->and((string) $saltLine->conversion_factor)->toBe('0.00100000');

    // A line whose factor was never validated does not consume stock.
    ProductionRunLine::whereKey($saltLine->id)->update(['conversion_factor' => null]);

    expect(fn () => app(RecordProductionRun::class)->confirm($run->fresh(['lines.product']), $this->user))
        ->toThrow(UnitNotConvertibleException::class, 'never validated');

    ProductionRunLine::whereKey($saltLine->id)->update(['conversion_factor' => '0.00100000']);

    $confirmed = app(RecordProductionRun::class)->confirm($run->fresh(['lines.product']), $this->user);

    expect((string) $this->salt->fresh()->currentStockQuantity())->toBe('9.2')
        ->and((string) $confirmed->consumed_value)->toBe('44.0000');
});

it('refuses a half jar on a run line for a whole-count product, by name', function () {
    app(RecordStockIn::class)->handle($this->lid, $this->warehouse, '10', '2', $this->user, occurredAt: '2026-10-16 09:00:00');

    expect(fn () => app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->picklePack,
        outputQuantity: '1',
        lines: [['product' => $this->lid, 'quantity' => '1.5']],
        createdBy: $this->user,
    ))->toThrow(ValidationException::class, 'whole');
});

// ── Migration safety ─────────────────────────────────────────────────────────

it('leaves existing recipes unchanged after the backfill: same stock figure, stock unit, factor 1', function () {
    $bom = ($this->save)([
        ['product_id' => $this->salt->id, 'quantity_per_output' => '0.4'],
        ['product_id' => $this->lid->id, 'quantity_per_output' => '1'],
    ]);

    // The shape every row had before this release: no entered columns at all.
    DB::table('bill_of_material_lines')->update(['entered_quantity' => null, 'entered_unit_id' => null]);

    app(RecordStockIn::class)->handle($this->salt, $this->warehouse, '10', '50', $this->user, occurredAt: '2026-10-16 09:00:00');
    $run = app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse, outputProduct: $this->picklePack, outputQuantity: '1',
        lines: [['product' => $this->salt, 'quantity' => '0.4']], createdBy: $this->user,
    );
    DB::table('production_run_lines')->update(['entered_quantity' => null, 'entered_unit_id' => null, 'conversion_factor' => null]);

    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_17_150003_add_entered_units_to_recipe_and_run_lines.php');
    $migration->backfill();

    $salt = $bom->fresh('lines')->lines->firstWhere('component_product_id', $this->salt->id);
    $lid = $bom->fresh('lines')->lines->firstWhere('component_product_id', $this->lid->id);
    $runLine = $run->fresh('lines')->lines->first();

    expect((string) $salt->quantity_per_output)->toBe('0.4000')
        ->and((string) $salt->entered_quantity)->toBe('0.4000')
        ->and($salt->entered_unit_id)->toBe($this->kg->id)
        ->and((string) $lid->quantity_per_output)->toBe('1.0000')
        ->and($lid->entered_unit_id)->toBe($this->pcs->id)
        ->and((string) $runLine->quantity_consumed)->toBe('0.4000')
        ->and((string) $runLine->entered_quantity)->toBe('0.4000')
        ->and($runLine->entered_unit_id)->toBe($this->kg->id)
        ->and((string) $runLine->conversion_factor)->toBe('1.00000000');

    // And a row the backfill has not reached still reads as its stock figure.
    DB::table('bill_of_material_lines')->where('id', $salt->id)->update(['entered_quantity' => null, 'entered_unit_id' => null]);
    $stale = $bom->fresh('lines.componentProduct')->lines->firstWhere('component_product_id', $this->salt->id);

    expect($stale->enteredQuantity())->toBe('0.4000')->and($stale->enteredUnitId())->toBe($this->kg->id);
});

it('recognises typed-in units in the migration backfill and leaves unknown ones at null', function () {
    $dozen = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Dozen', 'abbreviation' => 'dz']);
    DB::table('units_of_measure')->update(['dimension' => null, 'factor_to_base' => null]);

    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_17_150001_add_dimension_and_factor_to_units_of_measure.php');
    $migration->backfill();

    expect($this->g->fresh()->dimension)->toBe('mass')
        ->and((string) $this->g->fresh()->factor_to_base)->toBe('1.00000000')
        ->and($this->litre->fresh()->dimension)->toBe('volume')
        ->and($this->jar->fresh()->dimension)->toBe('count')
        ->and($this->jar->fresh()->factor_to_base)->toBeNull()
        ->and($dozen->fresh()->dimension)->toBeNull();
});

// ── The stock-unit lock ──────────────────────────────────────────────────────

it('locks the stock unit at the model once any movement exists', function () {
    // Before any movement the unit may still change.
    $this->salt->update(['unit_of_measure_id' => $this->g->id]);
    expect($this->salt->fresh()->unit_of_measure_id)->toBe($this->g->id);

    app(RecordStockIn::class)->handle($this->salt->fresh(), $this->warehouse, '10', '50', $this->user, occurredAt: '2026-10-16 09:00:00');

    expect(fn () => $this->salt->fresh()->update(['unit_of_measure_id' => $this->kg->id]))
        ->toThrow(DomainException::class, 'stock unit cannot change');

    expect($this->salt->fresh()->unit_of_measure_id)->toBe($this->g->id);
});
