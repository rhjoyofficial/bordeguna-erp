<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\ApplyPriceListImport;
use App\Modules\Sales\Actions\PlanPriceListImport;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\PriceListFile;
use Illuminate\Support\Carbon;

/**
 * The bulk price importer. 56 products across four tiers is 224 rules, and the
 * only way to enter one before this was a form.
 *
 * ⚠ Every test drives the REAL actions, and the economic assertions go through
 * `ResolveSellingPrice` rather than reading the rows back. A price that exists
 * in the table but never resolves is the module's defining failure shape, and
 * counting rows would not catch it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-08-13 10:00:00');

    $this->business = Business::create(['name' => 'Price Co', 'slug' => 'price-co']);
    TenantScope::enter($this->business->id);

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->product = function (string $sku, string $name, bool $active = true) use ($unit) {
        return Product::create([
            'business_id' => $this->business->id,
            'unit_of_measure_id' => $unit->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::FinishedGood,
            'is_active' => $active,
        ]);
    };

    foreach ([['RETAIL', 'Retail'], ['DEALER', 'Dealer'], ['DISTRIBUTOR', 'Distributor'], ['CORPORATE', 'Corporate']] as [$code, $name]) {
        PriceGroup::create([
            'business_id' => $this->business->id,
            'code' => $code,
            'name' => $name,
            'prices_include_tax' => false,
            'is_active' => true,
        ]);
    }

    $this->write = function (string $csv): string {
        $path = tempnam(sys_get_temp_dir(), 'prices').'.csv';
        file_put_contents($path, $csv);

        return $path;
    };

    $this->import = function (string $csv, string $on = '2026-09-01') {
        $path = ($this->write)($csv);
        ['rows' => $rows, 'ignored' => $ignored] = PriceListFile::read($path);
        $plan = app(PlanPriceListImport::class)->handle($rows, $ignored, Carbon::parse($on));
        @unlink($path);

        return $plan;
    };

    $this->apply = fn ($plan, string $on = '2026-09-01') => app(ApplyPriceListImport::class)
        ->handle($plan, Carbon::parse($on), $this->user);
});

afterEach(fn () => Carbon::setTestNow());

it('imports four tiers for one product and every tier resolves', function () {
    ($this->product)('BNC-SLT-PNK-140G', 'Pink Salt 140g');

    $plan = ($this->import)(
        "sku,retail_price,dealer_price,distributor_price,corporate_price\n".
        "BNC-SLT-PNK-140G,190,170,160,150\n"
    );

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toHaveCount(4);

    ($this->apply)($plan);

    // ⚠ Resolved through the real engine, per group, not counted in the table.
    $resolver = app(ResolveSellingPrice::class);
    $product = Product::where('sku', 'BNC-SLT-PNK-140G')->sole();

    foreach (['RETAIL' => '190', 'DEALER' => '170', 'DISTRIBUTOR' => '160', 'CORPORATE' => '150'] as $code => $expected) {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => "Buyer {$code}",
            'price_group_id' => PriceGroup::where('code', $code)->value('id'),
        ]);

        $resolved = $resolver->resolve($product, $customer, '1', Carbon::parse('2026-09-01'));

        expect($resolved)->not->toBeNull()
            ->and((float) $resolved->unitPrice)->toBe((float) $expected);
    }
});

it('covers a customer in no price group at all, through RETAIL', function () {
    // C-PRI-5. `groupIdFor()` falls an ungrouped customer back to RETAIL, so the
    // four tier rows already price walk-in. If that were ever untrue, every
    // counter sale would throw and no row count would have shown it.
    ($this->product)('SKU-1', 'Thing');

    ($this->apply)(($this->import)("sku,retail_price\nSKU-1,250\n"));

    $walkIn = Customer::create(['business_id' => $this->business->id, 'name' => 'Walk-in', 'price_group_id' => null]);

    $resolved = app(ResolveSellingPrice::class)->find(
        Product::where('sku', 'SKU-1')->sole(), $walkIn, '1', Carbon::parse('2026-09-01')
    );

    expect($resolved)->not->toBeNull()
        ->and((float) $resolved->unitPrice)->toBe(250.0);
});

it('treats a blank price as no rule, never as zero', function () {
    // C-PRI-3. A zero is a real, sellable price of nothing.
    ($this->product)('SKU-1', 'Thing');

    $plan = ($this->import)("sku,retail_price,dealer_price\nSKU-1,190,\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toHaveCount(1)
        ->and($plan->creates[0]['group'])->toBe('RETAIL');
});

it('refuses a zero or negative price', function () {
    ($this->product)('SKU-1', 'Thing');

    $plan = ($this->import)("sku,retail_price\nSKU-1,0\n");

    expect($plan->hasErrors())->toBeTrue()
        ->and($plan->errors[0]['message'])->toContain('greater than zero');
});

it('refuses to create a product from a price file', function () {
    $plan = ($this->import)("sku,retail_price\nGHOST-1,190\n");

    expect($plan->hasErrors())->toBeTrue()
        ->and($plan->errors[0]['message'])->toContain('never creates products')
        ->and(Product::count())->toBe(0);
});

it('skips an unknown SKU the file itself marks inactive, instead of refusing the whole file', function () {
    // The approved catalogue decision "inactive and never created" reaches the
    // price columns too: LLD-FRT-HIM-25KG exists in the combined file as an
    // inactive row and deliberately has no product. Pricing nothing is not an
    // error, and one such row must not block 216 real price rules.
    ($this->product)('SKU-1', 'Thing');

    $plan = ($this->import)("sku,is_active,retail_price\nSKU-1,yes,190\nGHOST-25KG,no,140\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toHaveCount(1)
        ->and($plan->warnings)->toHaveCount(1)
        ->and($plan->warnings[0]['message'])->toContain('never existed')
        ->and(Product::count())->toBe(1);
});

it('still refuses an unknown SKU on an active row, whatever is_active says elsewhere', function () {
    // The typo protection the unknown-SKU error exists for: an ACTIVE row that
    // matches nothing is a mistake to fix, never a silent skip.
    $plan = ($this->import)("sku,is_active,retail_price\nGHOST-1,yes,190\n");

    expect($plan->hasErrors())->toBeTrue()
        ->and($plan->errors[0]['message'])->toContain('never creates products');
});

it('never applies a plan that carries errors', function () {
    ($this->product)('SKU-1', 'Thing');

    $plan = ($this->import)("sku,retail_price\nSKU-1,190\nGHOST,50\n");

    expect(fn () => ($this->apply)($plan))->toThrow(RuntimeException::class);
    expect(ProductPrice::count())->toBe(0);
});

it('skips an inactive product with a warning', function () {
    // C-PRI-4. Joy excluded inactive products from the go-live catalogue.
    ($this->product)('OLD-1', 'Seasonal mango', false);

    $plan = ($this->import)("sku,retail_price\nOLD-1,190\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toBeEmpty()
        ->and($plan->warnings[0]['message'])->toContain('inactive');
});

it('supersedes a price in force by closing it the day before, never editing it', function () {
    // C-PRI-2. An invoice raised in September must still be explicable later.
    $product = ($this->product)('SKU-1', 'Thing');

    ProductPrice::create([
        'product_id' => $product->id,
        'price_group_id' => PriceGroup::where('code', 'RETAIL')->value('id'),
        'min_quantity' => 0,
        'unit_price' => '190',
        'effective_from' => '2026-01-01',
    ]);

    $plan = ($this->import)("sku,retail_price\nSKU-1,220\n", '2026-09-01');

    expect($plan->supersedes)->toHaveCount(1)
        ->and($plan->supersedes[0]['closesOn'])->toBe('2026-08-31');

    ($this->apply)($plan, '2026-09-01');

    $old = ProductPrice::where('unit_price', '190.0000')->sole();

    expect($old->effective_to->toDateString())->toBe('2026-08-31')
        ->and(ProductPrice::count())->toBe(2);

    // The economics, not the rows: August resolves to the old price and
    // September to the new one.
    $resolver = app(ResolveSellingPrice::class);
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'B', 'price_group_id' => null]);

    expect((float) $resolver->resolve($product, $customer, '1', Carbon::parse('2026-08-15'))->unitPrice)->toBe(190.0)
        ->and((float) $resolver->resolve($product, $customer, '1', Carbon::parse('2026-09-01'))->unitPrice)->toBe(220.0);
});

it('leaves an identical price completely alone', function () {
    // ⚠ Otherwise a re-run rewrites every rule, and the price history fills
    // with rules that changed nothing.
    $product = ($this->product)('SKU-1', 'Thing');

    ProductPrice::create([
        'product_id' => $product->id,
        'price_group_id' => PriceGroup::where('code', 'RETAIL')->value('id'),
        'min_quantity' => 0,
        'unit_price' => '190',
        'effective_from' => '2026-01-01',
    ]);

    $plan = ($this->import)("sku,retail_price\nSKU-1,190\n");

    expect($plan->creates)->toBeEmpty()
        ->and($plan->supersedes)->toBeEmpty()
        ->and($plan->unchanged)->toHaveCount(1)
        ->and($plan->wouldChangeAnything())->toBeFalse();
});

it('supports a quantity break as a repeated SKU row', function () {
    $product = ($this->product)('SKU-1', 'Thing');

    $plan = ($this->import)(
        "sku,min_quantity,retail_price\n".
        "SKU-1,0,100\n".
        "SKU-1,10,90\n"
    );

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toHaveCount(2);

    ($this->apply)($plan);

    $resolver = app(ResolveSellingPrice::class);
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'B', 'price_group_id' => null]);

    expect((float) $resolver->resolve($product, $customer, '1', Carbon::parse('2026-09-01'))->unitPrice)->toBe(100.0)
        ->and((float) $resolver->resolve($product, $customer, '10', Carbon::parse('2026-09-01'))->unitPrice)->toBe(90.0);
});

it('catches the same tier priced twice at the same quantity', function () {
    ($this->product)('SKU-1', 'Thing');

    $plan = ($this->import)("sku,retail_price\nSKU-1,100\nSKU-1,120\n");

    expect($plan->hasErrors())->toBeTrue()
        ->and($plan->errors[0]['message'])->toContain('twice');
});

it('reports which active products still cannot be sold', function () {
    ($this->product)('SKU-1', 'Priced');
    ($this->product)('SKU-2', 'Forgotten');
    ($this->product)('SKU-3', 'Inactive', false);

    $plan = ($this->import)("sku,retail_price\nSKU-1,100\n");

    // ⚠ Only the ACTIVE unpriced one. An inactive product is not expected to
    // sell, so listing it would make the real number easy to ignore.
    expect($plan->stillUnpriced)->toBe(['SKU-2']);
});

it('reads the real catalogue file shape, ignoring its non-price columns', function () {
    ($this->product)('BNC-SLT-PNK-140G', 'Pink Salt');

    $plan = ($this->import)(
        "sku,business,brand,name,product_name,variant_title,category,mrp,online_discount,retail_price,dealer_price,distributor_price,corporate_price,currency,is_active\n".
        "BNC-SLT-PNK-140G,Bionic,BNC,Pink Salt - 140 g,Pink Salt,140gm,Salts,190,,190,170,160,150,BDT,active\n"
    );

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toHaveCount(4)
        // ⚠ Ignored, but REPORTED. A misspelt column must be visible.
        ->and($plan->ignoredColumns)->toContain('mrp')
        ->and($plan->ignoredColumns)->toContain('brand');
});

it('refuses a misspelt price column outright', function () {
    // ⚠⚠ The lesson from the catalogue importer: a column headed `retail`
    // instead of `retail_price` imports perfectly and drops a whole tier.
    ($this->product)('SKU-1', 'Thing');

    expect(fn () => ($this->import)("sku,retail_price,wholesale_price\nSKU-1,100,90\n"))
        ->toThrow(RuntimeException::class, 'Unrecognised price column');
});

it('refuses a file with no price column at all', function () {
    expect(fn () => ($this->import)("sku,name\nSKU-1,Thing\n"))
        ->toThrow(RuntimeException::class, 'no price column');
});

it('matches SKU regardless of Bangla normalisation in the name', function () {
    // ⚠⚠ The ERP stores decomposed য + nukta; spreadsheets write precomposed য়.
    // Matching is on SKU precisely so this cannot matter - this test is what
    // proves it does not.
    $decomposed = "Royal Essence Ghee (\u{09B0}\u{09AF}\u{09BC}\u{09CD}\u{09AF}\u{09BE}\u{09B2}) 350gm";
    $precomposed = "Royal Essence Ghee (\u{09B0}\u{09DF}\u{09CD}\u{09AF}\u{09BE}\u{09B2})";

    expect($decomposed)->not->toBe($precomposed);

    ($this->product)('LLD-GHE-ROY-350G', $decomposed);

    $plan = ($this->import)("sku,product_name,retail_price\nLLD-GHE-ROY-350G,{$precomposed},870\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toHaveCount(1);
});

it('accepts cost_price, which carries a price and is not a selling price', function () {
    // ⚠⚠ Found by running the importer against Joy's REAL file, not against a
    // fixture that agreed with my rule. `cost_price` is a purchase cost and a
    // real column in the catalogue; rejecting it made the importer refuse the
    // only file it exists to read. The typo guard still stands - see the test
    // above, where `wholesale_price` is still fatal.
    ($this->product)('SKU-1', 'Thing');

    $plan = ($this->import)("sku,retail_price,cost_price,mrp\nSKU-1,190,,220\n");

    expect($plan->errors)->toBeEmpty()
        ->and($plan->creates)->toHaveCount(1)
        ->and($plan->ignoredColumns)->toContain('cost_price')
        ->and($plan->ignoredColumns)->toContain('mrp');
});
