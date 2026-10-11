<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Enums\WeightSource;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Support\ProductWeight;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier hub slice 3 (Joy, 11 Oct 2026): every product has a weight.
 *
 * - Pre-filled from net content ONLY where the content unit is a mass (g, kg):
 *   400 g net is a 400 g start; 5 L is left empty, no density guess.
 * - Typing a weight sets its source to `manual`; `learned` is reserved for the
 *   slice that learns packed weights from courier hub weights.
 * - A weights sheet fills the whole catalogue: template, preview with
 *   row-level errors, apply, gated with catalogue management.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Scale Co', 'slug' => 'scale-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $unit = fn (string $name, string $abbr) => UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => $name, 'abbreviation' => $abbr]);
    $this->pcs = $unit('Pieces', 'pcs');
    $this->gram = $unit('Gram', 'gm');
    $this->kilo = $unit('Kilogram', 'Kg');
    $this->litre = $unit('Litre', 'L');
    $this->ml = $unit('Millilitre', 'mL');
    $this->category = Category::create(['business_id' => $this->business->id, 'name' => 'Honey', 'slug' => 'honey', 'code' => 'HON']);

    $this->product = fn (string $sku, array $extra = []) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $this->pcs->id,
        'sku' => $sku, 'name' => "Product {$sku}", 'type' => ProductType::FinishedGood, ...$extra,
    ]);

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withHeader('X-Business-Id', (string) $this->business->id);
    };

    $this->sheet = fn (array $lines, string $header = 'sku,name,net_content,weight_grams') => UploadedFile::fake()
        ->createWithContent('weights.csv', $header."\n".implode("\n", $lines)."\n");

    $this->form = fn (array $extra = []) => [
        'sku' => 'HON-500', 'name' => 'Honey 500 g', 'type' => 'finished_good',
        'unit_of_measure_id' => $this->pcs->id, ...$extra,
    ];
});

// ── The pre-fill migration ──────────────────────────────────────────────────

it('pre-fills weight from a mass net content and leaves liquids and counts empty', function () {
    $grams = ($this->product)('G-400', ['content_quantity' => '400', 'content_unit_id' => $this->gram->id]);
    $kilos = ($this->product)('K-1.5', ['content_quantity' => '1.5', 'content_unit_id' => $this->kilo->id]);
    $litre = ($this->product)('L-5', ['content_quantity' => '5', 'content_unit_id' => $this->litre->id]);
    $millilitre = ($this->product)('ML-500', ['content_quantity' => '500', 'content_unit_id' => $this->ml->id]);
    $none = ($this->product)('NONE');
    $typed = ($this->product)('TYPED', ['content_quantity' => '250', 'content_unit_id' => $this->gram->id, 'weight_grams' => 300, 'weight_source' => WeightSource::Manual]);

    $migration = require base_path('app/Modules/Inventory/database/migrations/2026_10_11_300000_every_product_can_carry_a_weight.php');
    $migration->prefill();

    expect($grams->fresh()->weight_grams)->toBe(400)
        ->and($grams->fresh()->weight_source)->toBe(WeightSource::FromNetContent)
        // Matched on the alias, so "Kg" is a kilogram: 1.5 kg is 1,500 g.
        ->and($kilos->fresh()->weight_grams)->toBe(1500)
        // ⚠ No density guess for liquids.
        ->and($litre->fresh()->weight_grams)->toBeNull()
        ->and($litre->fresh()->weight_source)->toBeNull()
        ->and($millilitre->fresh()->weight_grams)->toBeNull()
        ->and($none->fresh()->weight_grams)->toBeNull()
        // A weight somebody typed is never overwritten.
        ->and($typed->fresh()->weight_grams)->toBe(300)
        ->and($typed->fresh()->weight_source)->toBe(WeightSource::Manual);

    // Running it again changes nothing.
    $migration->prefill();
    expect($grams->fresh()->weight_grams)->toBe(400);
});

it('reads a mass unit by any of the seeder aliases, and nothing else as a mass', function () {
    $unit = fn (string $name, string $abbr) => new UnitOfMeasure(['name' => $name, 'abbreviation' => $abbr]);

    expect(ProductWeight::gramsPerUnit($unit('Gram', 'g')))->toBe(1)
        ->and(ProductWeight::gramsPerUnit($unit('Grams', 'gms')))->toBe(1)
        ->and(ProductWeight::gramsPerUnit($unit('Kilo', 'KG')))->toBe(1000)
        ->and(ProductWeight::gramsPerUnit($unit('Litre', 'L')))->toBeNull()
        ->and(ProductWeight::gramsPerUnit($unit('Millilitre', 'mL')))->toBeNull()
        ->and(ProductWeight::fromNetContent('0.25', $unit('Kilogram', 'kg')))->toBe(250)
        ->and(ProductWeight::fromNetContent('5', $unit('Litre', 'L')))->toBeNull()
        ->and(ProductWeight::fromNetContent(null, $unit('Gram', 'g')))->toBeNull();
});

