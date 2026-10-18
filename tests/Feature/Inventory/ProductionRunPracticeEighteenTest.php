<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Validation\ArrayFieldName;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The owner's practice run, 18 Oct 2026, on the production run screen:
 * P8 the result after "Record and confirm run" and a second identical run a
 * moment later; P10 the sentences a person reads instead of field paths and
 * ids; P1 the standard units a real chart was missing.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:00:00');

    $this->business = Business::create(['name' => 'Practice 18 Co', 'slug' => 'practice-18-co']);
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

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Bor de Guna Corporate Office', 'code' => 'BCO']);
    $g = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Gram', 'abbreviation' => 'g']);
    $piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    $this->salt = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $g->id,
        'sku' => 'RM-SALT', 'name' => 'Practice Bulk Pink Salt', 'type' => ProductType::RawMaterial,
    ]);
    $this->jar = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $piece->id,
        'sku' => 'SALT-JAR', 'name' => 'Pink Salt Jar', 'type' => ProductType::FinishedGood,
    ]);

    app(RecordStockIn::class)->handle($this->salt, $this->warehouse, '5000', '0.50', User::factory()->create(), occurredAt: '2026-10-17 09:00:00');

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->as = fn (?User $user = null) => $this->actingAs($user ?? $this->manager);

    $this->payload = fn (array $overrides = []) => array_merge([
        'warehouse_id' => $this->warehouse->id,
        'output_product_id' => $this->jar->id,
        'output_quantity' => '10',
        'occurred_at' => '2026-10-18',
        'expense_waste' => false,
        'confirm' => true,
        'lines' => [['product_id' => $this->salt->id, 'quantity' => '2000', 'waste' => '0']],
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

it('lands on the confirmed run with a success line after Record and confirm (P8)', function () {
    $response = ($this->as)()->post('/inventory/production-runs', ($this->payload)());
    $run = ProductionRun::sole();

    $response->assertRedirect("/inventory/production-runs/{$run->id}")
        ->assertSessionHas('success', "Production run {$run->run_number} confirmed.");

    expect($run->isConfirmed())->toBeTrue();
});

it('refuses the same run by the same person within two minutes, naming the first, until they say record it again (P8)', function () {
    ($this->as)()->post('/inventory/production-runs', ($this->payload)())->assertSessionHasNoErrors();
    $first = ProductionRun::sole();

    // Ten seconds later, the same press again ('10.0' is the same quantity).
    Carbon::setTestNow('2026-10-18 10:00:10');
    ($this->as)()->post('/inventory/production-runs', ($this->payload)(['output_quantity' => '10.0']))
        ->assertSessionHasErrors(['repeat', 'repeat_run_id']);

    expect(ProductionRun::count())->toBe(1)
        ->and(session('errors')->first('repeat'))->toContain($first->run_number)
        ->and(session('errors')->first('repeat_run_id'))->toBe((string) $first->id);

    // The person means it.
    ($this->as)()->post('/inventory/production-runs', ($this->payload)(['record_again' => true]))->assertSessionHasNoErrors();
    expect(ProductionRun::count())->toBe(2);
});

it('lets a different quantity, a different person, or the same run after two minutes through (P8)', function () {
    ($this->as)()->post('/inventory/production-runs', ($this->payload)())->assertSessionHasNoErrors();

    ($this->as)()->post('/inventory/production-runs', ($this->payload)(['output_quantity' => '5', 'lines' => [['product_id' => $this->salt->id, 'quantity' => '1000']]]))
        ->assertSessionHasNoErrors();

    $colleague = User::factory()->create();
    $colleague->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $colleague->assignRole('Manager');
    ($this->as)($colleague)->post('/inventory/production-runs', ($this->payload)(['lines' => [['product_id' => $this->salt->id, 'quantity' => '1000']]]))
        ->assertSessionHasNoErrors();

    Carbon::setTestNow('2026-10-18 10:02:30');
    ($this->as)()->post('/inventory/production-runs', ($this->payload)(['lines' => [['product_id' => $this->salt->id, 'quantity' => '500']]]))
        ->assertSessionHasNoErrors();

    expect(ProductionRun::count())->toBe(4);
});

it('says line problems as sentences, not field paths (P10)', function () {
    ($this->as)()->post('/inventory/production-runs', ($this->payload)([
        'output_quantity' => 'a20',
        'lines' => [
            ['product_id' => $this->salt->id, 'quantity' => '10'],
            ['product_id' => '', 'quantity' => ''],
        ],
    ]))->assertSessionHasErrors([
        'output_quantity' => 'Enter the output quantity as a number, for example 20 or 2.5.',
        'lines.1.product_id' => 'Line 2: choose a product.',
        'lines.1.quantity' => 'Line 2: enter the quantity.',
    ]);

    // Any other array field still reads as words, on every form.
    expect(ArrayFieldName::forHumans('lines.0.product_id'))->toBe('line 1 product')
        ->and(ArrayFieldName::forHumans('items.2.unit_price'))->toBe('item 3 unit price')
        ->and(ArrayFieldName::forHumans('lines.0.taxes.1.rate'))->toBe('line 1 tax 2 rate');

    $messages = validator(['lines' => [['discount' => 'x']]], ['lines.*.discount' => ['numeric']])->errors()->all();
    expect($messages)->toBe(['The line 1 discount field must be a number.']);
});

it('names the product, the warehouse and the unit when stock is short, with grouped numbers (P10)', function () {
    ($this->as)()->post('/inventory/production-runs', ($this->payload)([
        'lines' => [['product_id' => $this->salt->id, 'quantity' => '20000']],
    ]))->assertSessionHasErrors([
        'run' => 'Not enough Practice Bulk Pink Salt in Bor de Guna Corporate Office: needs 20,000 g, only 5,000 g available.',
    ]);

    expect(InsufficientStockException::forProductInWarehouse(999999, 888888, 2.5, 0)->getMessage())
        ->toBe('Not enough product #999999 in warehouse #888888: needs 2.5, only 0 available.');
});

it('plants the missing standard units into a chart that has some, never touching or doubling one (P1)', function () {
    // The chart as found on real data: dz, g, kg, L, pcs (g and pcs above), plus
    // a hand-typed "Bottles" and an "ML" in capitals.
    foreach ([['Dozen', 'dz'], ['Kilogram', 'kg'], ['Litre', 'L'], ['Bottles', 'btl'], ['Millilitre', 'ML']] as [$name, $abbreviation]) {
        UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => $name, 'abbreviation' => $abbreviation]);
    }
    $other = Business::create(['name' => 'Bare Co', 'slug' => 'bare-co-units']);
    $before = DB::table('units_of_measure')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_18_100001_plant_missing_standard_units.php');
    $migration->plant();
    $migration->plant();

    $mine = DB::table('units_of_measure')->where('business_id', $this->business->id)->pluck('abbreviation')->all();
    $theirs = DB::table('units_of_measure')->where('business_id', $other->id)->orderBy('abbreviation')->get(['abbreviation', 'dimension', 'factor_to_base']);

    // Every unit that was there is exactly as it was.
    foreach ($before as $row) {
        expect((array) DB::table('units_of_measure')->find($row['id']))->toEqual($row);
    }

    expect($mine)->toHaveCount(7 + 4)
        ->and($mine)->toContain('box', 'jar', 'pack', 'packet')
        ->and($mine)->not->toContain('mL', 'bottle')
        ->and($theirs->pluck('abbreviation')->all())->toEqualCanonicalizing(['mL', 'box', 'jar', 'pack', 'bottle', 'packet'])
        ->and($theirs->firstWhere('abbreviation', 'mL')->dimension)->toBe('volume')
        ->and((float) $theirs->firstWhere('abbreviation', 'mL')->factor_to_base)->toBe(1.0)
        ->and($theirs->firstWhere('abbreviation', 'jar')->dimension)->toBe('count')
        ->and($theirs->firstWhere('abbreviation', 'jar')->factor_to_base)->toBeNull();
});
