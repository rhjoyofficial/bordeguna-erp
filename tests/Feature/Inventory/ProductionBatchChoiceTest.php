<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Support\LedgerIntegrity;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\HoldStockBatch;
use App\Modules\Inventory\Actions\RecordProductionRun;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\ProductionRunLineBatch;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ProductionRunForm;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B8 (24 Oct 2026; Q8 = B, R31): "Instead of
 * auto-consuming, manually select the specific batch" for every input of a
 * production run. No proposal; batches of the run's warehouse only (stock
 * elsewhere is transferred first); expired and on-hold batches refused; a line
 * may be split; confirm takes exactly the picks and the finished batch costs
 * exactly what they cost.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 10:00:00');

    $this->business = Business::create(['name' => 'Mill Co', 'slug' => 'mill-co-b8']);
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

    $this->mill = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mill', 'code' => 'MIL', 'is_default' => true]);
    $this->shop = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SHP']);
    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $pc = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);

    $this->seed = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'sku' => 'RM-SEED', 'name' => 'Mustard seed', 'type' => ProductType::RawMaterial]);
    $this->oil = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $pc->id, 'sku' => 'FG-OIL', 'name' => 'Mustard oil 1 L', 'type' => ProductType::FinishedGood]);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $in = fn (Warehouse $w, string $qty, string $cost, string $number, string $on, ?string $expiry = null) => app(RecordStockIn::class)->handle(
        $this->seed, $w, $qty, $cost, $this->manager, batchNumber: $number, expiryDate: $expiry, occurredAt: $on,
    );
    // The oldest is the cheapest: FIFO would take it first.
    $in($this->mill, '50', '100', 'SEED-A', '2026-10-01 09:00:00');
    $in($this->mill, '50', '120', 'SEED-B', '2026-10-10 09:00:00');
    $in($this->mill, '50', '90', 'SEED-OLD', '2026-09-01 09:00:00', '2026-10-20');
    $in($this->shop, '50', '80', 'SEED-SHOP', '2026-10-05 09:00:00');

    $this->batch = fn (string $number) => StockBatch::where('batch_number', $number)->sole();

    $this->form = fn (array $picks, string $quantity = '30', array $overrides = []) => array_merge([
        'warehouse_id' => $this->mill->id,
        'output_product_id' => $this->oil->id,
        'output_quantity' => '10',
        'occurred_at' => '2026-10-24',
        'expense_waste' => false,
        'confirm' => true,
        'lines' => [['product_id' => $this->seed->id, 'quantity' => $quantity, 'waste' => '0',
            'batches' => array_map(fn (array $pick) => ['stock_batch_id' => ($this->batch)($pick[0])->id, 'quantity' => $pick[1]], $picks)]],
    ], $overrides);

    $this->post = fn (array $form) => $this->actingAs($this->manager)->from(route('inventory.production-runs.create'))->post(route('inventory.production-runs.store'), $form);
});

afterEach(fn () => Carbon::setTestNow());

it('takes exactly the batches picked, split across two, and costs the output at exactly that', function () {
    // 20 kg of the dearer B and 10 kg of A, not FIFO's 30 kg of A.
    ($this->post)(($this->form)([['SEED-B', '20'], ['SEED-A', '10']]))->assertSessionHasNoErrors();

    $run = ProductionRun::sole();
    $out = $run->movements()->where('direction', MovementDirection::Out)->get();

    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and($out->mapWithKeys(fn ($m) => [$m->batch->batch_number => (float) $m->quantity])->sortKeys()->all())->toBe(['SEED-A' => 10.0, 'SEED-B' => 20.0])
        ->and((float) ($this->batch)('SEED-A')->current_quantity)->toBe(40.0)
        ->and((float) ($this->batch)('SEED-B')->current_quantity)->toBe(30.0)
        // 20 x 120 + 10 x 100 = 3,400 for 10 bottles = 340 each.
        ->and((float) $run->consumed_value)->toBe(3400.0)
        ->and((float) $run->outputBatch->unit_cost)->toBe(340.0)
        ->and(ProductionRunLineBatch::count())->toBe(2)
        ->and(LedgerIntegrity::verifyAllBusinesses()['problems'])->toBeEmpty();

    // The run page says where each input came from.
    $this->actingAs($this->manager)->get(route('inventory.production-runs.show', $run))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('run.lines.0.batches', fn ($picks) => collect($picks)->pluck('batch_number')->all() === ['SEED-B', 'SEED-A']));
});

it('requires a batch on every line, with no proposal', function () {
    $form = ($this->form)([]);
    unset($form['lines'][0]['batches']);

    ($this->post)($form)->assertSessionHasErrors(['lines.0.batches' => 'Line 1: pick the batch it comes from.']);

    expect(ProductionRun::count())->toBe(0);
});

