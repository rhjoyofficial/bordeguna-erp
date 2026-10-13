<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The price importer as a screen.
 *
 * ⚠⚠ The engine is already tested. What only the screen can get wrong is the
 * EFFECTIVE DATE: it decides which rules in force get closed, so the same file
 * previewed for two dates is two different outcomes. It has to be bound to the
 * preview exactly as tightly as the file's own bytes are.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Price Co', 'slug' => 'price-import-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    $this->retail = PriceGroup::create([
        'business_id' => $this->business->id, 'code' => 'RETAIL', 'name' => 'Retail', 'is_active' => true,
    ]);
    PriceGroup::create([
        'business_id' => $this->business->id, 'code' => 'DEALER', 'name' => 'Dealer', 'is_active' => true,
    ]);

    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'LLD-DRY-CHU-125G',
        'name' => 'Churi Shutki (ছুরি শুটকি) - 125 g',
        'type' => 'finished_good',
        'is_active' => true,
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->csv = fn (array $lines) => UploadedFile::fake()->createWithContent(
        'prices.csv',
        "sku,retail_price\n".implode("\n", $lines)."\n"
    );

    $this->previewOf = fn (UploadedFile $file, string $on) => ($this->asRole)('Owner')
        ->post(route('sales.prices.import.preview'), ['file' => $file, 'effective_from' => $on])
        ->original->getData()['page']['props']['preview'];
});

it('previews a price file and writes nothing', function () {
    $preview = ($this->previewOf)(($this->csv)(['LLD-DRY-CHU-125G,340']), '2026-09-01');

    expect($preview['creates'])->toHaveCount(1)
        ->and($preview['errors'])->toBe([])
        ->and($preview['effective_from'])->toBe('2026-09-01')
        ->and(ProductPrice::count())->toBe(0);
});

it('imports the reviewed file on the reviewed date', function () {
    $preview = ($this->previewOf)(($this->csv)(['LLD-DRY-CHU-125G,340']), '2026-09-01');

    ($this->asRole)('Owner')->post(route('sales.prices.import'), [
        'token' => $preview['token'], 'hash' => $preview['hash'], 'effective_from' => $preview['effective_from'],
    ])->assertRedirect(route('sales.prices.index'));

    $rule = ProductPrice::first();

    expect((float) $rule->unit_price)->toBe(340.0)
        ->and($rule->effective_from->toDateString())->toBe('2026-09-01');
});

it('refuses a file that changed since the preview', function () {
    $preview = ($this->previewOf)(($this->csv)(['LLD-DRY-CHU-125G,340']), '2026-09-01');

    $path = Storage::disk('local')->path('imports/'.$this->business->id.'/'.$preview['token'].'.csv');
    file_put_contents($path, "sku,retail_price\nLLD-DRY-CHU-125G,99999\n");

    ($this->asRole)('Owner')->post(route('sales.prices.import'), [
        'token' => $preview['token'], 'hash' => $preview['hash'], 'effective_from' => $preview['effective_from'],
    ])->assertSessionHasErrors('file');

    expect(ProductPrice::count())->toBe(0);
});

it('carries quantity breaks through, which is how a cheaper 8-pack is priced', function () {
    // ⚠ Joy's dry fish: only the 125 g pack is stocked, and 0.5 kg or 1 kg is 4
    // or 8 of them at a lower price each. That is a quantity break, not a
    // separate product and not a manual discount.
    $file = UploadedFile::fake()->createWithContent('breaks.csv',
        "sku,min_quantity,retail_price\nLLD-DRY-CHU-125G,1,340\nLLD-DRY-CHU-125G,4,340\nLLD-DRY-CHU-125G,8,315\n");

    $preview = ($this->previewOf)($file, '2026-09-01');

    ($this->asRole)('Owner')->post(route('sales.prices.import'), [
        'token' => $preview['token'], 'hash' => $preview['hash'], 'effective_from' => $preview['effective_from'],
    ])->assertRedirect();

    expect(ProductPrice::count())->toBe(3)
        ->and(ProductPrice::orderBy('min_quantity')->pluck('min_quantity')->map(fn ($q) => (float) $q)->all())
        ->toBe([1.0, 4.0, 8.0])
        ->and((float) ProductPrice::where('min_quantity', 8)->value('unit_price'))->toBe(315.0);
});

