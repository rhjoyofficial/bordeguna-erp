<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Support\Str;

/**
 * Stage 3 of the onboarding: the product catalogue importer.
 *
 * ⚠⚠ THE RULE THIS FILE ENFORCES: no invented data, and no partial imports.
 * Master data entered wrongly at go-live is wrong for ever - opening stock
 * costs become the FIFO layers that decide profit on every sale for months - so
 * the importer's job is to refuse a file it does not fully understand, not to
 * do its best with it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Catalogue Co', 'slug' => 'catalogue-co']);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-co']);

    $this->file = function (string $body): string {
        $path = tempnam(sys_get_temp_dir(), 'cat').'.csv';
        file_put_contents($path, $body);
        $this->paths[] = $path;

        return $path;
    };

    $this->paths = [];

    $this->header = "sku,name,category,unit,unit_abbr,type,brand,barcode,description,low_stock_threshold,is_active\n";

    // ⚠ Brands are RESOLVED, never created by an import (D-4), so a test that
    // uses one has to make it first, exactly as production will.
    $this->makeBrand = function (string $code, string $name) {
        TenantScope::enter($this->business->id);

        try {
            return Brand::create([
                'business_id' => $this->business->id,
                'code' => $code, 'name' => $name, 'slug' => Str::slug($name),
            ]);
        } finally {
            TenantScope::leave();
        }
    };

    // ⚠ Applies by default, because most of these tests are about what ends up
    // in the database. The default-is-safe behaviour has its own test below,
    // which builds the call without --apply at all.
    $this->import = fn (string $body) => $this->artisan('catalogue:import', [
        'file' => ($this->file)($this->header.$body),
        '--business' => 'catalogue-co',
        '--apply' => true,
    ]);

    $this->review = fn (string $body) => $this->artisan('catalogue:import', [
        'file' => ($this->file)($this->header.$body),
        '--business' => 'catalogue-co',
    ]);

    // Everything the importer reads is business-scoped. Assertions run inside
    // the tenant; the COMMAND is expected to enter it on its own.
    $this->inTenant = function (callable $fn) {
        TenantScope::enter($this->business->id);

        try {
            return $fn();
        } finally {
            TenantScope::leave();
        }
    };
});

afterEach(function () {
    foreach ($this->paths ?? [] as $path) {
        @unlink($path);
    }
});

it('creates products, categories and units from a clean file', function () {
    ($this->import)(
        "HON-MG-500,Mustard Flower Honey 500g,Honey,Piece,pcs,finished_good,,8801234567890,Sealed jar,12,1\n".
        "GHE-COW-1000,Cow Ghee 1kg,Ghee,Piece,pcs,finished_good,,,,,\n"
    )->assertExitCode(0);

    ($this->inTenant)(function () {
        expect(Product::count())->toBe(2)
            ->and(Category::count())->toBe(2)
            ->and(UnitOfMeasure::count())->toBe(1);

        $honey = Product::where('sku', 'HON-MG-500')->firstOrFail();

        expect($honey->name)->toBe('Mustard Flower Honey 500g')
            ->and($honey->barcode)->toBe('8801234567890')
            ->and($honey->description)->toBe('Sealed jar')
            ->and($honey->type->value)->toBe('finished_good')
            ->and($honey->is_active)->toBeTrue()
            ->and($honey->category->name)->toBe('Honey')
            ->and($honey->unitOfMeasure->abbreviation)->toBe('pcs');
    });
});

// ⚠⚠ The whole point of the default. A tool whose safe mode needs a flag gets
// run without the flag exactly once.
it('writes nothing without --apply', function () {
    ($this->review)("HON-MG-500,Honey 500g,Honey,Piece,pcs,finished_good,,,,,\n")
        ->expectsOutputToContain('Review only')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(0)
        ->and(Category::count())->toBe(0)
        ->and(UnitOfMeasure::count())->toBe(0));
});

/**
 * ⚠⚠ IDEMPOTENCE, PROVEN BY OBSERVATION. The SKU is the permanent identifier,
 * so the same file applied twice must produce the same catalogue - not a second
 * copy of it.
 */
