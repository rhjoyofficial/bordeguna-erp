<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Where the recipe meets the run. C-BOM-2 and C-BOM-3.
 *
 * ⚠⚠ THE RUN COPIES THE RECIPE; IT DOES NOT POINT AT IT. The test that matters
 * most here is the one that edits the recipe AFTER a run and proves the run
 * still says what it was measured against. This is the same rule the batch
 * already follows for the unit-conversion factor: a document is a statement
 * about a moment, and a template that could rewrite it retrospectively makes
 * every past number unprovable.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-05 10:00:00');

    $this->business = Business::create(['name' => 'Pickle Co', 'slug' => 'pickle-co', 'fiscal_year_start_month' => 7]);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(InventoryAccountMappingSeeder::class);
    TenantScope::enter($this->business->id);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Factory', 'code' => 'FAC']);

    $make = fn (string $sku, string $name, UnitOfMeasure $unit, ProductType $type) => Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => $sku,
        'name' => $name,
        'type' => $type,
    ]);

    $this->bulk = $make('RM-PIC-BEF', 'Royal Beef Pickle bulk', $kg, ProductType::RawMaterial);
    $this->jar = $make('PK-JAR-400G', 'Jar 400 g', $piece, ProductType::RawMaterial);
    $this->cap = $make('PK-CAP-63MM', 'Jar lid 63 mm', $piece, ProductType::RawMaterial);
    $this->label = $make('LBL-PIC-400G', 'Pickle label 400 g', $piece, ProductType::RawMaterial);
    $this->pack = $make('LLD-PIC-BEF-400G', 'Royal Beef Pickle 400 g', $piece, ProductType::FinishedGood);
    $this->other = $make('LLD-PIC-HIL-400G', 'Hilsa Pickle 400 g', $piece, ProductType::FinishedGood);

    $this->stockIn = fn (Product $p, string $qty, string $cost) => app(RecordStockIn::class)->handle(
        product: $p,
        warehouse: $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->user,
        occurredAt: '2026-09-04 09:00:00',
    );

    // C-BOM-5: a 2% allowance on the bulk, nothing on the countable parts.
    $this->recipe = app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->pack,
        lines: [
            ['product_id' => $this->bulk->id, 'quantity_per_output' => '0.4', 'wastage_percent' => '2'],
            ['product_id' => $this->jar->id, 'quantity_per_output' => '1'],
            ['product_id' => $this->cap->id, 'quantity_per_output' => '1'],
        ],
        by: $this->user,
    );

    $this->draft = fn (array $lines, string $outputQuantity = '100', $bom = 'recipe') => app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->pack,
        outputQuantity: $outputQuantity,
        lines: $lines,
        createdBy: $this->user,
        occurredAt: '2026-09-05',
        billOfMaterial: $bom === 'recipe' ? $this->recipe : $bom,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('copies the recipe onto the run as the standard each line is measured against', function () {
    // C-BOM-3. 0.4 kg per pack plus 2% is 40.8 kg for 100 packs.
    $run = ($this->draft)([
        ['product' => $this->bulk, 'quantity' => '40.8'],
        ['product' => $this->jar, 'quantity' => '100'],
        ['product' => $this->cap, 'quantity' => '100'],
    ]);

    $standards = $run->lines->pluck('standard_quantity', 'product_id')
        ->map(fn ($q) => (float) $q)->all();

    expect($standards[$this->bulk->id])->toBe(40.8)
        ->and($standards[$this->jar->id])->toBe(100.0)
        ->and($standards[$this->cap->id])->toBe(100.0)
        ->and($run->bill_of_material_id)->toBe($this->recipe->id)
        ->and($run->bill_of_material_version)->toBe(1);
});

it('leaves the standard NULL on a line the recipe never mentioned', function () {
    // ⚠ Null, not zero. A line the operator added by hand is the interesting
    // one, and a zero would hide it among the components simply not needed.
    $run = ($this->draft)([
        ['product' => $this->bulk, 'quantity' => '40.8'],
        ['product' => $this->jar, 'quantity' => '100'],
        ['product' => $this->cap, 'quantity' => '100'],
        ['product' => $this->label, 'quantity' => '100'],
    ]);

    expect($run->lines->firstWhere('product_id', $this->label->id)->standard_quantity)->toBeNull();
});

