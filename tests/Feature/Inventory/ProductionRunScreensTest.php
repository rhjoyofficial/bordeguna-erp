<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The production screens and their permission layer: everything ships
 * reachable - route, screen, sidebar link - in the same commit as the engine,
 * and `inventory.production.manage` is the one gate for the whole document
 * (D-MFG-4).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-17 10:00:00');

    $this->business = Business::create(['name' => 'Production Screen Co', 'slug' => 'production-screen-co']);
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

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Factory', 'code' => 'FAC']);
    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);

    $this->oil = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id,
        'sku' => 'OIL-BULK', 'name' => 'Bulk mustard oil', 'type' => ProductType::RawMaterial,
    ]);
    $this->pack = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $piece->id,
        'sku' => 'OIL-5L', 'name' => 'Mustard oil 5 L pack', 'type' => ProductType::FinishedGood,
    ]);

    app(RecordStockIn::class)->handle(
        $this->oil, $this->warehouse, '100', '180.00',
        User::factory()->create(), occurredAt: '2026-08-16 09:00:00'
    );

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->payload = fn (array $overrides = []) => array_merge([
        'warehouse_id' => $this->warehouse->id,
        'output_product_id' => $this->pack->id,
        'output_quantity' => '10',
        'output_expiry_date' => '2027-08-17',
        'output_mrp' => '1250',
        'occurred_at' => '2026-08-17',
        'expense_waste' => false,
        'confirm' => true,
        'lines' => [['product_id' => $this->oil->id, 'quantity' => '50', 'waste' => '0']],
    ], $overrides);
});

afterEach(fn () => Carbon::setTestNow());

it('shows the runs list, entry form and report to a Manager', function () {
    ($this->asRole)('Manager')->get('/inventory/production-runs')->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/production-runs/index'));

    ($this->asRole)('Manager')->get('/inventory/production-runs/new')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/production-runs/create')
            // The live availability the form shows before confirm refuses -
            // normalised, so the value is identical on SQLite and MySQL.
            ->where('stockByProduct.'.$this->oil->id.'.'.$this->warehouse->id, '100'));

    ($this->asRole)('Manager')->get('/inventory/production-yield')->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/production-yield'));
});

it('blocks the production document for Staff while leaving the report readable', function () {
    ($this->asRole)('Staff')->get('/inventory/production-runs')->assertForbidden();
    ($this->asRole)('Staff')->post('/inventory/production-runs', ($this->payload)())->assertForbidden();

    // Staff hold inventory.reports.view, and a report is not a document.
    ($this->asRole)('Staff')->get('/inventory/production-yield')->assertOk();
});

it('records and confirms a run through the screen in one step', function () {
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)())->assertRedirect();

    $run = ProductionRun::sole();

    expect($run->status)->toBe(ProductionRunStatus::Confirmed)
        ->and((string) $run->outputBatch->unit_cost)->toBe('900.0000') // 50 x 180 / 10
        ->and($run->outputBatch->manufactured_date->toDateString())->toBe('2026-08-17')
        ->and((string) $run->outputBatch->mrp)->toBe('1250.0000');
});

it('saves a draft, shows it, confirms it, and reverses it through the screens', function () {
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['confirm' => false]))->assertRedirect();

    $run = ProductionRun::sole();
    expect($run->status)->toBe(ProductionRunStatus::Draft);

    ($this->asRole)('Manager')->get("/inventory/production-runs/{$run->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/production-runs/show')
            ->where('run.run_number', $run->run_number));

    ($this->asRole)('Manager')->post("/inventory/production-runs/{$run->id}/confirm")->assertRedirect();
    expect($run->fresh()->status)->toBe(ProductionRunStatus::Confirmed);

    ($this->asRole)('Manager')->post("/inventory/production-runs/{$run->id}/reverse")->assertRedirect();
    expect($run->fresh()->status)->toBe(ProductionRunStatus::Reversed)
        ->and((string) $this->oil->fresh()->currentStockQuantity())->toBe('100');
});

it('deletes a draft and refuses to delete a confirmed run', function () {
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['confirm' => false]))->assertRedirect();
    $draft = ProductionRun::sole();

    ($this->asRole)('Manager')->delete("/inventory/production-runs/{$draft->id}")->assertRedirect();
    expect(ProductionRun::count())->toBe(0);

    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)())->assertRedirect();
    $confirmed = ProductionRun::sole();

    ($this->asRole)('Manager')->delete("/inventory/production-runs/{$confirmed->id}")->assertSessionHasErrors('run');
    expect(ProductionRun::count())->toBe(1);
});

it('aggregates the month value-based on the yield report and counts confirmed runs only', function () {
    // One confirmed run with waste, one draft that must not count.
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)([
        'lines' => [['product_id' => $this->oil->id, 'quantity' => '50', 'waste' => '5']],
    ]))->assertRedirect();
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['confirm' => false]))->assertRedirect();

    ($this->asRole)('Manager')->get('/inventory/production-yield?month=2026-08')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('inventory/production-yield')
            ->has('products', 1)
            ->has('runs', 1)
            ->where('products.0.runs', 1)
            // 5 kg of 50 kg at 180: waste 900.00 of 9,000.00 consumed = 10%.
            ->where('products.0.consumed_value', '9,000.00')
            ->where('products.0.waste_value', '900.00')
            ->where('products.0.waste_percent', 10));

    // A month with no confirmed runs reports emptiness, not an error.
    ($this->asRole)('Manager')->get('/inventory/production-yield?month=2026-07')->assertOk()
        ->assertInertia(fn ($page) => $page->has('products', 0)->has('runs', 0));
});

it('surfaces an insufficient-stock refusal as a form error and keeps the draft', function () {
    // 200 kg from a shelf holding 100.
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)([
        'lines' => [['product_id' => $this->oil->id, 'quantity' => '200', 'waste' => '0']],
    ]))->assertRedirect()->assertSessionHasErrors('run');

    // The confirm was refused; the typing survives as a draft, nothing moved.
    expect(ProductionRun::count())->toBe(1)
        ->and(ProductionRun::sole()->status)->toBe(ProductionRunStatus::Draft)
        ->and((string) $this->oil->fresh()->currentStockQuantity())->toBe('100');
});