it('is idempotent: re-applying the same file changes nothing', function () {
    $body = "HON-MG-500,Honey 500g,Honey,Piece,pcs,finished_good,,8801234567890,A jar,12,1\n".
            "GHE-COW-1000,Cow Ghee 1kg,Ghee,Piece,pcs,finished_good,,,,7.5,1\n";

    ($this->import)($body)->assertExitCode(0);
    $firstPass = ($this->inTenant)(fn () => Product::orderBy('sku')->get(['sku', 'name', 'barcode', 'low_stock_threshold'])->toArray());

    ($this->import)($body)->assertExitCode(0);

    ($this->inTenant)(function () use ($firstPass) {
        expect(Product::count())->toBe(2)
            ->and(Category::count())->toBe(2)
            ->and(UnitOfMeasure::count())->toBe(1)
            ->and(Product::orderBy('sku')->get(['sku', 'name', 'barcode', 'low_stock_threshold'])->toArray())
            ->toBe($firstPass);
    });
});

/**
 * ⚠⚠ THE DECIMAL TRAP. low_stock_threshold is decimal(15,4), so the database
 * returns "12.0000" where the file says "12". Compared as strings, every
 * product reports as an update on every run, for ever - and a genuine change
 * becomes invisible in the noise.
 */
it('does not report a decimal threshold as changed when it has not changed', function () {
    $body = "HON-MG-500,Honey 500g,Honey,Piece,pcs,finished_good,,,,12,1\n";

    ($this->import)($body)->assertExitCode(0);

    // ⚠ The exact counts, not just "it succeeded". A run that reported
    // "1 to update" every time would still exit 0 and still be the bug.
    ($this->import)($body)
        ->expectsOutputToContain('0 to create, 0 to update, 1 unchanged')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect((float) Product::first()->low_stock_threshold)->toBe(12.0));
});

it('updates a product when the file genuinely differs', function () {
    ($this->import)("HON-MG-500,Honey 500g,Honey,Piece,pcs,finished_good,,,,12,1\n")->assertExitCode(0);

    ($this->import)("HON-MG-500,Honey 500g jar,Honey,Piece,pcs,finished_good,,8809999999999,,20,1\n")->assertExitCode(0);

    ($this->inTenant)(function () {
        expect(Product::count())->toBe(1);

        $product = Product::first();

        expect($product->name)->toBe('Honey 500g jar')
            ->and($product->barcode)->toBe('8809999999999')
            ->and((float) $product->low_stock_threshold)->toBe(20.0)
            // ⚠ The SKU is permanent: a rename must never create a second product.
            ->and($product->sku)->toBe('HON-MG-500');
    });
});

/**
 * ⚠⚠ BLANK MEANS NULL, DECIDED INSIDE THE IMPORTER. The screen gets this from
 * the framework's global ConvertEmptyStringsToNull middleware; a console
 * command passes through no middleware at all. An empty string would collide on
 * the (business_id, barcode) unique index where NULL repeats freely - so a
 * business that uses no barcodes would fail at its SECOND product.
 */
it('stores blank optional fields as null, so many products may have no barcode', function () {
    ($this->import)(
        "SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,Second,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-3,Third,Honey,Piece,pcs,finished_good,,  ,  ,  ,\n"
    )->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::whereNull('barcode')->count())->toBe(3)
        ->and(Product::where('barcode', '')->count())->toBe(0)
        ->and(Product::whereNull('description')->count())->toBe(3)
        ->and(Product::whereNull('low_stock_threshold')->count())->toBe(3));
});

// ⚠ Case and whitespace must not be able to create a second category or unit.
it('resolves categories and units across spelling and whitespace differences', function () {
    ($this->import)(
        "SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,Second, honey ,Piece,PCS,finished_good,,,,,\n".
        "SKU-3,Third,HONEY,Piece,pcs,finished_good,,,,,\n"
    )->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Category::count())->toBe(1)
        ->and(UnitOfMeasure::count())->toBe(1)
        ->and(Product::count())->toBe(3)
        ->and(Product::pluck('category_id')->unique())->toHaveCount(1));
});

it('reuses a category that already exists rather than renaming it', function () {
    ($this->inTenant)(fn () => Category::create([
        'business_id' => $this->business->id, 'name' => 'Honey', 'slug' => 'honey',
    ]));

    ($this->import)("SKU-1,First,HONEY,Piece,pcs,finished_good,,,,,\n")->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Category::count())->toBe(1)
        ->and(Category::first()->name)->toBe('Honey'));
});