it('records what the operator says was consumed, not what the recipe said', function () {
    // C-BOM-3. The suggestion is editable, always. The run is the record.
    $run = ($this->draft)([
        ['product' => $this->bulk, 'quantity' => '43'],
        ['product' => $this->jar, 'quantity' => '100'],
        ['product' => $this->cap, 'quantity' => '102'],
    ]);

    $bulkLine = $run->lines->firstWhere('product_id', $this->bulk->id);
    $capLine = $run->lines->firstWhere('product_id', $this->cap->id);

    expect((float) $bulkLine->quantity_consumed)->toBe(43.0)
        ->and((float) $bulkLine->standard_quantity)->toBe(40.8)
        ->and((float) $capLine->quantity_consumed - (float) $capLine->standard_quantity)->toBe(2.0);
});

it('does not change a past run when the recipe is edited afterwards', function () {
    // ⚠⚠ C-BOM-2. THE RULE THIS WHOLE SLICE TURNS ON. A run is a statement about
    // a moment; a template that could rewrite it makes every past number
    // unprovable.
    $run = ($this->draft)([
        ['product' => $this->bulk, 'quantity' => '40.8'],
        ['product' => $this->jar, 'quantity' => '100'],
        ['product' => $this->cap, 'quantity' => '100'],
    ]);

    $this->recipe->load('lines');

    app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->pack,
        lines: [
            ['product_id' => $this->bulk->id, 'quantity_per_output' => '0.9'],
            ['product_id' => $this->jar->id, 'quantity_per_output' => '3'],
        ],
        by: $this->user,
        editing: $this->recipe,
    );

    $reloaded = $run->fresh('lines');

    expect((float) $reloaded->lines->firstWhere('product_id', $this->bulk->id)->standard_quantity)->toBe(40.8)
        ->and((float) $reloaded->lines->firstWhere('product_id', $this->jar->id)->standard_quantity)->toBe(100.0)
        // The run was built from v1, and v1 is still the answer.
        ->and($reloaded->bill_of_material_version)->toBe(1);
});

it('writes a new version rather than mutating the one a run was built from', function () {
    // C-BOM-6, from the other side: the edit above must have spawned v2.
    ($this->draft)([
        ['product' => $this->bulk, 'quantity' => '40.8'],
        ['product' => $this->jar, 'quantity' => '100'],
        ['product' => $this->cap, 'quantity' => '100'],
    ]);

    $this->recipe->load('lines');

    $next = app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->pack,
        lines: [['product_id' => $this->jar->id, 'quantity_per_output' => '1']],
        by: $this->user,
        editing: $this->recipe,
    );

    expect($next->id)->not->toBe($this->recipe->id)
        ->and($next->version)->toBe(2)
        ->and($this->recipe->fresh()->is_active)->toBeFalse()
        // v1's lines are untouched: it is what the run was built from.
        ->and($this->recipe->fresh()->lines)->toHaveCount(3);
});

it('refuses a recipe that makes a different product', function () {
    $wrong = app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->other,
        lines: [['product_id' => $this->jar->id, 'quantity_per_output' => '1']],
        by: $this->user,
    );

    expect(fn () => ($this->draft)([
        ['product' => $this->jar, 'quantity' => '100'],
    ], '100', $wrong))->toThrow(ValidationException::class);
});

it('leaves the run with no recipe when none was chosen', function () {
    $run = ($this->draft)([
        ['product' => $this->bulk, 'quantity' => '40'],
    ], '100', null);

    expect($run->bill_of_material_id)->toBeNull()
        ->and($run->lines->first()->standard_quantity)->toBeNull();
});

it('still confirms and posts exactly as a run without a recipe does', function () {
    // ⚠⚠ C-BOM-1 at the far end: the recipe changed the SUGGESTION and nothing
    // about what confirming a run does. The output batch still costs the FIFO
    // value consumed, divided by the output quantity.
    ($this->stockIn)($this->bulk, '100', '500');   // 50,000
    ($this->stockIn)($this->jar, '500', '12');     //  6,000 for 500
    ($this->stockIn)($this->cap, '500', '3');      //  1,500 for 500

    $run = ($this->draft)([
        ['product' => $this->bulk, 'quantity' => '40'],
        ['product' => $this->jar, 'quantity' => '100'],
        ['product' => $this->cap, 'quantity' => '100'],
    ]);

    $confirmed = app(RecordProductionRun::class)->confirm($run, $this->user);

    // 40 kg × 500 + 100 × 12 + 100 × 3 = 20,000 + 1,200 + 300 = 21,500
    expect((float) $confirmed->consumed_value)->toBe(21500.0)
        ->and((float) $confirmed->outputBatch->unit_cost)->toBe(215.0)
        ->and(ProductionRun::whereNotNull('bill_of_material_id')->count())->toBe(1);
});
