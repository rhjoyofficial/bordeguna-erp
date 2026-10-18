<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\ReverseProductionRun;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductionCostKind;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\BomSuggestion;
use App\Modules\Inventory\Support\MaxMakeable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PR3 (18 Oct 2026, Joy: "when I add qty of them, auto calculate how many or
 * how much product can be made, auto fill the necessary amount used").
 *
 * "Can make N, limited by X" (MaxMakeable), whole jars filled UP, and Tally's
 * additional costs (labour, overhead) carried into the batch and posted
 * Dr Stock / Cr the chosen head, undone by the reversal.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:00:00');

    $this->business = Business::create(['name' => 'Calc Co', 'slug' => 'calc-co', 'fiscal_year_start_month' => 7]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->gram = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Gram', 'abbreviation' => 'g']);
    $piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Factory', 'code' => 'FAC', 'is_default' => true]);
    $this->other = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);

    $make = fn (string $sku, string $name, UnitOfMeasure $unit, ProductType $type = ProductType::RawMaterial) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => $sku, 'name' => $name, 'type' => $type,
    ]);

    $this->bulk = $make('RM-PIC', 'Pickle bulk', $kg);
    $this->jar = $make('PK-JAR', 'Jar 400 g', $piece);
    $this->pack = $make('PIC-400', 'Pickle 400 g', $piece, ProductType::FinishedGood);

    $this->stockIn = fn (Product $p, string $qty, string $cost, ?Warehouse $w = null) => app(RecordStockIn::class)->handle(
        product: $p,
        warehouse: $w ?? $this->warehouse,
        quantity: $qty,
        unitCost: $cost,
        performedBy: $this->user,
        occurredAt: '2026-10-17 09:00:00',
    );

    // 0.4 kg per jar plus 2%: 0.408 kg of bulk per pack, one jar per pack.
    $this->recipe = app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->pack,
        lines: [
            ['product_id' => $this->bulk->id, 'quantity_per_output' => '0.4', 'wastage_percent' => '2'],
            ['product_id' => $this->jar->id, 'quantity_per_output' => '1'],
        ],
        by: $this->user,
    );

    $this->available = fn (?Warehouse $w = null) => MaxMakeable::available(MaxMakeable::layers(($w ?? $this->warehouse)->id))[($w ?? $this->warehouse)->id] ?? [];

    $this->labour = Account::query()->where('code', '6097')->firstOrFail();
    $this->packagingLabour = Account::query()->where('code', '6201')->firstOrFail();

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('says how many can be made and which component limits it, wastage included', function () {
    ($this->stockIn)($this->bulk, '20', '300');   // 20 / 0.408 = 49.01 packs
    ($this->stockIn)($this->jar, '60', '12');     // 60 packs

    $result = MaxMakeable::forRecipe($this->recipe->fresh(), ($this->available)());

    // Whole packs only: 49, held back by the bulk.
    expect($result['quantity'])->toBe('49')
        ->and($result['limited_by_product_id'])->toBe($this->bulk->id)
        ->and($result['limited_by'])->toBe('Pickle bulk');

    // And the suggestion for 49 fits the shelf it was worked out from.
    expect((float) BomSuggestion::forOutput('0.4', '49', '1', '2'))->toBeLessThanOrEqual(20.0);
});

it('reads the chosen warehouse only and gives zero, limited by the missing part, when a part is absent', function () {
    ($this->stockIn)($this->bulk, '20', '300');
    ($this->stockIn)($this->jar, '60', '12', $this->other);

    $here = MaxMakeable::forRecipe($this->recipe->fresh(), ($this->available)());

    expect($here['quantity'])->toBe('0')
        ->and($here['limited_by_product_id'])->toBe($this->jar->id);
});

it('leaves held and expired batches out of what a run could take', function () {
    ($this->stockIn)($this->bulk, '20', '300');
    ($this->stockIn)($this->jar, '30', '12');
    ($this->stockIn)($this->jar, '30', '12');

    // One jar batch on hold: only 30 jars are on offer.
    StockBatch::query()->where('product_id', $this->jar->id)->orderBy('id')->first()->update(['held_at' => now()]);

    $result = MaxMakeable::forRecipe($this->recipe->fresh(), ($this->available)());

    expect($result['quantity'])->toBe('30')
        ->and($result['limited_by_product_id'])->toBe($this->jar->id);
});