/**
 * ⚠⚠ NO PARTIAL IMPORTS. One bad row means nothing is written - not "the good
 * rows go in and you fix the rest".
 */
it('writes nothing at all when any row fails validation', function () {
    ($this->import)(
        "SKU-1,Perfectly Fine,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,Also Fine,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-3,Bad Type,Honey,Piece,pcs,not_a_type,,,,,\n"
    )->assertExitCode(1);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(0)
        ->and(Category::count())->toBe(0)
        ->and(UnitOfMeasure::count())->toBe(0));
});

it('reports every error at once rather than stopping at the first', function () {
    ($this->import)(
        "SKU-1,,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,No Category,,Piece,pcs,finished_good,,,,,\n".
        "SKU-3,Bad Type,Honey,Piece,pcs,nonsense,,,,,\n"
    )
        ->expectsOutputToContain('name is mandatory')
        ->expectsOutputToContain('category is mandatory')
        ->expectsOutputToContain('is not one of')
        ->assertExitCode(1);
});

/**
 * ⚠⚠ THE REVIEW MUST NOT PROMISE WHAT THE RUN REFUSES. A row that resolved a
 * valid type and unit but failed on something else - a blank name here - used to
 * be listed under "Would create" and counted in the summary, on the very same
 * run that wrote nothing. No wrong data was ever written; the REPORT was wrong,
 * and on this command the report is the whole product.
 */
it('does not count a failed row as something it would create', function () {
    ($this->review)(
        "SKU-1,Perfectly Fine,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-3,Bad Threshold,Honey,Piece,pcs,finished_good,,,,-5,\n"
    )
        ->expectsOutputToContain('1 to create, 0 to update, 0 unchanged, 2 error(s)')
        // ⚠ Asserted on the product NAME, not the SKU: errors are reported as
        // "[SKU-3] ...", so the SKU appears either way and proves nothing. The
        // name is printed only by the "Would create" list.
        ->doesntExpectOutputToContain('Bad Threshold')
        ->expectsOutputToContain('Perfectly Fine')
        ->assertExitCode(1);
});

it('refuses a duplicate sku within the file', function () {
    ($this->import)(
        "SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-1,Again,Honey,Piece,pcs,finished_good,,,,,\n"
    )->expectsOutputToContain('already used on line')->assertExitCode(1);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(0));
});

it('refuses a duplicate barcode within the file', function () {
    ($this->import)(
        "SKU-1,First,Honey,Piece,pcs,finished_good,,8801234567890,,,\n".
        "SKU-2,Second,Honey,Piece,pcs,finished_good,,8801234567890,,,\n"
    )->expectsOutputToContain('already used on line')->assertExitCode(1);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(0));
});

// ⚠⚠ C-CAT-3. Moving a barcode between products is either a repack or a
// mistake, and the importer cannot tell which.
it('refuses a barcode that already belongs to a different product', function () {
    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,,8801234567890,,,\n")->assertExitCode(0);

    ($this->import)("SKU-2,Second,Honey,Piece,pcs,finished_good,,8801234567890,,,\n")
        ->expectsOutputToContain('already belongs to product SKU-1')
        ->assertExitCode(1);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(1));
});

it('lets a product keep its own barcode across runs', function () {
    $body = "SKU-1,First,Honey,Piece,pcs,finished_good,,8801234567890,,,\n";

    ($this->import)($body)->assertExitCode(0);
    ($this->import)($body)->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(1)
        ->and(Product::first()->barcode)->toBe('8801234567890'));
});

// ⚠⚠ C-CAT-2. A unit's abbreviation is printed on every stock figure in the
// system; changing what it stands for is a person's decision, not an import's.
it('refuses to rename an existing unit', function () {
    ($this->inTenant)(fn () => UnitOfMeasure::create([
        'business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs',
    ]));

    ($this->import)("SKU-1,First,Honey,Bottle,pcs,finished_good,,,,,\n")
        ->expectsOutputToContain('already exists as "Piece"')
        ->assertExitCode(1);

    ($this->inTenant)(fn () => expect(UnitOfMeasure::first()->name)->toBe('Piece')
        ->and(Product::count())->toBe(0));
});