// ── The product dialog ──────────────────────────────────────────────────────

it('marks a typed weight as manual', function () {
    ($this->asRole)('Manager')->post('/inventory/products', ($this->form)(['weight_grams' => 560]))->assertSessionHasNoErrors();

    $product = Product::where('sku', 'HON-500')->sole();

    expect($product->weight_grams)->toBe(560)
        ->and($product->weight_source)->toBe(WeightSource::Manual);
});

it('starts a new product with no weight from its mass net content', function () {
    ($this->asRole)('Manager')->post('/inventory/products', ($this->form)([
        'content_quantity' => '500', 'content_unit_id' => $this->gram->id,
    ]))->assertSessionHasNoErrors();

    $product = Product::where('sku', 'HON-500')->sole();

    expect($product->weight_grams)->toBe(500)
        ->and($product->weight_source)->toBe(WeightSource::FromNetContent);
});

it('leaves a new liquid product with no weight', function () {
    ($this->asRole)('Manager')->post('/inventory/products', ($this->form)([
        'content_quantity' => '5', 'content_unit_id' => $this->litre->id,
    ]))->assertSessionHasNoErrors();

    $product = Product::where('sku', 'HON-500')->sole();

    expect($product->weight_grams)->toBeNull()->and($product->weight_source)->toBeNull();
});