it('fills a whole-unit component up to the next whole one, on the screen and in the stored standard', function () {
    expect(BomSuggestion::forOutput('1', '10', '4', '0', wholeUnits: true))->toBe('3.0000')
        ->and(BomSuggestion::forOutput('1', '12', '4', '0', wholeUnits: true))->toBe('3.0000')
        ->and(BomSuggestion::forOutput('1', '10', '4', '0'))->toBe('2.5000');

    // A recipe of 1 jar per 4 packs (a carton, say): 10 packs need 3 jars.
    $recipe = app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->pack,
        lines: [['product_id' => $this->jar->id, 'quantity_per_output' => '1']],
        by: $this->user,
        outputQuantity: '4',
    );

    $run = app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->pack,
        outputQuantity: '10',
        lines: [['product' => $this->jar, 'quantity' => '3']],
        createdBy: $this->user,
        occurredAt: '2026-10-18',
        billOfMaterial: $recipe,
    );

    expect((string) $run->lines->first()->standard_quantity)->toBe('3.0000');
});

it('adds labour and packaging labour to the batch cost and posts them Dr Stock / Cr the chosen heads', function () {
    ($this->stockIn)($this->bulk, '20', '300');
    ($this->stockIn)($this->jar, '60', '12');

    $run = app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->pack,
        outputQuantity: '10',
        lines: [
            ['product' => $this->bulk, 'quantity' => '4.08'],
            ['product' => $this->jar, 'quantity' => '10'],
        ],
        createdBy: $this->user,
        occurredAt: '2026-10-18',
        outputExpiryDate: '2027-10-18',
        billOfMaterial: $this->recipe,
        costs: [
            ['kind' => ProductionCostKind::Labour, 'account' => $this->labour, 'amount' => '500'],
            ['kind' => 'packaging_labour', 'account' => $this->packagingLabour, 'amount' => '150.50', 'note' => 'Two packers'],
        ],
    );

    expect($run->costs)->toHaveCount(2);

    $run = app(RecordProductionRun::class)->confirm($run, $this->user);

    // Materials 4.08 x 300 + 10 x 12 = 1,344.00; costs 650.50; 1,994.50 / 10.
    expect((string) $run->consumed_value)->toBe('1344.0000')
        ->and((string) $run->additional_cost_value)->toBe('650.5000')
        ->and((string) $run->output_value)->toBe('1994.5000')
        ->and((string) $run->outputBatch->unit_cost)->toBe('199.4500');

    $journal = Journal::query()->where('source_type', $run->getMorphClass())->where('source_id', $run->id)->sole();
    $byAccount = $journal->lines->groupBy('account_id')->map->sum('amount_minor');

    expect((int) $byAccount[Account::bySlug('stock_on_hand')->id])->toBe(65050)
        ->and((int) $byAccount[$this->labour->id])->toBe(-50000)
        ->and((int) $byAccount[$this->packagingLabour->id])->toBe(-15050)
        ->and((int) $journal->lines->sum('amount_minor'))->toBe(0);

    // The reversal undoes the posting and the stock together.
    $reversed = app(ReverseProductionRun::class)->handle($run, $this->user);

    expect($reversed->status)->toBe(ProductionRunStatus::Reversed)
        ->and((int) JournalLine::where('account_id', $this->labour->id)->sum('amount_minor'))->toBe(0)
        ->and((int) JournalLine::where('account_id', Account::bySlug('stock_on_hand')->id)->sum('amount_minor'))->toBe(0)
        ->and((string) $this->pack->fresh()->currentStockQuantity())->toBe('0');
});

it('keeps a run without cost lines exactly as before', function () {
    ($this->stockIn)($this->bulk, '20', '300');
    ($this->stockIn)($this->jar, '60', '12');

    $run = app(RecordProductionRun::class)->confirm(app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->pack,
        outputQuantity: '10',
        lines: [['product' => $this->bulk, 'quantity' => '4.08'], ['product' => $this->jar, 'quantity' => '10']],
        createdBy: $this->user,
        occurredAt: '2026-10-18',
        outputExpiryDate: '2027-10-18',
    ), $this->user);

    expect($run->additional_cost_value)->toBeNull()
        ->and((string) $run->output_value)->toBe('1344.0000')
        ->and((string) $run->outputBatch->unit_cost)->toBe('134.4000')
        ->and(Journal::query()->where('source_type', $run->getMorphClass())->where('source_id', $run->id)->count())->toBe(0);
});