it('refuses one unit name arriving under two abbreviations', function () {
    ($this->import)(
        "SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,Second,Honey,Piece,pc,finished_good,,,,,\n"
    )->expectsOutputToContain('is abbreviated')->assertExitCode(1);

    ($this->inTenant)(fn () => expect(UnitOfMeasure::count())->toBe(0));
});

it('refuses one abbreviation covering two different unit names', function () {
    ($this->import)(
        "SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,Second,Honey,Packet,pcs,finished_good,,,,,\n"
    )->expectsOutputToContain('and "Packet" here')->assertExitCode(1);

    ($this->inTenant)(fn () => expect(UnitOfMeasure::count())->toBe(0));
});

// ⚠⚠ An unknown column is an error, not a warning: a file headed `barcodes`
// imports perfectly and silently drops every barcode.
it('refuses a file with a misspelled column', function () {
    $path = ($this->file)(
        "sku,name,category,unit,unit_abbr,type,barcodes\n".
        "SKU-1,First,Honey,Piece,pcs,finished_good,8801234567890\n"
    );

    $this->artisan('catalogue:import', ['file' => $path, '--business' => 'catalogue-co'])
        ->expectsOutputToContain('unrecognised column(s): barcodes')
        ->assertExitCode(1);
});

it('refuses a file missing a mandatory column', function () {
    $path = ($this->file)("sku,name,category,unit,type\nSKU-1,First,Honey,Piece,finished_good\n");

    $this->artisan('catalogue:import', ['file' => $path, '--business' => 'catalogue-co'])
        ->expectsOutputToContain('missing mandatory column(s): unit_abbr')
        ->assertExitCode(1);
});

// ⚠ Excel writes a BOM. Without stripping it the first column reads as
// "\u{FEFF}sku" and the failure gives no hint why.
it('reads a file saved by Excel with a UTF-8 BOM', function () {
    $path = ($this->file)("\u{FEFF}".$this->header."SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n");

    $this->artisan('catalogue:import', ['file' => $path, '--business' => 'catalogue-co', '--apply' => true])
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(1));
});

it('ignores the blank rows a spreadsheet leaves behind', function () {
    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n,,,,,,,,,\n,,,,,,,,,\n")
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(1));
});

// ⚠ C-CAT-1. The file is a statement about the products it contains, not a
// claim to be the whole catalogue.
it('leaves a product absent from the file completely alone', function () {
    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n")->assertExitCode(0);

    ($this->import)("SKU-2,Second,Honey,Piece,pcs,finished_good,,,,,\n")
        ->expectsOutputToContain('left untouched')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(2)
        ->and(Product::where('sku', 'SKU-1')->first()->is_active)->toBeTrue());
});

// ⚠⚠ C-CAT-5, Joy's own trap: a sealed 500g jar sold as `kg` makes stock read
// `12` meaning twelve kilos when twelve jars are held. A warning, never a block.
it('warns when a pack size in the name disagrees with a weight unit', function () {
    ($this->import)("HON-500,Sundarban Honey 500g,Honey,Kilogram,kg,finished_good,,,,,\n")
        ->expectsOutputToContain('if this is a sealed pack')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(1));
});

it('does not warn when a pack size is sold as a piece', function () {
    ($this->import)("HON-500,Sundarban Honey 500g,Honey,Piece,pcs,finished_good,,,,,\n")
        ->doesntExpectOutputToContain('if this is a sealed pack')
        ->assertExitCode(0);
});

it('warns about near-duplicate product names without blocking', function () {
    ($this->import)(
        "SKU-1,Sundarban Honey 500g,Honey,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,Sundarban Honey 500 g,Honey,Piece,pcs,finished_good,,,,,\n"
    )->expectsOutputToContain('nearly identical')->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(2));
});

/**
 * ⚠⚠⚠ BANGLA IS THE REAL DATA, AND EVERY TEST ABOVE IS IN ENGLISH. Bor De Guna's
 * catalogue is written in Bangla, and the first version of looseKey() stripped
 * every character outside `a-z0-9` - so all six products in the first real file
 * reduced to the EMPTY STRING and compared equal to one another. 34 green tests
 * and a green 1237-test suite said nothing; the scratch MySQL run said it on the
 * first screenful.
 */
