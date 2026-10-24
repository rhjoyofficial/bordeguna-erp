<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductionRunStatus;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductionRun;
use App\Modules\Inventory\Models\StockBatch;
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

    $this->payload = function (array $overrides = []) {
        $payload = array_merge([
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

        // ⭐ B8 (Q8 = B): every oil line picks the oil's one batch by hand.
        $batch = StockBatch::query()->where('product_id', $this->oil->id)->value('id');
        $payload['lines'] = array_map(fn (array $line) => ($line['product_id'] ?? '') === $this->oil->id && ! isset($line['batches'])
            ? $line + ['batches' => [['stock_batch_id' => $batch, 'quantity' => $line['quantity']]]]
            : $line, $payload['lines']);

        return $payload;
    };
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

    // ⭐ Corrections wave 1 (20 Oct 2026): through the corrections process,
    // with a reason, on the run's own date (the bare button is retired).
    $manager = ($this->asRole)('Manager');
    $body = ['reason_text' => 'The run never happened', 'idempotency_key' => '6f1d2c1e-4b6a-4c55-9d1e-3a1b2c3d4e5f'];
    $token = $manager->postJson("/records/inventory.production_run/{$run->id}/reverse/preview", $body)->assertOk()->json('preview_token');
    $manager->post("/records/inventory.production_run/{$run->id}/reverse", $body + ['preview_token' => $token])->assertSessionHasNoErrors();
    expect($run->fresh()->status)->toBe(ProductionRunStatus::Reversed)
        ->and((string) $this->oil->fresh()->currentStockQuantity())->toBe('100');
});

it('deletes a draft and refuses to delete a confirmed run', function () {
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['confirm' => false]))->assertRedirect();
    $draft = ProductionRun::sole();

    // ⭐ 20 Oct 2026: deleting a draft says why (the Corrections report keeps it).
    ($this->asRole)('Manager')->delete("/inventory/production-runs/{$draft->id}")->assertSessionHasErrors('reason_text');
    ($this->asRole)('Manager')->delete("/inventory/production-runs/{$draft->id}", ['reason_text' => 'Saved twice by mistake'])->assertRedirect();
    expect(ProductionRun::count())->toBe(0);

    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)())->assertRedirect();
    $confirmed = ProductionRun::sole();

    ($this->asRole)('Manager')->delete("/inventory/production-runs/{$confirmed->id}", ['reason_text' => 'Trying to delete it'])->assertSessionHasErrors('run');
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

// ⚠⚠ A bare exists accepted another business's ids; the scoped lookups then
// missed them and the page died with a 500 instead of a sentence.
it('refuses another business warehouse and products as validation errors', function () {
    $other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-production-co']);
    $theirWarehouse = Warehouse::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Theirs', 'code' => 'THEIRS']);
    $theirUnit = UnitOfMeasure::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $theirProduct = Product::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'unit_of_measure_id' => $theirUnit->id,
        'sku' => 'THEIRS-1', 'name' => 'Their product', 'type' => ProductType::FinishedGood,
    ]);

    ($this->asRole)('Manager')
        ->post('/inventory/production-runs', ($this->payload)([
            'warehouse_id' => $theirWarehouse->id,
            'output_product_id' => $theirProduct->id,
            'lines' => [['product_id' => $theirProduct->id, 'quantity' => '1', 'waste' => '0']],
        ]))
        ->assertSessionHasErrors(['warehouse_id', 'output_product_id', 'lines.0.product_id']);

    expect(ProductionRun::count())->toBe(0);
});

// ⚠ MFC-003 (audit 14 Oct 2026): a run dated after today was a blank 500.
it('refuses a run dated after today under the date, and records nothing (MFC-003)', function () {
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['occurred_at' => '2026-08-18']))
        ->assertStatus(302)
        ->assertSessionHasErrors('occurred_at');

    expect(ProductionRun::count())->toBe(0);
});

