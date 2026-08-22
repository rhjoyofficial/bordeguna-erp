<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\OpeningStock;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The 31 August count, imported from a browser.
 *
 * ⚠⚠ The engine is already covered by OpeningStockImportTest. What only the
 * SCREEN can get wrong is the three things a console command never had: the
 * count date (no default, and bound to the preview), the file's own bytes
 * between preview and apply, and the total the accountant has to agree with -
 * which the operator types back because nothing in the system can compare it
 * with a document the ERP has never seen.
 *
 * ⚠ Every successful apply asserts NO SESSION ERRORS as well as the redirect.
 * A validation bounce is also a 302, so `assertRedirect()` on its own is happy
 * with a request that wrote nothing - which is how an "Undefined array key"
 * hid behind a green assertion here once already.
 *
 * ⚠⚠ And the round trip. The count sheet and the importer are two screens that
 * must agree about which products exist and what the columns are called; the
 * last test here downloads the real sheet, fills it in and applies it, because
 * a template nobody has fed back through the reader is a guess.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-01 10:00:00');

    $this->business = Business::create(['name' => 'Counting Co', 'slug' => 'counting-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();

    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->unit = UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs',
    ]);

    $this->main = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_active' => true,
    ]);
    $this->shop = Warehouse::create([
        'business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SHOP', 'is_active' => true,
    ]);

    $this->product = fn (string $sku) => Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $this->unit->id,
        'sku' => $sku,
        'name' => "Product {$sku}",
        'type' => ProductType::FinishedGood,
        'is_active' => true,
    ]);

    ($this->product)('LLD-DRY-CHU-125G');
    ($this->product)('BNC-SED-CHI-500G');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->csv = fn (string $body, string $name = 'count.csv') => UploadedFile::fake()->createWithContent(
        $name,
        "sku,warehouse,quantity,unit_cost\n".$body
    );

    $this->previewOf = fn (UploadedFile $file, string $asAt = '2026-08-31') => ($this->asRole)('Owner')
        ->post(route('inventory.opening-stock.import.preview'), ['file' => $file, 'as_at' => $asAt])
        ->original->getData()['page']['props']['preview'];
});

afterEach(fn () => Carbon::setTestNow());

it('previews the whole document and writes nothing', function () {
    $preview = ($this->previewOf)(($this->csv)("LLD-DRY-CHU-125G,MAIN,10,250\nBNC-SED-CHI-500G,SHOP,4,500\n"));

    expect($preview['errors'])->toBe([])
        ->and($preview['warehouses'])->toHaveCount(2)
        ->and($preview['line_count'])->toBe(2)
        ->and($preview['total_value_exact'])->toBe('4500.00')
        ->and($preview['as_at'])->toBe('2026-08-31')
        ->and(OpeningStock::count())->toBe(0)
        ->and(StockBatch::count())->toBe(0);
});

it('writes the documents once the total is typed back', function () {
    $preview = ($this->previewOf)(($this->csv)("LLD-DRY-CHU-125G,MAIN,10,250\nBNC-SED-CHI-500G,SHOP,4,500\n"));

    ($this->asRole)('Owner')->post(route('inventory.opening-stock.import.store'), [
        'token' => $preview['token'],
        'hash' => $preview['hash'],
        'as_at' => $preview['as_at'],
        // The human form, commas and all. A thousands separator is not a
        // disagreement about the amount.
        'confirm_total' => '4,500.00',
        'note' => 'Physical count',
    ])->assertSessionHasNoErrors()->assertRedirect(route('inventory.opening-stock.index'));

    expect(OpeningStock::count())->toBe(2)
        ->and(OpeningStock::first()->as_at->toDateString())->toBe('2026-08-31')
        ->and(StockBatch::count())->toBe(2);
});

it('refuses a total that does not match, and writes nothing', function () {
    // ⚠⚠ The one check the system cannot make for itself: this figure has to
    // equal the stock line on the accountant's opening trial balance, and no
    // rule can compare it with a document the ERP has never seen.
    $preview = ($this->previewOf)(($this->csv)("LLD-DRY-CHU-125G,MAIN,10,250\n"));

    ($this->asRole)('Owner')->post(route('inventory.opening-stock.import.store'), [
        'token' => $preview['token'],
        'hash' => $preview['hash'],
        'as_at' => $preview['as_at'],
        'confirm_total' => '2400.00',
    ])->assertSessionHasErrors('confirm_total');

    expect(OpeningStock::count())->toBe(0);
});

it('refuses a file that changed since the preview', function () {
    $preview = ($this->previewOf)(($this->csv)("LLD-DRY-CHU-125G,MAIN,10,250\n"));

    $path = Storage::disk('local')->path('imports/'.$this->business->id.'/'.$preview['token'].'.csv');
    file_put_contents($path, "sku,warehouse,quantity,unit_cost\nLLD-DRY-CHU-125G,MAIN,10,99999\n");

    ($this->asRole)('Owner')->post(route('inventory.opening-stock.import.store'), [
        'token' => $preview['token'],
        'hash' => $preview['hash'],
        'as_at' => $preview['as_at'],
        'confirm_total' => '2500.00',
    ])->assertSessionHasErrors('file');

    expect(OpeningStock::count())->toBe(0);
});