it('does not call two different Bangla names near-duplicates', function () {
    ($this->import)(
        "HON-MUS-500,সরিষা ফুলের মধু,মধু,Piece,pcs,finished_good,,,,,\n".
        "GHE-COW-1000,খাঁটি গাওয়া ঘি,ঘি,Piece,pcs,finished_good,,,,,\n".
        "MUS-OIL-1000,ঘানি ভাঙা সরিষার তেল,তেল,Piece,pcs,finished_good,,,,,\n"
    )
        ->doesntExpectOutputToContain('nearly identical')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(3)
        ->and(Category::count())->toBe(3));
});

// ⚠⚠ The other half of the same defect: two DIFFERENT Bangla unit names both
// reduced to "" and compared equal, raising a false blocking error about one
// name arriving under two abbreviations. A legitimate file was refused.
it('accepts two different Bangla unit names', function () {
    ($this->import)(
        "HON-MUS-500,সরিষা ফুলের মধু,মধু,পিস,pcs,finished_good,,,,,\n".
        "MUS-OIL-1000,সরিষার তেল,তেল,লিটার,ltr,finished_good,,,,,\n"
    )->assertExitCode(0);

    ($this->inTenant)(fn () => expect(UnitOfMeasure::count())->toBe(2)
        ->and(UnitOfMeasure::pluck('name')->sort()->values()->all())->toBe(['পিস', 'লিটার']));
});

// ⚠ And it still catches a genuine repeat when the script is Bangla.
it('still spots a real Bangla near-duplicate', function () {
    ($this->import)(
        "SKU-1,সরিষা ফুলের মধু ৫০০ গ্রাম,মধু,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,সরিষা ফুলের মধু ৫০০গ্রাম,মধু,Piece,pcs,finished_good,,,,,\n"
    )->expectsOutputToContain('nearly identical')->assertExitCode(0);
});

/**
 * ⚠⚠ C-CAT-5 ON REAL DATA. Joy named this trap himself: a sealed 500g jar sold
 * as `kg` makes stock read 12 meaning twelve kilos when twelve jars are held.
 * The check matched ASCII digits and ASCII unit words only, so on a Bangla
 * catalogue - the only catalogue this business has - it never fired.
 */
it('warns about a Bangla pack size sold by weight', function () {
    ($this->import)("DAT-AJW-500,আজওয়া খেজুর ৫০০ গ্রাম,খেজুর,কেজি,kg,finished_good,,,,,\n")
        ->expectsOutputToContain('if this is a sealed pack')
        ->assertExitCode(0);
});

it('does not warn when a Bangla pack size is sold as a piece', function () {
    ($this->import)("HON-MUS-500,সরিষা ফুলের মধু ৫০০ গ্রাম,মধু,Piece,pcs,finished_good,,,,,\n")
        ->doesntExpectOutputToContain('if this is a sealed pack')
        ->assertExitCode(0);
});

it('resolves Bangla categories across whitespace differences', function () {
    ($this->import)(
        "SKU-1,প্রথম,মধু,Piece,pcs,finished_good,,,,,\n".
        "SKU-2,দ্বিতীয়, মধু ,Piece,pcs,finished_good,,,,,\n"
    )->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Category::count())->toBe(1)
        ->and(Product::pluck('category_id')->unique())->toHaveCount(1));
});

/**
 * ⚠⚠ THE BRAND DIMENSION. LaLaDia and Bionic-Garden are brands inside Bor De
 * Guna, not tenants, so one catalogue carries both and a product names its
 * brand. D-2 settles the hard case: two brands NEVER share a product row, even
 * when the goods look physically identical, so FIFO, pricing and brand
 * profitability all stay unambiguous.
 */
it('sets a brand named by code or by name', function () {
    ($this->makeBrand)('LLD', 'LaLaDia');
    ($this->makeBrand)('BNC', 'Bionic-Garden');

    ($this->import)(
        "HON-MAN-500G,Mangrove Gold Honey 500gm,Honey,Piece,pcs,finished_good,LLD,,,,\n".
        "HON-MAN-500G-BNC,Mangrove Gold Honey 500gm,Honey,Piece,pcs,finished_good,Bionic-Garden,,,,\n"
    )->assertExitCode(0);

    ($this->inTenant)(function () {
        expect(Product::count())->toBe(2)
            ->and(Product::where('sku', 'HON-MAN-500G')->first()->brand->code)->toBe('LLD')
            ->and(Product::where('sku', 'HON-MAN-500G-BNC')->first()->brand->code)->toBe('BNC');
    });
});

