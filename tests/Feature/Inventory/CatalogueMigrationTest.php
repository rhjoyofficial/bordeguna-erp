<?php

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\CatalogueMigrationRun;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use Illuminate\Support\Str;

/**
 * The catalogue SKU migration: rename live products to the new
 * BRAND-CATEGORY-ITEM-SIZE scheme, retire what the file no longer carries,
 * create the new pack variants - preview first, everything in one transaction.
 *
 * ⚠⚠ THE RULE THIS FILE ENFORCES: a rename must never become a duplicate. The
 * old importer keys strictly by SKU, so run naively on the new file it would
 * create 23 products and leave 30 stranded under their old codes. Every test
 * here is some variation of "the pairing chose the right live product, or
 * refused loudly".
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor De Guna', 'slug' => 'bor-de-guna']);
    $this->other = Business::create(['name' => 'Rival Co', 'slug' => 'rival-co']);

    $this->paths = [];

    $this->file = function (string $body): string {
        $path = tempnam(sys_get_temp_dir(), 'mig').'.csv';
        file_put_contents($path, $body);
        $this->paths[] = $path;

        return $path;
    };

    $this->header = "sku,brand,name,product_name,variant_title,category,type,unit,unit_abbr,pack_size,is_active\n";

    $this->inTenant = function (callable $fn) {
        TenantScope::enter($this->business->id);

        try {
            return $fn();
        } finally {
            TenantScope::leave();
        }
    };

    ($this->inTenant)(function () {
        $this->bionic = Brand::create([
            'business_id' => $this->business->id,
            'code' => 'BNC', 'name' => 'Bionic', 'slug' => 'bionic',
        ]);
        $this->laladia = Brand::create([
            'business_id' => $this->business->id,
            'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia',
        ]);
        $this->unit = UnitOfMeasure::create(['name' => 'Piece', 'abbreviation' => 'pcs']);
        $this->category = Category::create(['name' => 'Assorted', 'slug' => 'assorted']);
    });

    // A live product as production holds them: old SKU, name carrying an
    // inline pack size, brand by id.
    $this->live = function (string $sku, string $name, ?int $brandId = null, bool $active = true) {
        return ($this->inTenant)(fn () => Product::create([
            'sku' => $sku,
            'name' => $name,
            'type' => 'finished_good',
            'brand_id' => $brandId ?? $this->bionic->id,
            'category_id' => $this->category->id,
            'unit_of_measure_id' => $this->unit->id,
            'is_active' => $active,
        ]));
    };

    $this->row = function (
        string $sku, string $productName, string $variant, string $pack,
        string $brand = 'BNC', string $active = 'active', ?string $name = null
    ): string {
        $name ??= "{$productName} - {$pack}";

        return "{$sku},{$brand},{$name},{$productName},{$variant},Assorted,finished_good,Piece,pcs,{$pack},{$active}\n";
    };

    $this->migrate = fn (string $body, bool $apply = true) => $this->artisan('catalogue:migrate', array_filter([
        'file' => ($this->file)($this->header.$body),
        '--business' => 'bor-de-guna',
        '--apply' => $apply ?: null,
    ]));
});

afterEach(function () {
    foreach ($this->paths ?? [] as $path) {
        @unlink($path);
    }
});

it('renames a live product to the new SKU and creates the new pack variant', function () {
    ($this->live)('SALT-PINK', 'Himalayan Pink Salt (HPS) - 140gm');

    ($this->migrate)(
        ($this->row)('BNC-SLT-PNK-140G', 'Himalayan Pink Salt (HPS)', '140gm', '140 g').
        ($this->row)('BNC-SLT-PNK-1KG', 'Himalayan Pink Salt (HPS)', '1KG', '1 kg')
    )->assertExitCode(0);

    ($this->inTenant)(function () {
        expect(Product::count())->toBe(2)
            ->and(Product::where('sku', 'SALT-PINK')->exists())->toBeFalse();

        $renamed = Product::where('sku', 'BNC-SLT-PNK-140G')->firstOrFail();
        $created = Product::where('sku', 'BNC-SLT-PNK-1KG')->firstOrFail();

        // The renamed row is the SAME product (id preserved - every historical
        // reference survives), wearing the file's name now.
        expect($renamed->name)->toContain('140 g')
            ->and($created->id)->not->toBe($renamed->id);
    });
});

it('writes nothing without --apply, even though the preview executes the full run', function () {
    ($this->live)('SALT-PINK', 'Himalayan Pink Salt (HPS) - 140gm');

    ($this->migrate)(
        ($this->row)('BNC-SLT-PNK-140G', 'Himalayan Pink Salt (HPS)', '140gm', '140 g').
        ($this->row)('BNC-SLT-PNK-1KG', 'Himalayan Pink Salt (HPS)', '1KG', '1 kg'),
        apply: false
    )
        ->expectsOutputToContain('ROLLED BACK')
        ->assertExitCode(0);

    ($this->inTenant)(function () {
        expect(Product::count())->toBe(1)
            ->and(Product::where('sku', 'SALT-PINK')->exists())->toBeTrue()
            ->and(CatalogueMigrationRun::count())->toBe(0);
    });
});

it('pairs graded variants by the old SKU suffix, never crossing Large and Jambo', function () {
    $large = ($this->live)('DATE-MED-LRG', 'Egyptian Medjool (EM)');
    $jambo = ($this->live)('DATE-MED-JAM', 'Egyptian Medjool (EM)');

    ($this->migrate)(
        ($this->row)('BNC-DAT-MED-1KG-LRG', 'Egyptian Medjool (EM)', '1KG Large', '1 kg').
        ($this->row)('BNC-DAT-MED-5KG-LRG', 'Egyptian Medjool (EM)', '5KG Large', '5 kg').
        ($this->row)('BNC-DAT-MED-1KG-JAM', 'Egyptian Medjool (EM)', '1KG Jambo', '1 kg').
        ($this->row)('BNC-DAT-MED-5KG-JAM', 'Egyptian Medjool (EM)', '5KG Jambo', '5 kg')
    )->assertExitCode(0);

    ($this->inTenant)(function () use ($large, $jambo) {
        expect(Product::count())->toBe(4)
            ->and(Product::findOrFail($large->id)->sku)->toBe('BNC-DAT-MED-1KG-LRG')
            ->and(Product::findOrFail($jambo->id)->sku)->toBe('BNC-DAT-MED-1KG-JAM');
    });
});

it('does not read Premium in a product name as a grade', function () {
    // "Kalmi Super Premium" is ungraded - Premium is part of its NAME. The
    // file's 1 kg row must claim it; a grade-word reading would orphan it and
    // create a duplicate.
    $kalmi = ($this->live)('DATE-KAL', 'Kalmi Super Premium (KSP)');

    ($this->migrate)(
        ($this->row)('BNC-DAT-KAL-1KG', 'Kalmi Super Premium (KSP)', '1KG', '1 kg').
        ($this->row)('BNC-DAT-KAL-5KG', 'Kalmi Super Premium (KSP)', '5KG', '5 kg')
    )->assertExitCode(0);

    ($this->inTenant)(function () use ($kalmi) {
        expect(Product::count())->toBe(2)
            ->and(Product::findOrFail($kalmi->id)->sku)->toBe('BNC-DAT-KAL-1KG');
    });
});

it('retires what no row claims - deactivated, never deleted', function () {
    $gone = ($this->live)('MANGO-FAZ', 'Fazli Mango (FM)', $this->laladia->id, active: false);
    $duplicate = ($this->live)('BNC-HON-MAN-500G', 'Mangrove Gold Honey (MGH) 500gm');
    $keeper = ($this->live)('LLD-HON-MAN-500G', 'Mangrove Gold Honey (MGH) 500gm', $this->laladia->id);

    ($this->migrate)(
        ($this->row)('LLD-HON-MAN-500G', 'Mangrove Gold Honey (MGH)', '500gm', '500 g', brand: 'LLD')
    )
        ->expectsOutputToContain('active today and no file row claims it')
        ->assertExitCode(0);

    ($this->inTenant)(function () use ($gone, $duplicate, $keeper) {
        // Still three rows: retire is a flag, not a delete.
        expect(Product::count())->toBe(3)
            ->and(Product::findOrFail($gone->id)->is_active)->toBeFalse()
            ->and(Product::findOrFail($duplicate->id)->is_active)->toBeFalse()
            ->and(Product::findOrFail($duplicate->id)->sku)->toBe('BNC-HON-MAN-500G')
            ->and(Product::findOrFail($keeper->id)->is_active)->toBeTrue();
    });
});

it('skips an inactive row that never existed, but renames an inactive row that did', function () {
    // MANGO-HIM: live and inactive, carried forward under its new SKU, still
    // inactive. The 25 kg pack never existed and is inactive: never created.
    $him = ($this->live)('MANGO-HIM', 'Himsagar Mango (HM)', $this->laladia->id, active: false);

    ($this->migrate)(
        ($this->row)('LLD-FRT-HIM-11KG', 'Himsagar Mango (HM)', '11KG', '11 kg', brand: 'LLD', active: 'inactive').
        ($this->row)('LLD-FRT-HIM-25KG', 'Himsagar Mango (HM)', '25KG', '25 kg', brand: 'LLD', active: 'inactive')
    )->assertExitCode(0);

    ($this->inTenant)(function () use ($him) {
        expect(Product::count())->toBe(1)
            ->and(Product::findOrFail($him->id)->sku)->toBe('LLD-FRT-HIM-11KG')
            ->and(Product::findOrFail($him->id)->is_active)->toBeFalse()
            ->and(Product::where('sku', 'LLD-FRT-HIM-25KG')->exists())->toBeFalse();
    });
});

it('is idempotent: a second run changes nothing', function () {
    ($this->live)('SALT-PINK', 'Himalayan Pink Salt (HPS) - 140gm');

    $body = ($this->row)('BNC-SLT-PNK-140G', 'Himalayan Pink Salt (HPS)', '140gm', '140 g').
        ($this->row)('BNC-SLT-PNK-1KG', 'Himalayan Pink Salt (HPS)', '1KG', '1 kg');

    ($this->migrate)($body)->assertExitCode(0);

    $before = ($this->inTenant)(fn () => Product::orderBy('id')->get(['id', 'sku', 'name', 'is_active'])->toArray());

    ($this->migrate)($body)
        ->expectsOutputToContain('0 rename')
        ->assertExitCode(0);

    ($this->inTenant)(function () use ($before) {
        expect(Product::orderBy('id')->get(['id', 'sku', 'name', 'is_active'])->toArray())->toBe($before);
    });
});

it('lets the exact-SKU rule win over base pairing, so a rename can never collide', function () {
    // A rename's target SKU can only already exist live on the product the
    // file row exact-matches - and the exact-SKU rule claims it first, so the
    // base pairing can never be handed a taken SKU. The collision guard in the
    // planner stays as belt and braces; this pins the precedence that makes it
    // unreachable.
    ($this->live)('HON-A', 'Floral Honey (FH) 255gm');
    ($this->live)('BNC-HON-FLO-255G', 'Completely Different Product');

    ($this->migrate)(
        ($this->row)('BNC-HON-FLO-255G', 'Floral Honey (FH)', '255gm', '255 g')
    )->assertExitCode(0);

    // The exact-SKU rule wins: the file row KEEPS the product already wearing
    // that SKU, and HON-A retires rather than colliding.
    ($this->inTenant)(function () {
        expect(Product::where('sku', 'HON-A')->firstOrFail()->is_active)->toBeFalse()
            ->and(Product::where('sku', 'BNC-HON-FLO-255G')->count())->toBe(1);
    });
});

it('pairs across the Bangla normalisation boundary', function () {
    // Production stores the decomposed form (য + nukta); the spreadsheet the
    // precomposed য়. Identical on screen, never equal as strings. A migration
    // that misses this creates a duplicate of every Bangla-named product.
    $decomposed = "Royal Essence Ghee (\u{09AF}\u{09BC})"; // য + nukta
    $precomposed = "Royal Essence Ghee (\u{09DF})";        // য়

    $ghee = ($this->live)('GHE-ROY-350G', $decomposed, $this->laladia->id);

    ($this->migrate)(
        ($this->row)('LLD-GHE-ROY-350G', $precomposed, '350gm', '350 g', brand: 'LLD')
    )->assertExitCode(0);

    ($this->inTenant)(function () use ($ghee) {
        expect(Product::count())->toBe(1)
            ->and(Product::findOrFail($ghee->id)->sku)->toBe('LLD-GHE-ROY-350G');
    });
});

it('never touches another business', function () {
    TenantScope::enter($this->other->id);

    try {
        $otherUnit = UnitOfMeasure::create(['name' => 'Piece', 'abbreviation' => 'pcs']);
        $rival = Product::create([
            'sku' => 'SALT-PINK',
            'name' => 'Himalayan Pink Salt (HPS) - 140gm',
            'type' => 'finished_good',
            'unit_of_measure_id' => $otherUnit->id,
            'is_active' => true,
        ]);
    } finally {
        TenantScope::leave();
    }

    ($this->live)('SALT-PINK', 'Himalayan Pink Salt (HPS) - 140gm');

    ($this->migrate)(
        ($this->row)('BNC-SLT-PNK-140G', 'Himalayan Pink Salt (HPS)', '140gm', '140 g')
    )->assertExitCode(0);

    TenantScope::enter($this->other->id);

    try {
        expect(Product::findOrFail($rival->id)->sku)->toBe('SALT-PINK');
    } finally {
        TenantScope::leave();
    }
});

it('records the audit trail of an applied run', function () {
    ($this->live)('SALT-PINK', 'Himalayan Pink Salt (HPS) - 140gm');
    ($this->live)('MANGO-FAZ', 'Fazli Mango (FM)', $this->laladia->id, active: false);

    ($this->migrate)(
        ($this->row)('BNC-SLT-PNK-140G', 'Himalayan Pink Salt (HPS)', '140gm', '140 g')
    )->assertExitCode(0);

    ($this->inTenant)(function () {
        $run = CatalogueMigrationRun::sole();

        expect($run->renames_count)->toBe(1)
            ->and($run->retires_count)->toBe(1)
            ->and($run->mapping['renames'][0]['old_sku'])->toBe('SALT-PINK')
            ->and($run->mapping['renames'][0]['new_sku'])->toBe('BNC-SLT-PNK-140G')
            ->and($run->file_hash)->toHaveLength(64);
    });
});

it('tolerates the price columns but refuses a file missing a matching signal', function () {
    ($this->live)('SALT-PINK', 'Himalayan Pink Salt (HPS) - 140gm');

    // The real file carries the price tiers; the migration ignores and reports them.
    $withPrices = "sku,brand,name,product_name,variant_title,category,type,unit,unit_abbr,pack_size,is_active,retail_price,mrp\n".
        "BNC-SLT-PNK-140G,BNC,Himalayan Pink Salt (HPS) - 140 g,Himalayan Pink Salt (HPS),140gm,Assorted,finished_good,Piece,pcs,140 g,active,190,190\n";

    $this->artisan('catalogue:migrate', [
        'file' => ($this->file)($withPrices),
        '--business' => 'bor-de-guna',
    ])
        ->expectsOutputToContain('does not read')
        ->assertExitCode(0);

    // Missing variant_title: every grade pairing would silently degrade, so
    // the file is refused outright.
    $missing = "sku,brand,name,product_name,category,type,unit,unit_abbr,pack_size,is_active\n".
        "BNC-SLT-PNK-140G,BNC,Himalayan Pink Salt (HPS) - 140 g,Himalayan Pink Salt (HPS),Assorted,finished_good,Piece,pcs,140 g,active\n";

    $this->artisan('catalogue:migrate', [
        'file' => ($this->file)($missing),
        '--business' => 'bor-de-guna',
    ])->assertExitCode(1);
});

it('refuses an unknown brand', function () {
    ($this->migrate)(
        ($this->row)('AZM-SLT-PNK-140G', 'Himalayan Pink Salt (HPS)', '140gm', '140 g', brand: 'AZM')
    )
        ->expectsOutputToContain('unknown brand')
        ->assertExitCode(1);

    ($this->inTenant)(fn () => expect(Product::count())->toBe(0));
});