it('applies the date that was reviewed, not the day the import happened', function () {
    // ⚠ Today is 1 September in this test. The opening position belongs to the
    // day the shelves were walked.
    $preview = ($this->previewOf)(($this->csv)("LLD-DRY-CHU-125G,MAIN,10,250\n"), '2026-08-31');

    ($this->asRole)('Owner')->post(route('inventory.opening-stock.import.store'), [
        'token' => $preview['token'], 'hash' => $preview['hash'],
        'as_at' => $preview['as_at'], 'confirm_total' => '2500.00',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(OpeningStock::first()->as_at->toDateString())->toBe('2026-08-31');
});

it('counts nothing for a row left blank, and says how many there were', function () {
    // C-OPN-6. The count sheet prints every stocked product in the business, so
    // a warehouse holding one of two comes back with a deliberately blank line.
    $file = UploadedFile::fake()->createWithContent('count.csv',
        "sku,warehouse,quantity,unit_cost\nLLD-DRY-CHU-125G,MAIN,10,250\nBNC-SED-CHI-500G,MAIN,,\n");

    $preview = ($this->previewOf)($file);

    expect($preview['errors'])->toBe([])
        ->and($preview['line_count'])->toBe(1)
        ->and($preview['not_counted'])->toHaveCount(1)
        ->and($preview['not_counted'][0]['sku'])->toBe('BNC-SED-CHI-500G')
        ->and($preview['counted_products'])->toBe(1)
        ->and($preview['active_stocked_products'])->toBe(2);
});

it('still refuses a cost with no quantity, because that row is half filled', function () {
    $file = UploadedFile::fake()->createWithContent('count.csv',
        "sku,warehouse,quantity,unit_cost\nLLD-DRY-CHU-125G,MAIN,,250\n");

    $preview = ($this->previewOf)($file);

    expect($preview['errors'])->toHaveCount(1)
        ->and($preview['errors'][0]['message'])->toContain('unit cost but no quantity');
});

it('refuses the whole file when one warehouse has already been counted', function () {
    // C-OPN-1 at the screen. Three warehouses written and the fourth refused is
    // the state that cannot be recovered from.
    $first = ($this->previewOf)(($this->csv)("LLD-DRY-CHU-125G,MAIN,10,250\n"));
    ($this->asRole)('Owner')->post(route('inventory.opening-stock.import.store'), [
        'token' => $first['token'], 'hash' => $first['hash'],
        'as_at' => $first['as_at'], 'confirm_total' => '2500.00',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $second = ($this->previewOf)(($this->csv)("LLD-DRY-CHU-125G,MAIN,1,250\nBNC-SED-CHI-500G,SHOP,4,500\n"));

    expect($second['errors'])->toHaveCount(1)
        ->and($second['errors'][0]['message'])->toContain('ALREADY been recorded');

    ($this->asRole)('Owner')->post(route('inventory.opening-stock.import.store'), [
        'token' => $second['token'], 'hash' => $second['hash'],
        'as_at' => $second['as_at'], 'confirm_total' => $second['total_value_exact'],
    ])->assertSessionHasErrors('file');

    expect(OpeningStock::count())->toBe(1);
});

it('names the warehouses still awaiting a count before anything is uploaded', function () {
    $page = ($this->asRole)('Owner')->get(route('inventory.opening-stock.import'))
        ->assertOk()->original->getData()['page']['props'];

    expect(collect($page['awaiting'])->pluck('code')->all())->toBe(['MAIN', 'SHOP'])
        ->and($page['alreadyRecorded'])->toBe([])
        ->and($page['stockedProducts'])->toBe(2);
});

it('round-trips the real count sheet CSV through the real importer', function () {
    // ⚠⚠ The count sheet and the importer are two screens that have to agree
    // about which products exist and what the columns are called. A template
    // nobody has fed back through the reader is a guess.
    $sheet = ($this->asRole)('Owner')
        ->get(route('inventory.opening-stock.count-sheet', ['warehouse' => $this->main->id, 'as_at' => '2026-08-31', 'format' => 'csv']))
        ->streamedContent();

    // Fill one line in and leave the other blank, exactly as a counter would.
    $lines = explode("\n", trim($sheet));
    $filled = [$lines[0]];

    foreach (array_slice($lines, 1) as $index => $line) {
        $cells = str_getcsv($line);
        // sku, product_name, unit, warehouse, quantity, unit_cost, batch, expiry, note
        if ($index === 0) {
            $cells[4] = '10';
            $cells[5] = '250';
        }
        $filled[] = implode(',', array_map(fn ($c) => str_contains((string) $c, ',') ? '"'.$c.'"' : $c, $cells));
    }

    $preview = ($this->previewOf)(UploadedFile::fake()->createWithContent('filled.csv', implode("\n", $filled)."\n"));

    expect($preview['errors'])->toBe([])
        ->and($preview['line_count'])->toBe(1)
        ->and($preview['not_counted'])->toHaveCount(1);

    ($this->asRole)('Owner')->post(route('inventory.opening-stock.import.store'), [
        'token' => $preview['token'], 'hash' => $preview['hash'],
        'as_at' => $preview['as_at'], 'confirm_total' => $preview['total_value_exact'],
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(OpeningStock::count())->toBe(1)
        ->and(StockBatch::count())->toBe(1);
});

it('is closed to Staff', function () {
    ($this->asRole)('Staff')->get(route('inventory.opening-stock.import'))->assertForbidden();
});

it('opens for a Manager', function () {
    ($this->asRole)('Manager')->get(route('inventory.opening-stock.import'))->assertOk();
});