/**
 * ⚠⚠ D-1 IN PRACTICE. The same honey, the same price, one per brand. This is the
 * shape the real import takes after Bionic's 500gm jar was given its own
 * permanent SKU, and it must produce TWO products with separate cost layers.
 */
it('keeps the same physical goods as separate products per brand', function () {
    ($this->makeBrand)('LLD', 'LaLaDia');
    ($this->makeBrand)('BNC', 'Bionic-Garden');

    ($this->import)(
        "HON-MAN-500G,Mangrove Gold Honey 500gm,Honey,Piece,pcs,finished_good,LLD,,,,\n".
        "HON-MAN-500G-BNC,Mangrove Gold Honey 500gm,Honey,Piece,pcs,finished_good,BNC,,,,\n"
    )->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(2)
        ->and(Product::pluck('brand_id')->unique())->toHaveCount(2));
});

// ⚠⚠ D-4. Brands are resolved, never created. A typo must stop the run, because
// "Bionc" would split a brand's margin in two and nobody would notice.
it('refuses a brand that does not exist and never creates one', function () {
    ($this->makeBrand)('LLD', 'LaLaDia');

    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,Bionc,,,,\n")
        ->expectsOutputToContain('unknown brand')
        // ⚠ The policy is stated once in the footer, not per row. Asserted on a
        // SHORT phrase: a long line wraps in the console and a wrapped message
        // silently breaks the assertion.
        ->expectsOutputToContain('never created by an import')
        ->assertExitCode(1);

    ($this->inTenant)(fn () => expect(Brand::count())->toBe(1)
        ->and(Product::count())->toBe(0));
});

it('names the brands it does know when refusing an unknown one', function () {
    ($this->makeBrand)('LLD', 'LaLaDia');

    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,NOPE,,,,\n")
        ->expectsOutputToContain('Known: LLD')
        ->assertExitCode(1);
});

// ⚠ D-5. Raw materials have no brand, and that is not an error.
it('accepts a raw material with no brand', function () {
    ($this->import)("SUG-RAW,Cane sugar sack,Sugar,Kilogram,kg,raw_material,,,,,\n")
        ->doesntExpectOutputToContain('has no brand')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::first()->brand_id)->toBeNull());
});

/**
 * ⚠⚠ D-3. A finished good with no brand WARNS and still imports. A hard failure
 * would stop the entire first import on exactly the rows whose brand Joy has not
 * decided yet.
 */
it('warns but does not block when a finished good has no brand', function () {
    ($this->import)("HON-1,Some Honey,Honey,Piece,pcs,finished_good,,,,,\n")
        ->expectsOutputToContain('finished good has no brand')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(1)
        ->and(Product::first()->brand_id)->toBeNull());
});

it('refuses a retired brand for a new product', function () {
    $brand = ($this->makeBrand)('OLD', 'Retired Brand');

    ($this->inTenant)(fn () => $brand->update(['is_active' => false]));

    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,OLD,,,,\n")
        ->expectsOutputToContain('is retired')
        ->assertExitCode(1);
});

// ⚠ Moving a product between brands is a real change and must show in the review.
it('reports a brand change as an update', function () {
    ($this->makeBrand)('LLD', 'LaLaDia');
    ($this->makeBrand)('BNC', 'Bionic-Garden');

    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,LLD,,,,\n")->assertExitCode(0);

    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,BNC,,,,\n")
        ->expectsOutputToContain('brand_id')
        ->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::first()->brand->code)->toBe('BNC'));
});

// ⚠ Idempotence must survive the new column: a brand that has not changed must
// not report as an update on every run.
it('does not report an unchanged brand as a change', function () {
    ($this->makeBrand)('LLD', 'LaLaDia');

    $body = "SKU-1,First,Honey,Piece,pcs,finished_good,LLD,,,,\n";

    ($this->import)($body)->assertExitCode(0);
    ($this->import)($body)
        ->expectsOutputToContain('0 to create, 0 to update, 1 unchanged')
        ->assertExitCode(0);
});