it('closes the old rule the day before rather than editing it', function () {
    // ⚠⚠ A price that once applied is a fact about the past. Superseding writes
    // a new row and closes the old one; it never rewrites history.
    $first = ($this->previewOf)(($this->csv)(['LLD-DRY-CHU-125G,340']), '2026-09-01');
    ($this->asRole)('Owner')->post(route('sales.prices.import'), [
        'token' => $first['token'], 'hash' => $first['hash'], 'effective_from' => $first['effective_from'],
    ]);

    $second = ($this->previewOf)(($this->csv)(['LLD-DRY-CHU-125G,360']), '2026-10-01');

    expect($second['supersedes'])->toHaveCount(1)
        ->and($second['supersedes'][0]['closesOn'])->toBe('2026-09-30');

    ($this->asRole)('Owner')->post(route('sales.prices.import'), [
        'token' => $second['token'], 'hash' => $second['hash'], 'effective_from' => $second['effective_from'],
    ]);

    expect(ProductPrice::count())->toBe(2)
        ->and(ProductPrice::whereNotNull('effective_to')->first()->effective_to->toDateString())->toBe('2026-09-30')
        ->and((float) ProductPrice::whereNull('effective_to')->first()->unit_price)->toBe(360.0);
});

it('says which products still cannot be sold after the import', function () {
    Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => UnitOfMeasure::first()->id,
        'sku' => 'LLD-DRY-LOI-125G', 'name' => 'Loitta Shutki - 125 g', 'type' => 'finished_good', 'is_active' => true,
    ]);

    $preview = ($this->previewOf)(($this->csv)(['LLD-DRY-CHU-125G,340']), '2026-09-01');

    // A product with no price does not fall back to anything; the sale is
    // refused. The number must never be a surprise.
    expect($preview['still_unpriced'])->toContain('LLD-DRY-LOI-125G');
});

it('exports prices in force in the shape the importer reads back', function () {
    $preview = ($this->previewOf)(($this->csv)(['LLD-DRY-CHU-125G,340']), '2026-09-01');
    ($this->asRole)('Owner')->post(route('sales.prices.import'), [
        'token' => $preview['token'], 'hash' => $preview['hash'], 'effective_from' => $preview['effective_from'],
    ]);

    $csv = ($this->asRole)('Owner')->get(route('sales.prices.export'))->streamedContent();

    expect(substr($csv, 0, 3))->toBe(chr(0xEF).chr(0xBB).chr(0xBF));

    $lines = explode("\n", trim(substr($csv, 3)));

    expect($lines[0])->toBe('sku,product_name,min_quantity,retail_price,dealer_price')
        ->and($lines[1])->toContain('LLD-DRY-CHU-125G');

    // The round trip: feed it back on the same date and nothing should change.
    $again = ($this->previewOf)(UploadedFile::fake()->createWithContent('rt.csv', $csv), '2026-09-01');

    expect($again['errors'])->toBe([])
        ->and($again['creates'])->toBe([])
        ->and($again['supersedes'])->toBe([]);
});

it('is closed to Staff', function () {
    ($this->asRole)('Staff')->get(route('sales.prices.import'))->assertForbidden();
});

it('opens for a Manager', function () {
    ($this->asRole)('Manager')->get(route('sales.prices.import'))->assertOk();
});

/**
 * ⚠⚠ Audit, 13 Oct 2026: the export route had no permission at all.
 */
it('refuses the price export to a login that may not read the price screen', function () {
    Role::create(['name' => 'Self-service', 'guard_name' => 'web', 'business_id' => $this->business->id]);

    ($this->asRole)('Self-service')->get(route('sales.prices.export'))->assertForbidden();
});
