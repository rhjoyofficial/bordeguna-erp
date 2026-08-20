<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The catalogue importer as a screen.
 *
 * ⚠⚠ WHY THIS EXISTS. The Plan and Apply engines have been tested for weeks and
 * were reachable only from an artisan command on the VPS, so Joy's data could
 * only reach production by being sent to me. The engine is not what is under
 * test here - the screen is, and specifically the three things only the screen
 * can get wrong: that a preview writes nothing, that apply gets the file that
 * was reviewed, and that it is gated.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Import Co', 'slug' => 'product-import-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
    Category::create(['business_id' => $this->business->id, 'name' => 'Honey', 'slug' => 'honey', 'code' => 'HON']);
    Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->csv = function (array $lines): UploadedFile {
        $body = "sku,name,category,unit,unit_abbr,type\n".implode("\n", $lines)."\n";

        return UploadedFile::fake()->createWithContent('catalogue.csv', $body);
    };

    $this->goodRow = 'LLD-HON-MAN-500G,Mangrove Gold Honey (ম্যানগ্রোভ গোল্ড হানি) - 500 g,Honey,Piece,pcs,finished_good';
});

it('previews a file and writes absolutely nothing', function () {
    $before = [Product::count(), Category::count(), UnitOfMeasure::count()];

    $response = ($this->asRole)('Owner')
        ->post(route('inventory.products.import.preview'), ['file' => ($this->csv)([$this->goodRow])]);

    $response->assertOk();
    $preview = $response->original->getData()['page']['props']['preview'];

    expect($preview['creates'])->toHaveCount(1)
        ->and($preview['errors'])->toBe([])
        ->and($preview['hash'])->not->toBeEmpty()
        // ⚠⚠ The whole promise of the middle step, in one assertion.
        ->and([Product::count(), Category::count(), UnitOfMeasure::count()])->toBe($before);
});