/**
 * ⚠⚠⚠ THE TENANT BOUNDARY. A brand belonging to another business must not be
 * reachable from this import at all - it is not "not allowed", it is not found,
 * because the importer resolves brands inside the tenant.
 */
it('cannot use another business brand', function () {
    TenantScope::enter($this->other->id);
    Brand::create(['business_id' => $this->other->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    TenantScope::leave();

    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,LLD,,,,\n")
        ->expectsOutputToContain('unknown brand')
        ->assertExitCode(1);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(0));
});

it('accepts the documented yes/no spellings for is_active', function () {
    ($this->import)(
        "SKU-1,One,Honey,Piece,pcs,finished_good,,,,,yes\n".
        "SKU-2,Two,Honey,Piece,pcs,finished_good,,,,,NO\n".
        "SKU-3,Three,Honey,Piece,pcs,finished_good,,,,,0\n".
        "SKU-4,Four,Honey,Piece,pcs,finished_good,,,,,\n"
    )->assertExitCode(0);

    ($this->inTenant)(fn () => expect(Product::where('is_active', true)->pluck('sku')->sort()->values()->all())
        ->toBe(['SKU-1', 'SKU-4'])
        ->and(Product::where('is_active', false)->pluck('sku')->sort()->values()->all())
        ->toBe(['SKU-2', 'SKU-3']));
});

it('refuses a value that is neither yes nor no', function () {
    ($this->import)("SKU-1,One,Honey,Piece,pcs,finished_good,,,,,maybe\n")
        ->expectsOutputToContain('is not a yes/no value')
        ->assertExitCode(1);
});

it('refuses a non-numeric or negative threshold', function () {
    ($this->import)(
        "SKU-1,One,Honey,Piece,pcs,finished_good,,,,lots,\n".
        "SKU-2,Two,Honey,Piece,pcs,finished_good,,,,-5,\n"
    )
        ->expectsOutputToContain('is not a number')
        ->expectsOutputToContain('cannot be negative')
        ->assertExitCode(1);
});

/**
 * ⚠⚠⚠ THE COLD-RUN TRAP, fourth occurrence in this project. A console command
 * carries no BusinessContext. Every model the importer touches is
 * business-scoped, and BusinessScope fails closed on `1 = 0` - so a command
 * that did not enter the tenant would read an EMPTY catalogue, conclude every
 * product is new, and write them into no business at all.
 *
 * This test runs exactly as the operator will: no ambient tenant whatsoever.
 */
it('enters the tenant by itself, with no ambient business context', function () {
    app(BusinessContext::class)->forget();

    expect(app(BusinessContext::class)->hasBusiness())->toBeFalse();

    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n")->assertExitCode(0);

    ($this->inTenant)(function () {
        expect(Product::count())->toBe(1)
            ->and(Product::first()->business_id)->toBe($this->business->id);
    });

    // ⚠ And it leaves the tenant behind it. A long-lived process must not
    // inherit this one's business.
    expect(app(BusinessContext::class)->hasBusiness())->toBeFalse();
});

it('imports into the named business and no other', function () {
    ($this->import)("SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n")->assertExitCode(0);

    TenantScope::enter($this->other->id);

    try {
        expect(Product::count())->toBe(0)
            ->and(Category::count())->toBe(0);
    } finally {
        TenantScope::leave();
    }
});

it('refuses to run without a business', function () {
    $path = ($this->file)($this->header."SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n");

    $this->artisan('catalogue:import', ['file' => $path])
        ->expectsOutputToContain('--business is required')
        ->assertExitCode(1);
});

it('refuses a business that does not exist', function () {
    $path = ($this->file)($this->header."SKU-1,First,Honey,Piece,pcs,finished_good,,,,,\n");

    $this->artisan('catalogue:import', ['file' => $path, '--business' => 'no-such-business'])
        ->expectsOutputToContain('No business matches')
        ->assertExitCode(1);
});

// ⚠ Printed, not assumed. The production container's cached config silently
// ignores `docker exec -e DB_DATABASE=`, so the operator must be able to READ
// which database is about to be written.
it('prints the database it is about to work on', function () {
    $path = ($this->file)($this->header);

    $this->artisan('catalogue:import', ['file' => $path, '--business' => 'catalogue-co'])
        ->expectsOutputToContain('Database:')
        ->assertExitCode(0);
});