it('refuses a cost line on a head that is not an expense, or of no amount', function (array $cost, string $key) {
    $stock = Account::bySlug('stock_on_hand');

    $cost = array_merge(['kind' => 'labour', 'account' => $this->labour, 'amount' => '100'], $cost);
    if (($cost['account'] ?? null) === 'stock') {
        $cost['account'] = $stock;
    }

    expect(fn () => app(RecordProductionRun::class)->draft(
        warehouse: $this->warehouse,
        outputProduct: $this->pack,
        outputQuantity: '1',
        lines: [['product' => $this->jar, 'quantity' => '1']],
        createdBy: $this->user,
        occurredAt: '2026-10-18',
        outputExpiryDate: '2027-10-18',
        costs: [$cost],
    ))->toThrow(ValidationException::class);

    expect(ProductionRun::count())->toBe(0);
})->with([
    'an asset head' => [['account' => 'stock'], 'costs.0.account_id'],
    'zero' => [['amount' => '0'], 'costs.0.amount'],
    'an unknown kind' => [['kind' => 'magic'], 'costs.0.kind'],
]);

it('gives the run form units, whole-only flags, can-make figures, cost layers and cost heads', function () {
    ($this->stockIn)($this->bulk, '20', '300');
    ($this->stockIn)($this->jar, '60', '12');

    ($this->asRole)('Manager')->get('/inventory/production-runs/new?product='.$this->pack->id)->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/production-runs/create')
            ->where('initialOutputProductId', $this->pack->id)
            ->where('canMake.'.$this->pack->id.'.'.$this->warehouse->id.'.quantity', '49')
            ->where('canMake.'.$this->pack->id.'.'.$this->warehouse->id.'.limited_by_product_id', $this->bulk->id)
            ->where('costLayers.'.$this->warehouse->id.'.'.$this->bulk->id.'.0.unit_cost', '300.0000')
            ->where('products', fn ($products) => collect($products)->firstWhere('id', $this->jar->id)['whole_only'] === true
                && collect($products)->firstWhere('id', $this->bulk->id)['whole_only'] === false
                // Bulk in kg may be typed in grams too.
                && collect(collect($products)->firstWhere('id', $this->bulk->id)['units'])->pluck('abbreviation')->contains('g'))
            ->where('costKinds', fn ($kinds) => collect($kinds)->firstWhere('value', 'labour')['account_id'] === $this->labour->id)
            ->where('expenseAccounts', fn ($accounts) => collect($accounts)->pluck('id')->contains($this->labour->id)
                && ! collect($accounts)->pluck('id')->contains(Account::bySlug('stock_on_hand')->id)));
});

it('records a run with a line in grams and a labour cost through the screen', function () {
    ($this->stockIn)($this->bulk, '20', '300');
    ($this->stockIn)($this->jar, '60', '12');

    ($this->asRole)('Manager')->post('/inventory/production-runs', [
        'warehouse_id' => $this->warehouse->id,
        'output_product_id' => $this->pack->id,
        'output_quantity' => '10',
        'bill_of_material_id' => $this->recipe->id,
        'output_expiry_date' => '2027-10-18',
        'occurred_at' => '2026-10-18',
        'expense_waste' => false,
        'confirm' => true,
        'lines' => [
            ['product_id' => $this->bulk->id, 'quantity' => '4080', 'waste' => '0', 'unit_id' => $this->gram->id],
            ['product_id' => $this->jar->id, 'quantity' => '10', 'waste' => '0'],
        ],
        'costs' => [['kind' => 'labour', 'account_id' => $this->labour->id, 'amount' => '200']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $run = ProductionRun::sole();

    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and((string) $run->lines->firstWhere('product_id', $this->bulk->id)->quantity_consumed)->toBe('4.0800')
        ->and((string) $run->outputBatch->unit_cost)->toBe('154.4000'); // (1,344 + 200) / 10

    ($this->asRole)('Manager')->get("/inventory/production-runs/{$run->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('run.additional_cost_value', '200.0000')
            ->where('run.costs.0.kind', 'labour')
            ->where('run.costs.0.amount', '200.00'));
});

it('shows "can make now" for each active recipe on the Recipes page', function () {
    ($this->stockIn)($this->bulk, '20', '300');
    ($this->stockIn)($this->jar, '60', '12');

    ($this->asRole)('Manager')->get('/inventory/bills-of-material')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/bills-of-material/index')
            ->where('canMakeWarehouse', 'Factory')
            ->where('boms.0.can_make.quantity', '49')
            ->where('boms.0.can_make.limited_by', 'Pickle bulk'));
});
