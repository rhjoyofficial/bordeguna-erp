<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\PlanOpeningStockImport;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\OpeningStockFile;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The blank sheet somebody carries around the warehouse on counting day.
 *
 * ⚠⚠ The test that matters is the ROUND TRIP: the CSV this controller emits is
 * filled in and fed back through `OpeningStockFile` and `PlanOpeningStockImport`
 * with zero errors. Asserting the header string on its own would pass happily
 * while the `unit` column emitted "Piece" against a product held in "pcs" - a
 * mismatch C-OPN-4 rejects on EVERY row, discovered on the one morning the
 * document can be written.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-18 09:00:00');

    $this->business = Business::create(['name' => 'Count Sheet Co', 'slug' => 'count-sheet-co']);
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

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAVAR']);
    $this->other = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Mohammadpur', 'code' => 'MDPUR']);

    $piece = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);

    $make = fn (array $attributes) => Product::create(array_merge([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $piece->id,
        'type' => ProductType::FinishedGood,
    ], $attributes));

    $this->honey = $make(['sku' => 'LLD-HON-MAN-500G', 'name' => 'Mangrove Gold Honey - 500 g']);
    $this->seed = $make(['sku' => 'RM-SED-MUS-BULK', 'name' => 'Mustard seed, bulk', 'type' => ProductType::RawMaterial, 'unit_of_measure_id' => $kg->id]);
    $this->retired = $make(['sku' => 'BNC-HON-MAN-500G', 'name' => 'Mangrove Gold Honey (retired duplicate)', 'is_active' => false]);
    $this->service = $make(['sku' => 'SVC-DELIVERY', 'name' => 'Delivery service', 'type' => ProductType::Service]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user);
    };

    $this->sheet = fn (string $format = 'print', ?Warehouse $warehouse = null) => ($this->asRole)('Staff')->get(
        '/inventory/opening-stock/count-sheet?warehouse='.($warehouse ?? $this->warehouse)->id
        .'&as_at=2026-08-31&format='.$format
    );

    $this->csvRows = function (string $content) {
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($content))));
        $rows[0] = array_map(fn ($h) => ltrim($h, "\xEF\xBB\xBF"), $rows[0]);

        return $rows;
    };
});

afterEach(fn () => Carbon::setTestNow());

it('prints a sheet carrying the SKU, unit and warehouse code the import matches on', function () {
    $html = ($this->sheet)()->assertOk()->getContent();

    expect($html)
        ->toContain('LLD-HON-MAN-500G')
        ->toContain('Mangrove Gold Honey - 500 g')
        ->toContain('RM-SED-MUS-BULK')
        ->toContain('SAVAR')
        ->toContain('2026-08-31')
        // The counter needs the unit on the row, or the quantity is ambiguous.
        ->toContain('pcs')
        ->toContain('kg');
});

it('leaves off what the opening stock document would refuse', function () {
    $html = ($this->sheet)()->assertOk()->getContent();

    // Inactive: counting stock of a product excluded from go-live is a
    // contradiction, and the importer errors on it.
    expect($html)->not->toContain('BNC-HON-MAN-500G');
    // A service cannot hold stock.
    expect($html)->not->toContain('SVC-DELIVERY');
});

it('pre-fills no quantity and no cost, not even a zero', function () {
    $rows = ($this->csvRows)(($this->sheet)('csv')->streamedContent());

    $qty = array_search('quantity', $rows[0], true);
    $cost = array_search('unit_cost', $rows[0], true);

    expect(count($rows))->toBe(3); // header + honey + seed

    foreach (array_slice($rows, 1) as $row) {
        expect($row[$qty])->toBe('')
            ->and($row[$cost])->toBe('');
    }
});

it('emits a UTF-8 BOM so Excel does not mangle the Bangla product names', function () {
    expect(($this->sheet)('csv')->streamedContent())->toStartWith("\xEF\xBB\xBF");
});

/**
 * ⚠⚠ THE ONE THAT MATTERS. Emit the template, fill in what a counter would
 * write, and push it through the real reader and the real planner.
 */
it('round-trips: the CSV it emits imports with zero errors once quantities are written in', function () {
    $rows = ($this->csvRows)(($this->sheet)('csv')->streamedContent());

    $qty = array_search('quantity', $rows[0], true);
    $cost = array_search('unit_cost', $rows[0], true);

    $filled = [$rows[0]];

    foreach (array_slice($rows, 1) as $row) {
        $row[$qty] = '12';
        $row[$cost] = '450.00';
        $filled[] = $row;
    }

    $path = tempnam(sys_get_temp_dir(), 'count').'.csv';
    $handle = fopen($path, 'w');
    foreach ($filled as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);

    $plan = app(PlanOpeningStockImport::class)->handle(
        OpeningStockFile::read($path),
        Carbon::parse('2026-08-31'),
    );

    unlink($path);

    expect($plan->errors)->toBe([]);
    expect($plan->lineCount())->toBe(2);
});

it('warns in the sheet itself when the warehouse can never receive opening stock again', function () {
    expect(($this->sheet)()->getContent())->not->toContain('ALREADY been recorded');

    // ⚠ The clock moves past the count date first. Opening stock refuses a
    // future as-at, because stock cannot move before it exists - which is also
    // why the real document is posted ON or AFTER 31 August, never before it.
    Carbon::setTestNow('2026-09-01 09:00:00');

    app(RecordOpeningStock::class)->handle(
        warehouse: $this->warehouse,
        lines: [['product' => $this->honey, 'quantity' => '5', 'unitCost' => '400', 'batchNumber' => null, 'expiryDate' => null]],
        createdBy: User::factory()->create(),
        asAt: '2026-08-31',
    );

    // ⚠ The refusal is shown BEFORE the count, not discovered at import after
    // a day spent counting a document that can never be written.
    expect(($this->sheet)()->getContent())->toContain('ALREADY been recorded');
    // The other warehouse is untouched and still countable.
    expect(($this->sheet)('print', $this->other)->getContent())->not->toContain('ALREADY been recorded');
});

it('writes nothing at all', function () {
    $before = [StockBatch::count(), StockMovement::count(), Product::count()];

    ($this->sheet)()->assertOk();
    ($this->sheet)('csv')->streamedContent();

    expect([StockBatch::count(), StockMovement::count(), Product::count()])->toBe($before);
});

it('is a report, so the person who walks the warehouse can print it', function () {
    ($this->asRole)('Staff')->get('/inventory/opening-stock/count-sheet?warehouse='.$this->warehouse->id)->assertOk();
    ($this->asRole)('Manager')->get('/inventory/opening-stock/count-sheet?warehouse='.$this->warehouse->id)->assertOk();
});

it('refuses a warehouse that is not named', function () {
    ($this->asRole)('Staff')->get('/inventory/opening-stock/count-sheet')->assertSessionHasErrors('warehouse');
});

// ⚠ Bug hunt 16 Oct 2026 (02 L2): another business's warehouse was a bare 404.
it('refuses another business warehouse as a field error', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $theirs = Warehouse::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Theirs', 'code' => 'THEIRS']);

    ($this->asRole)('Staff')->get('/inventory/opening-stock/count-sheet?warehouse='.$theirs->id)->assertSessionHasErrors('warehouse');
});