it('refuses a pick that is not one', function (array $picks, string $message) {
    ($this->post)(($this->form)($picks))->assertSessionHasErrors('lines.0.batches');

    expect(session('errors')->first('lines.0.batches'))->toContain($message)
        ->and(ProductionRun::count())->toBe(0)
        ->and((float) ($this->batch)('SEED-A')->current_quantity)->toBe(50.0);
})->with([
    'stock in another warehouse' => [[['SEED-SHOP', '30']], 'is in another warehouse. Transfer it to Mill first'],
    'an expired batch' => [[['SEED-OLD', '30']], 'expired on 20/10/2026'],
    'not adding up to the line' => [[['SEED-A', '20']], 'The batches add up to 20 kg, but the line uses 30 kg.'],
    'the same batch twice' => [[['SEED-A', '15'], ['SEED-A', '15']], 'is picked twice'],
]);

it('refuses a batch on hold', function () {
    app(HoldStockBatch::class)->hold(($this->batch)('SEED-B'), $this->manager, 'Lab test pending');

    ($this->post)(($this->form)([['SEED-B', '30']]))->assertSessionHasErrors('lines.0.batches');

    expect(session('errors')->first('lines.0.batches'))->toContain('is on hold');
});

it('keeps the draft and names the batch when it holds less than picked by confirm time', function () {
    ($this->post)(($this->form)([['SEED-A', '30']], overrides: ['confirm' => false]))->assertSessionHasNoErrors();
    $run = ProductionRun::sole();

    // Meanwhile most of A is used elsewhere.
    app(RecordProductionRun::class)->confirm(app(RecordProductionRun::class)->draft(
        warehouse: $this->mill, outputProduct: $this->oil, outputQuantity: '1',
        lines: [['product' => $this->seed, 'quantity' => '45', 'batches' => [['batch' => ($this->batch)('SEED-A'), 'quantity' => '45']]]],
        createdBy: $this->manager, occurredAt: '2026-10-24',
    ), $this->manager);

    $this->actingAs($this->manager)->post(route('inventory.production-runs.confirm', $run))
        ->assertSessionHasErrors(['run' => 'Not enough Mustard seed in batch SEED-A at Mill: needs 30 kg, only 5 kg available. Choose another batch for the rest.']);

    expect($run->fresh()->status)->toBe(ProductionRunStatus::Draft);
});

it('opens a draft for Edit with its picks, and saves new ones', function () {
    ($this->post)(($this->form)([['SEED-A', '30']], overrides: ['confirm' => false]))->assertSessionHasNoErrors();
    $run = ProductionRun::sole();

    expect(ProductionRunForm::of($run)['lines'][0]['batches'])->toBe([['stock_batch_id' => (string) ($this->batch)('SEED-A')->id, 'quantity' => '30']]);

    $form = ($this->form)([['SEED-B', '30']]);
    unset($form['confirm']);
    $this->actingAs($this->manager)->put(route('inventory.production-runs.update', $run), $form)->assertSessionHasNoErrors();

    expect($run->fresh()->lines->sole()->batchPicks->pluck('stock_batch_id')->all())->toBe([($this->batch)('SEED-B')->id]);
});

it('will not confirm a draft saved before batches were picked', function () {
    $draft = app(RecordProductionRun::class)->draft(
        warehouse: $this->mill, outputProduct: $this->oil, outputQuantity: '10',
        lines: [['product' => $this->seed, 'quantity' => '30']],
        createdBy: $this->manager, occurredAt: '2026-10-24',
    );

    $this->actingAs($this->manager)->post(route('inventory.production-runs.confirm', $draft))
        ->assertSessionHasErrors(['run' => "Edit {$draft->run_number} and choose the batch for every input line, then confirm it."]);

    expect($draft->fresh()->status)->toBe(ProductionRunStatus::Draft);
});

it('no longer counts expired stock as available on the form, and lists every batch with its supplier, cost and expiry', function () {
    $this->actingAs($this->manager)->get(route('inventory.production-runs.create'))->assertOk()
        // 50 A + 50 B in the mill; the expired 50 is not available.
        ->assertInertia(fn (Assert $page) => $page->where("stockByProduct.{$this->seed->id}.{$this->mill->id}", '100'));

    $body = $this->actingAs($this->manager)
        ->getJson(route('inventory.stock.batch-options', ['product_id' => $this->seed->id, 'warehouse_id' => $this->mill->id]))
        ->assertOk()->json('batches');

    expect(collect($body)->pluck('batch_number')->all())->toBe(['SEED-OLD', 'SEED-A', 'SEED-B'])
        ->and($body[0])->toMatchArray(['expired' => true, 'unit_cost' => '90.00', 'expiry_date' => '2026-10-20'])
        ->and(array_key_exists('supplier', $body[0]))->toBeTrue();
});