// ⚠ MFC-004 (audit 14 Oct 2026): a run dated into a closed month came back
// under `errors.ledger`, which this page never renders: nothing visible.
it('says a closed month under the date, a key the page renders (MFC-004)', function () {
    AccountingPeriod::closeThrough(Carbon::parse('2026-08-16'), User::factory()->create());

    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['occurred_at' => '2026-08-16']))
        ->assertStatus(302)
        ->assertSessionHasErrors('occurred_at')
        ->assertSessionDoesntHaveErrors('ledger');

    expect(ProductionRun::count())->toBe(0);
});

// ⚠ MFC-005 (audit 14 Oct 2026): Confirm run on a draft dated before its input
// batch arrived was a blank 500; the one-step path already said why.
it('says why a draft cannot be confirmed on the separate Confirm run button (MFC-005)', function () {
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['confirm' => false, 'occurred_at' => '2026-08-15']))
        ->assertRedirect();

    $run = ProductionRun::sole();

    ($this->asRole)('Manager')->post("/inventory/production-runs/{$run->id}/confirm")
        ->assertStatus(302)
        ->assertSessionHasErrors('run');

    expect($run->fresh()->status)->toBe(ProductionRunStatus::Draft);
});

// ⚠ MFC-009 (audit 14 Oct 2026): Reverse run, Delete draft and a recipe's
// Delete acted on one click. A browser test is not part of this suite, so the
// wiring itself is held: each call sits behind a ConfirmDialog's onConfirm.
it('asks before reversing a run or deleting a draft run or a recipe version (MFC-009)', function () {
    $run = file_get_contents(resource_path('js/pages/inventory/production-runs/show.tsx'));
    $recipes = file_get_contents(resource_path('js/pages/inventory/bills-of-material/index.tsx'));

    // ⭐ Corrections wave 1 (20 Oct 2026): Reverse goes through the action
    // dialog (preview, reason, the run's own date) and Delete through the
    // reason dialog; neither is one click.
    expect($run)->toContain('previewUrl={`${base}/reverse/preview`}')
        ->and($run)->toContain('url={`/inventory/production-runs/${run.id}`}')
        ->and($run)->toContain('method="delete"')
        ->and($run)->not->toContain('router.post(`/inventory/production-runs/${run.id}/reverse`)')
        ->and($run)->not->toContain('onClick={() => router.delete(`/inventory/production-runs/${run.id}`)}')
        ->and($recipes)->toContain("onConfirm={() => router.delete(route('inventory.bills-of-material.destroy', bom.id))}")
        ->and($recipes)->not->toContain("onClick={() => router.delete(route('inventory.bills-of-material.destroy', bom.id))}");
});

// ⚠ Bug hunt 16 Oct 2026 (02 L3): a typo in the output's expiry date made a
// batch already out of date, which the nightly sweep then proposed to write off.
it('refuses an output expiry date that has already passed', function () {
    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['output_expiry_date' => '2025-08-17']))
        ->assertSessionHasErrors('output_expiry_date');

    expect(ProductionRun::count())->toBe(0);

    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)(['output_expiry_date' => '2027-08-17']))
        ->assertSessionHasNoErrors();
});

// 17 Oct 2026: the form starts on the business's default warehouse, the rule
// Opening stock uses, not on whichever name sorts first.
it('preselects the default warehouse on the run form, not the first by name', function () {
    $this->warehouse->makeDefault();
    $first = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Aardvark Store', 'code' => 'AAR']);

    ($this->asRole)('Manager')->get('/inventory/production-runs/new')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('warehouses.0.id', $first->id)
            ->where('defaultWarehouseId', $this->warehouse->id)
            ->where('products', fn ($products) => collect($products)->every(fn ($p) => array_key_exists('tracks_expiry', (array) $p))));
});

it('refuses a run of an expiry-tracked output without the expiry date, under the field', function () {
    $this->pack->update(['tracks_expiry' => true]);

    ($this->asRole)('Manager')
        ->post('/inventory/production-runs', ($this->payload)(['output_expiry_date' => null]))
        ->assertSessionHasErrors('output_expiry_date');

    expect(ProductionRun::count())->toBe(0);

    ($this->asRole)('Manager')->post('/inventory/production-runs', ($this->payload)())->assertSessionHasNoErrors();
    expect(ProductionRun::count())->toBe(1);
});