it('turns a weight from net content into a manual one when somebody edits it, and logs the change', function () {
    $product = ($this->product)('HON-500', ['content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => 500, 'weight_source' => WeightSource::FromNetContent]);

    ($this->asRole)('Manager')->put("/inventory/products/{$product->id}", ($this->form)([
        'content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => 540,
    ]))->assertSessionHasNoErrors();

    expect($product->fresh()->weight_grams)->toBe(540)
        ->and($product->fresh()->weight_source)->toBe(WeightSource::Manual)
        ->and(Activity::query()->where('subject_id', $product->id)->latest('id')->first()?->properties['attributes']['weight_grams'] ?? null)->toBe(540);
});

it('keeps the source when the dialog is saved with the weight untouched', function () {
    $product = ($this->product)('HON-500', ['content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => 500, 'weight_source' => WeightSource::FromNetContent]);

    ($this->asRole)('Manager')->put("/inventory/products/{$product->id}", ($this->form)([
        'name' => 'Honey 500 g jar', 'content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => 500,
    ]))->assertSessionHasNoErrors();

    expect($product->fresh()->weight_source)->toBe(WeightSource::FromNetContent);
});

it('follows a corrected net content while the weight still came from it', function () {
    $product = ($this->product)('HON-500', ['content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => 500, 'weight_source' => WeightSource::FromNetContent]);

    ($this->asRole)('Manager')->put("/inventory/products/{$product->id}", ($this->form)([
        'content_quantity' => '1', 'content_unit_id' => $this->kilo->id, 'weight_grams' => 500,
    ]))->assertSessionHasNoErrors();

    expect($product->fresh()->weight_grams)->toBe(1000)
        ->and($product->fresh()->weight_source)->toBe(WeightSource::FromNetContent);
});

it('never moves a typed weight when the net content changes', function () {
    $product = ($this->product)('HON-500', ['content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => 560, 'weight_source' => WeightSource::Manual]);

    ($this->asRole)('Manager')->put("/inventory/products/{$product->id}", ($this->form)([
        'content_quantity' => '1', 'content_unit_id' => $this->kilo->id, 'weight_grams' => 560,
    ]))->assertSessionHasNoErrors();

    expect($product->fresh()->weight_grams)->toBe(560)->and($product->fresh()->weight_source)->toBe(WeightSource::Manual);
});

it('clears the weight and its source when the box is emptied', function () {
    $product = ($this->product)('HON-500', ['content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => 500, 'weight_source' => WeightSource::FromNetContent]);

    ($this->asRole)('Manager')->put("/inventory/products/{$product->id}", ($this->form)([
        'content_quantity' => '500', 'content_unit_id' => $this->gram->id, 'weight_grams' => '',
    ]))->assertSessionHasNoErrors();

    expect($product->fresh()->weight_grams)->toBeNull()->and($product->fresh()->weight_source)->toBeNull();
});

it('refuses a weight that is not whole grams', function () {
    ($this->asRole)('Manager')->post('/inventory/products', ($this->form)(['weight_grams' => '12.5']))->assertSessionHasErrors('weight_grams');
    ($this->asRole)('Manager')->post('/inventory/products', ($this->form)(['weight_grams' => '0']))->assertSessionHasErrors('weight_grams');

    expect(Product::count())->toBe(0);
});

// ── The products list ───────────────────────────────────────────────────────

it('lists the products with no weight', function () {
    ($this->product)('HAS', ['weight_grams' => 400, 'weight_source' => WeightSource::Manual]);
    ($this->product)('MISSING');

    $rows = ($this->asRole)('Manager')->get('/inventory/products?weight=missing')
        ->original->getData()['page']['props']['products']['data'];

    expect(collect($rows)->pluck('sku')->all())->toBe(['MISSING'])
        ->and($rows[0]['weight_grams'])->toBeNull();
});

// ── The catalogue file ──────────────────────────────────────────────────────

it('carries weight_grams through the catalogue file, as a manual weight, and a blank leaves it alone', function () {
    ($this->product)('KEEP', ['unit_of_measure_id' => $this->pcs->id, 'category_id' => $this->category->id, 'weight_grams' => 700, 'weight_source' => WeightSource::FromNetContent]);

    $csv = "sku,name,category,unit,unit_abbr,type,weight_grams\n"
        ."NEW-1,New one,Honey,Pieces,pcs,finished_good,450\n"
        ."KEEP,Product KEEP,Honey,Pieces,pcs,finished_good,\n";

    $preview = ($this->asRole)('Owner')->post(route('inventory.products.import.preview'), [
        'file' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv),
    ])->original->getData()['page']['props']['preview'];

    expect($preview['errors'])->toBe([])->and($preview['updates'])->toBe([]);

    ($this->asRole)('Owner')->post(route('inventory.products.import.store'), ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertSessionHasNoErrors();

    expect(Product::where('sku', 'NEW-1')->sole()->weight_grams)->toBe(450)
        ->and(Product::where('sku', 'NEW-1')->sole()->weight_source)->toBe(WeightSource::Manual)
        ->and(Product::where('sku', 'KEEP')->sole()->weight_grams)->toBe(700)
        ->and(Product::where('sku', 'KEEP')->sole()->weight_source)->toBe(WeightSource::FromNetContent);
});

it('refuses a catalogue weight that is not whole grams', function () {
    $csv = "sku,name,category,unit,unit_abbr,type,weight_grams\nNEW-1,New one,Honey,Pieces,pcs,finished_good,450g\n";

    $preview = ($this->asRole)('Owner')->post(route('inventory.products.import.preview'), [
        'file' => UploadedFile::fake()->createWithContent('catalogue.csv', $csv),
    ])->original->getData()['page']['props']['preview'];

    expect($preview['errors'])->toHaveCount(1)
        ->and($preview['errors'][0]['message'])->toContain('weight_grams');
});

// ── The weights sheet ───────────────────────────────────────────────────────

it('downloads every active stocked product with its current weight', function () {
    ($this->product)('A-1', ['weight_grams' => 400, 'weight_source' => WeightSource::Manual, 'content_quantity' => '400', 'content_unit_id' => $this->gram->id]);
    ($this->product)('B-2', ['content_quantity' => '5', 'content_unit_id' => $this->litre->id]);
    ($this->product)('GONE', ['is_active' => false]);
    ($this->product)('SVC', ['type' => ProductType::Service]);

    $csv = ($this->asRole)('Manager')->get(route('inventory.products.weights.template'))->streamedContent();
    $lines = explode("\n", trim(substr($csv, 3)));

    expect(substr($csv, 0, 3))->toBe(chr(0xEF).chr(0xBB).chr(0xBF))
        ->and($lines[0])->toBe('sku,name,net_content,weight_grams')
        ->and($lines)->toHaveCount(3)
        ->and($lines[1])->toBe('A-1,"Product A-1","400 gm",400')
        ->and($lines[2])->toBe('B-2,"Product B-2","5 L",');
});

it('previews a weights sheet without writing, then applies it as manual weights', function () {
    $a = ($this->product)('A-1');
    $b = ($this->product)('B-2', ['weight_grams' => 900, 'weight_source' => WeightSource::FromNetContent]);
    $c = ($this->product)('C-3', ['weight_grams' => 300, 'weight_source' => WeightSource::Manual]);

    $preview = ($this->asRole)('Manager')->post(route('inventory.products.weights.preview'), [
        'file' => ($this->sheet)(['a-1,Product A-1,,450', 'B-2,Product B-2,,950', 'C-3,Product C-3,,300', 'D-missing-row-is-fine-blank,,,']),
    ])->original->getData()['page']['props']['preview'];

    // The unknown SKU is an error even with a blank weight: the sheet never creates products.
    expect($preview['errors'])->toHaveCount(1);

    $preview = ($this->asRole)('Manager')->post(route('inventory.products.weights.preview'), [
        'file' => ($this->sheet)(['a-1,Product A-1,,450', 'B-2,Product B-2,,950', 'C-3,Product C-3,,300']),
    ])->original->getData()['page']['props']['preview'];

    expect($preview['errors'])->toBe([])
        ->and(collect($preview['changes'])->pluck('to', 'sku')->all())->toBe(['A-1' => 450, 'B-2' => 950])
        ->and($preview['unchanged'])->toBe(1)
        // ⚠ The preview wrote nothing.
        ->and($a->fresh()->weight_grams)->toBeNull()
        ->and($b->fresh()->weight_grams)->toBe(900);

    ($this->asRole)('Manager')->post(route('inventory.products.weights.store'), ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('inventory.products.weights'));

    expect($a->fresh()->weight_grams)->toBe(450)
        ->and($a->fresh()->weight_source)->toBe(WeightSource::Manual)
        ->and($b->fresh()->weight_grams)->toBe(950)
        ->and($b->fresh()->weight_source)->toBe(WeightSource::Manual)
        ->and($c->fresh()->weight_source)->toBe(WeightSource::Manual);
});

it('lists every bad row and refuses to apply while any remain', function () {
    $a = ($this->product)('A-1');
    ($this->product)('B-2');

    $preview = ($this->asRole)('Manager')->post(route('inventory.products.weights.preview'), [
        'file' => ($this->sheet)([
            'A-1,,,450g',
            'B-2,,,0.45',
            'ZZZ,,,100',
            'A-1,,,500',
            ',,,100',
        ]),
    ])->original->getData()['page']['props']['preview'];

    $messages = collect($preview['errors'])->map(fn ($e) => "{$e['line']}:{$e['message']}")->all();

    expect($preview['errors'])->toHaveCount(5)
        ->and($messages[0])->toStartWith('2:weight_grams "450g"')
        ->and($messages[1])->toStartWith('3:weight_grams "0.45"')
        ->and($messages[2])->toStartWith('4:No product with this SKU')
        ->and($messages[3])->toStartWith('5:This SKU is already on line 2')
        ->and($messages[4])->toStartWith('6:The row has no SKU');

    ($this->asRole)('Manager')->post(route('inventory.products.weights.store'), ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertSessionHasErrors('file');

    expect($a->fresh()->weight_grams)->toBeNull();
});

it('refuses a sheet with an unrecognised column rather than reading it as blanks', function () {
    ($this->asRole)('Manager')->post(route('inventory.products.weights.preview'), [
        'file' => ($this->sheet)(['A-1,450'], 'sku,weight'),
    ])->assertSessionHasErrors('file');
});

it('refuses a file that changed since the preview', function () {
    ($this->product)('A-1');

    $preview = ($this->asRole)('Manager')->post(route('inventory.products.weights.preview'), [
        'file' => ($this->sheet)(['A-1,,,450']),
    ])->original->getData()['page']['props']['preview'];

    Storage::disk('local')->put('imports/'.$this->business->id.'/'.$preview['token'].'.csv', "sku,weight_grams\nA-1,9999\n");

    ($this->asRole)('Manager')->post(route('inventory.products.weights.store'), ['token' => $preview['token'], 'hash' => $preview['hash']])
        ->assertSessionHasErrors('file');

    expect(Product::where('sku', 'A-1')->sole()->weight_grams)->toBeNull();
});

it('is open to catalogue managers only', function () {
    ($this->asRole)('Staff')->get(route('inventory.products.weights'))->assertForbidden();
    ($this->asRole)('Staff')->get(route('inventory.products.weights.template'))->assertForbidden();
    ($this->asRole)('Staff')->post(route('inventory.products.weights.preview'), ['file' => ($this->sheet)(['A-1,,,450'])])->assertForbidden();

    $page = ($this->asRole)('Manager')->get(route('inventory.products.weights'));
    $page->assertOk();
    expect($page->original->getData()['page']['props']['summary'])->toBe(['products' => 0, 'missing' => 0]);
});

it('never reaches another business\'s products from the weights sheet', function () {
    $other = Business::create(['name' => 'Other Scale Co', 'slug' => 'other-scale-co']);
    $otherUnit = UnitOfMeasure::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $theirs = Product::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'unit_of_measure_id' => $otherUnit->id,
        'sku' => 'THEIRS-1', 'name' => 'Not ours', 'type' => ProductType::FinishedGood,
    ]);

    $csv = ($this->asRole)('Manager')->get(route('inventory.products.weights.template'))->streamedContent();
    expect($csv)->not->toContain('THEIRS-1');

    $preview = ($this->asRole)('Manager')->post(route('inventory.products.weights.preview'), [
        'file' => ($this->sheet)(['THEIRS-1,,,450']),
    ])->original->getData()['page']['props']['preview'];

    expect($preview['errors'])->toHaveCount(1)
        ->and($preview['errors'][0]['message'])->toContain('No product with this SKU exists in this business')
        ->and(Product::withoutGlobalScopes()->find($theirs->id)->weight_grams)->toBeNull();
});