it('imports the file that was previewed', function () {
    $preview = ($this->asRole)('Owner')
        ->post(route('inventory.products.import.preview'), ['file' => ($this->csv)([$this->goodRow])])
        ->original->getData()['page']['props']['preview'];

    ($this->asRole)('Owner')
        ->post(route('inventory.products.import'), ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertRedirect(route('inventory.products.index'));

    expect(Product::where('sku', 'LLD-HON-MAN-500G')->exists())->toBeTrue();
});

it('refuses to import a file that changed since the preview', function () {
    // ⚠⚠ C-IMP-2, and the reason the hash exists. Two tabs, two files: without
    // this the screen shows a truthful preview of one file and applies another,
    // and nobody could tell afterwards which had landed.
    $preview = ($this->asRole)('Owner')
        ->post(route('inventory.products.import.preview'), ['file' => ($this->csv)([$this->goodRow])])
        ->original->getData()['page']['props']['preview'];

    // Someone swaps the bytes underneath, keeping the same token.
    $path = Storage::disk('local')->path('imports/'.$this->business->id.'/'.$preview['token'].'.csv');
    file_put_contents($path, "sku,name,category,unit,unit_abbr,type\nLLD-HON-XXX-1KG,Something Else,Honey,Piece,pcs,finished_good\n");

    ($this->asRole)('Owner')
        ->post(route('inventory.products.import'), ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertSessionHasErrors('file');

    expect(Product::count())->toBe(0);
});

it('refuses a token that is not a plain uuid', function () {
    // The token is concatenated into a path, so anything shaped like a traversal
    // is refused before it gets near the filesystem.
    ($this->asRole)('Owner')
        ->post(route('inventory.products.import'), ['token' => '../../../etc/passwd', 'hash' => str_repeat('a', 64)])
        ->assertSessionHasErrors('file');
});

it('shows every bad row rather than stopping at the first', function () {
    $response = ($this->asRole)('Owner')->post(route('inventory.products.import.preview'), [
        'file' => ($this->csv)([
            'LLD-A-1,First (nope),Honey,Piece,pcs,not_a_type',
            'LLD-A-2,Second (nope),Honey,Piece,pcs,also_wrong',
            $this->goodRow,
        ]),
    ]);

    $preview = $response->original->getData()['page']['props']['preview'];

    // A review that reveals one problem per run is a review nobody finishes.
    expect($preview['errors'])->toHaveCount(2);
});

it('refuses to apply while the file still has errors', function () {
    $preview = ($this->asRole)('Owner')->post(route('inventory.products.import.preview'), [
        'file' => ($this->csv)(['LLD-A-1,Broken (nope),Honey,Piece,pcs,not_a_type']),
    ])->original->getData()['page']['props']['preview'];

    ($this->asRole)('Owner')
        ->post(route('inventory.products.import'), ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertSessionHasErrors('file');

    expect(Product::count())->toBe(0);
});

it('reports what the file does not mention, and touches none of it', function () {
    // C-CAT-1: a file is a statement about the rows it contains, not a claim to
    // be the whole catalogue. Nothing is deactivated by an import.
    $existing = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => UnitOfMeasure::first()->id,
        'sku' => 'LLD-OLD-ONE-1KG', 'name' => 'Untouched', 'type' => 'finished_good', 'is_active' => true,
    ]);

    $preview = ($this->asRole)('Owner')
        ->post(route('inventory.products.import.preview'), ['file' => ($this->csv)([$this->goodRow])])
        ->original->getData()['page']['props']['preview'];

    expect($preview['absent_from_file'])->toContain('LLD-OLD-ONE-1KG');

    ($this->asRole)('Owner')
        ->post(route('inventory.products.import'), ['token' => $preview['token'], 'hash' => $preview['hash']]);

    expect($existing->fresh()->is_active)->toBeTrue();
});

it('exports in exactly the shape the importer reads', function () {
    Product::create([
        'business_id' => $this->business->id,
        'category_id' => Category::first()->id,
        'brand_id' => Brand::first()->id,
        'unit_of_measure_id' => UnitOfMeasure::first()->id,
        'sku' => 'LLD-HON-MAN-500G',
        'name' => 'Mangrove Gold Honey (ম্যানগ্রোভ গোল্ড হানি) - 500 g',
        'type' => 'finished_good',
    ]);

    $csv = ($this->asRole)('Owner')->get(route('inventory.products.export'))->streamedContent();

    // ⚠⚠ The BOM matters: without it Excel on Windows shows every Bangla name as
    // mojibake, and the file comes back with corrupted names that import as NEW
    // products rather than updating the ones they came from.
    expect(substr($csv, 0, 3))->toBe(chr(0xEF).chr(0xBB).chr(0xBF));

    $lines = explode("\n", trim(substr($csv, 3)));

    expect($lines[0])->toBe('sku,name,category,unit,unit_abbr,type,brand,barcode,description,low_stock_threshold,is_active');

    // C-IMP-5: the round trip. Feed the export straight back in and it must
    // recognise its own products rather than proposing to create them again.
    $preview = ($this->asRole)('Owner')->post(route('inventory.products.import.preview'), [
        'file' => UploadedFile::fake()->createWithContent('roundtrip.csv', $csv),
    ])->original->getData()['page']['props']['preview'];

    expect($preview['errors'])->toBe([])
        ->and($preview['creates'])->toBe([])
        ->and($preview['updates'])->toBe([])
        ->and($preview['unchanged'])->toBe(1);
});

it('is closed to Staff, who may record stock but not rewrite the catalogue', function () {
    ($this->asRole)('Staff')->get(route('inventory.products.import'))->assertForbidden();
    ($this->asRole)('Staff')
        ->post(route('inventory.products.import.preview'), ['file' => ($this->csv)([$this->goodRow])])
        ->assertForbidden();
});

it('opens for a Manager', function () {
    ($this->asRole)('Manager')->get(route('inventory.products.import'))->assertOk();
});
