<?php

use App\Console\Commands\HqApiToken;
use App\Models\User;
use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 | The HQ order-reads (HQ docs/81 C.5, Phase 4 read side). Reads only: nothing here creates, changes
 | or cancels an order, and no ability is added to the token allow-list.
 |
 | What matters most is again the negative space: another business's product, price or customer is
 | never visible; an ability the token lacks is a 403, never a thinner answer; and a cost, a credit
 | limit, an address or a note never leaves the ERP through these endpoints under any ability.
 |
 | The last test writes (HQ_API_WRITE_FIXTURES=1) or checks the contract fixtures HQ carries a copy
 | of, so the two repositories cannot drift without one of them failing.
 */

const ORDER_READS_FIXTURES = 'tests/fixtures/hq-api/order-reads';

/*
 | The contract identity. HQ's `tests/Feature/Erp/OrderReadsContractTest.php` pins the SAME value:
 | a change to any response body changes it here first, and HQ's copy then no longer matches.
 */
const ORDER_READS_CONTRACT_DIGEST = 'e646da534fe9549ec5bf72a845e537bc1437072b693c2c367c6e33a022289b22';

/** Every key that may never appear in an order-reads body, at any depth, under any ability. */
const ORDER_READS_NEVER = [
    'unit_cost', 'avg_unit_cost', 'averageUnitCost', 'average_unit_cost', 'cost', 'cost_price', 'supplier', 'supplier_id',
    'margin', 'credit_limit', 'payment_terms', 'bin', 'bin_id', 'tin', 'email', 'address', 'note', 'journal', 'journal_id',
    'payroll', 'phone', 'contact_name', 'batch_number',
];

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-21 10:00:00'));

    $this->a = Business::create(['name' => 'Alpha Foods', 'slug' => 'alpha', 'fiscal_year_start_month' => 7]);
    $this->b = Business::create(['name' => 'Beta Foods', 'slug' => 'beta', 'fiscal_year_start_month' => 7]);
    $this->hq = User::factory()->create(['email' => 'hq@bordeguna.test']);
    $this->hq->businesses()->attach([$this->a->id, $this->b->id]);

    $this->world = [];
    foreach ([$this->a, $this->b] as $biz) {
        app(BusinessContext::class)->set($biz->id);
        $w = [];
        $w['main'] = Warehouse::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
        $w['shop'] = Warehouse::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Shop floor', 'code' => 'SHOP']);
        $unit = UnitOfMeasure::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);
        $brand = Brand::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia-'.$biz->id]);
        $category = Category::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Honey', 'slug' => 'honey-'.$biz->id, 'code' => 'HON']);

        $w['honey'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id, 'brand_id' => $brand->id, 'category_id' => $category->id,
            'sku' => 'HON-500', 'barcode' => $biz->is($this->a) ? '8940000000011' : null, 'name' => $biz->name.' honey 500g', 'type' => ProductType::FinishedGood, 'is_active' => true, 'lead_time_days' => 2]);
        $w['oil'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id,
            'sku' => 'OIL-1L', 'name' => $biz->name.' mustard oil 1L', 'type' => ProductType::FinishedGood, 'is_active' => false]);
        $w['service'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id,
            'sku' => 'SVC-GIFT', 'name' => 'Gift wrapping', 'type' => ProductType::Service, 'is_active' => true]);
        $w['ghee'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id, 'brand_id' => $brand->id,
            'sku' => 'GHE-250', 'name' => $biz->name.' ghee 250g', 'type' => ProductType::FinishedGood, 'is_active' => true,
            'availability_override' => AvailabilityPolicy::Strict]);

        foreach ([[$w['main'], '3'], [$w['shop'], '2']] as $i => [$warehouse, $qty]) {
            StockBatch::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'warehouse_id' => $warehouse->id,
                'batch_number' => "B-{$biz->id}-{$i}", 'unit_cost' => '437.25', 'mrp' => '993.75', 'conversion_factor' => '1', 'initial_quantity' => $qty,
                'current_quantity' => $qty, 'status' => 'active', 'received_at' => now()->subDays(3)]);
        }

        $w['retail'] = PriceGroup::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'RETAIL', 'name' => 'Retail']);
        $w['dealer'] = PriceGroup::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'DEALER', 'name' => 'Dealer']);

        $w['rahim'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'C-1', 'name' => 'Rahim Uddin', 'type' => 'retail',
            'phone' => '01711000001', 'email' => 'rahim@example.test', 'address' => 'Mirpur', 'credit_limit' => '5123', 'note' => 'likes honey', 'is_active' => true]);
        $w['karim'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'C-2', 'name' => 'Karim (closed)', 'type' => 'retail',
            'phone' => '+880 1711-000002', 'is_active' => false]);
        $w['walkin'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'WALK-IN', 'name' => 'Walk-in customer', 'type' => 'retail',
            'phone' => '01711000003', 'is_active' => true, 'is_system' => true]);
        $w['mother'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'C-4', 'name' => 'Salma Begum', 'type' => 'retail',
            'phone' => '01711000004', 'is_active' => true]);
        $w['son'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'C-5', 'name' => 'Tanvir Ahmed', 'type' => 'retail',
            'phone' => '1711000004', 'is_active' => true]);

        // The honey's price list: a standard price, a Retail group price, a Retail break at ten, and one
        // agreed with Rahim. Dealer has none. The oil has no price at all.
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'unit_price' => '900', 'effective_from' => '2026-01-01']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'price_group_id' => $w['retail']->id, 'unit_price' => '850', 'effective_from' => '2026-01-01', 'note' => 'list 2026']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'price_group_id' => $w['retail']->id, 'min_quantity' => '10', 'unit_price' => '800', 'effective_from' => '2026-01-01',
            'is_discount' => true, 'discount_label' => 'Ten or more']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'customer_id' => $w['rahim']->id, 'unit_price' => '780', 'effective_from' => '2026-06-01']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['ghee']->id, 'price_group_id' => $w['retail']->id, 'unit_price' => '650', 'effective_from' => '2026-01-01']);

        $w['shop_store'] = Store::withoutGlobalScopes()->create(['business_id' => $biz->id, 'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD'.$biz->id,
            'theme_key' => 'laladia-classic', 'price_group_id' => $w['retail']->id]);
        $w['shop_store']->products()->create(['product_id' => $w['honey']->id, 'slug' => 'honey'])->publish();
        $w['shop_store']->products()->create(['product_id' => $w['ghee']->id, 'slug' => 'ghee'])->publish();
        $w['strict_store'] = Store::withoutGlobalScopes()->create(['business_id' => $biz->id, 'key' => 'counter-web', 'name' => 'Counter web', 'order_prefix' => 'CTW'.$biz->id,
            'theme_key' => 'laladia-classic', 'price_group_id' => $w['retail']->id, 'availability_policy' => AvailabilityPolicy::Strict]);
        $w['strict_store']->products()->create(['product_id' => $w['ghee']->id, 'slug' => 'ghee'])->publish();

        $this->world[$biz->id] = $w;
    }
    app(BusinessContext::class)->forget();

    $this->all = ['inventory.view', 'inventory.reports.view', 'sales.view', 'sales.customers.view'];
    $this->tokenFor = fn (Business $biz, ?array $abilities = null) => Sanctum::actingAs($this->hq, array_merge($abilities ?? $this->all, ["hq:business:{$biz->id}"]));
    $this->url = fn (string $path, ?Business $biz = null) => '/api/hq/v1/'.($biz ?? $this->a)->id.'/order-reads/'.$path;
});

/** Every key at every depth of a decoded body. */
function orderReadKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $inner) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        array_push($keys, ...orderReadKeys($inner));
    }

    return $keys;
}

/** The canonical form both repositories hash: decoded, then encoded one fixed way. */
function orderReadCanonical(array $decoded): string
{
    return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

// ── authentication, business scope, abilities ─────────────────────────────────────────────────

it('refuses anonymous callers, a token for another business, and a token without the ability, on every order-read', function () {
    $endpoints = [
        ['get', 'products', 'inventory.view'],
        ['get', 'skus/HON-500', 'inventory.view'],
        ['get', 'prices/HON-500', 'sales.view'],
        ['get', 'availability/HON-500', 'inventory.view'],
        ['post', 'customer-eligibility', 'sales.customers.view'],
    ];

    foreach ($endpoints as [$method, $path]) {
        $this->json($method, ($this->url)($path), ['phone' => '01711000001'])->assertStatus(401);
    }

    ($this->tokenFor)($this->a);
    foreach ($endpoints as [$method, $path]) {
        $this->json($method, ($this->url)($path, $this->b), ['phone' => '01711000001'])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    foreach ($endpoints as [$method, $path, $ability]) {
        ($this->tokenFor)($this->a, array_values(array_diff($this->all, [$ability])));
        $this->json($method, ($this->url)($path), ['phone' => '01711000001'])->assertStatus(403)
            ->assertJsonPath('error.message', "Token lacks ability {$ability}.");
    }
});

it('never lets a cost, a credit limit, an address, a note or a phone leave through any order-read, even with every ability', function () {
    ($this->tokenFor)($this->a);
    $bodies = [
        $this->getJson(($this->url)('products?filter[status]=all'))->assertOk()->json(),
        $this->getJson(($this->url)('skus/HON-500'))->assertOk()->json(),
        $this->getJson(($this->url)('prices/HON-500?store=laladia'))->assertOk()->json(),
        $this->getJson(($this->url)('prices/HON-500?customer_id='.$this->world[$this->a->id]['rahim']->id))->assertOk()->json(),
        $this->getJson(($this->url)('availability/HON-500?store=laladia'))->assertOk()->json(),
        $this->postJson(($this->url)('customer-eligibility'), ['phone' => '01711000001'])->assertOk()->json(),
    ];

    foreach ($bodies as $body) {
        expect(array_values(array_intersect(orderReadKeys($body), ORDER_READS_NEVER)))->toBe([]);
        $text = json_encode($body);
        foreach (['437.25', '993.75', '5123', 'Mirpur', 'likes honey', 'rahim@example.test', 'list 2026', 'B-'] as $secret) {
            expect(str_contains((string) $text, $secret))->toBeFalse("[{$secret}] leaked into an order-read");
        }
    }
});

it('still refuses every sensitive ability at the token command, so no order-read widens what a token can hold', function () {
    foreach (['sales.orders.record', 'sales.orders.cancel', 'sales.prices.manage', 'hrm.payroll.manage', 'accounting.journals.post', 'inventory.stock.adjust'] as $ability) {
        expect(in_array($ability, HqApiToken::READ_ABILITIES, true))->toBeFalse();
        $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => "inventory.view,{$ability}"])->assertFailed();
    }
    expect($this->hq->tokens()->count())->toBe(0);
});

it('writes nothing: every order-read leaves every table exactly as it found it', function () {
    ($this->tokenFor)($this->a);
    $tables = ['products', 'product_prices', 'customers', 'stock_batches', 'sales_orders', 'store_products', 'crm_tickets'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

    $this->getJson(($this->url)('products'))->assertOk();
    $this->getJson(($this->url)('skus/HON-500'))->assertOk();
    $this->getJson(($this->url)('prices/HON-500?quantity=12&store=laladia'))->assertOk();
    $this->getJson(($this->url)('availability/HON-500?store=laladia'))->assertOk();
    // A phone nobody has: the website path would CREATE a customer here. The read must not.
    $this->postJson(($this->url)('customer-eligibility'), ['phone' => '01999999999'])->assertOk()->assertJsonPath('data.would_create_new_customer', true);

    expect(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all())->toBe($before);
});

// ── products and SKUs ─────────────────────────────────────────────────────────────────────────

it('lists the active products of the token business only, with exactly the identity fields and nothing else', function () {
    ($this->tokenFor)($this->a);
    $response = $this->getJson(($this->url)('products'))->assertOk()->assertHeader('X-Business-Id', (string) $this->a->id);

    expect(collect($response->json('data'))->pluck('sku')->all())->toBe(['GHE-250', 'HON-500', 'SVC-GIFT'])
        ->and(collect($response->json('data'))->pluck('name')->filter(fn ($n) => str_contains($n, 'Beta'))->all())->toBe([])
        ->and(array_keys($response->json('data.1')))->toBe(['id', 'sku', 'barcode', 'name', 'type', 'is_active', 'orderable', 'unit', 'brand', 'category', 'availability_override', 'lead_time_days'])
        ->and($response->json('data.1'))->toMatchArray([
            'sku' => 'HON-500', 'barcode' => '8940000000011', 'type' => 'finished_good', 'is_active' => true, 'orderable' => true,
            'unit' => ['name' => 'Piece', 'abbreviation' => 'pcs'], 'brand' => ['code' => 'LLD', 'name' => 'LaLaDia'], 'category' => ['name' => 'Honey'],
            'availability_override' => null, 'lead_time_days' => 2,
        ])
        // A service is listed, and it says it cannot be ordered: that is the one type rule a sales order has.
        ->and($response->json('data.2.orderable'))->toBeFalse()
        ->and($response->json('data.0.availability_override'))->toBe('strict')
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.contract'))->toBe('hq-order-reads.v1');
});

it('filters products by text, SKU, id, barcode, brand and status, and refuses a filter it does not know', function () {
    ($this->tokenFor)($this->a);
    $skus = fn (string $query) => collect($this->getJson(($this->url)('products?'.$query))->assertOk()->json('data'))->pluck('sku')->all();
    $honey = $this->world[$this->a->id]['honey'];

    expect($skus('filter[q]=honey'))->toBe(['HON-500'])
        ->and($skus('filter[q]=hon-'))->toBe(['HON-500'])
        ->and($skus('filter[sku]=GHE-250'))->toBe(['GHE-250'])
        ->and($skus('filter[id]='.$honey->id))->toBe(['HON-500'])
        ->and($skus('filter[barcode]=8940000000011'))->toBe(['HON-500'])
        ->and($skus('filter[brand]=LLD'))->toBe(['GHE-250', 'HON-500'])
        ->and($skus('filter[status]=inactive'))->toBe(['OIL-1L'])
        ->and($skus('filter[status]=all'))->toBe(['GHE-250', 'HON-500', 'OIL-1L', 'SVC-GIFT']);

    $this->getJson(($this->url)('products?filter[status]=everything'))->assertStatus(422);
    $this->getJson(($this->url)('products?per_page=500'))->assertStatus(422);
});

it('names the one product a SKU identifies in the token business, says the ERP has no variants, and 404s a SKU it does not hold', function () {
    ($this->tokenFor)($this->a);
    $honey = $this->world[$this->a->id]['honey'];

    $this->getJson(($this->url)('skus/HON-500'))->assertOk()
        ->assertJsonPath('data.id', $honey->id)
        ->assertJsonPath('data.name', 'Alpha Foods honey 500g')
        ->assertJsonPath('data.variant_model', 'none');

    // The same SKU exists in business B; the token for A only ever sees A's.
    expect(Product::withoutGlobalScopes()->where('sku', 'HON-500')->count())->toBe(2);

    $this->getJson(($this->url)('skus/NOPE-1'))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

// ── price ─────────────────────────────────────────────────────────────────────────────────────

it('answers a price with the rule that produced it, from the one pricing engine, for nobody, a store and a quantity break', function () {
    ($this->tokenFor)($this->a);

    $nobody = $this->getJson(($this->url)('prices/HON-500'))->assertOk();
    expect($nobody->json('data'))->toMatchArray(['unit_price' => '900.0000', 'currency' => 'BDT', 'basis' => 'selling_price', 'quantity' => '1', 'priced_as' => 'nobody', 'mrp' => null])
        ->and($nobody->json('data.price_source'))->toMatchArray(['tier' => 'default', 'price_group' => null, 'customer_specific' => false, 'explanation' => 'Standard selling price'])
        ->and($nobody->json('data.promotions_applied_to_sales_orders'))->toBe([]);

    $store = $this->getJson(($this->url)('prices/HON-500?store=laladia'))->assertOk();
    expect($store->json('data'))->toMatchArray(['unit_price' => '850.0000', 'priced_as' => 'store'])
        ->and($store->json('data.store'))->toBe(['key' => 'laladia', 'price_group' => 'RETAIL'])
        ->and($store->json('data.price_source'))->toMatchArray(['tier' => 'price_group', 'price_group' => ['code' => 'RETAIL', 'name' => 'Retail', 'prices_include_tax' => true], 'min_quantity' => '0.0000']);

    $bulk = $this->getJson(($this->url)('prices/HON-500?store=laladia&quantity=12'))->assertOk();
    expect($bulk->json('data'))->toMatchArray(['unit_price' => '800.0000', 'quantity' => '12'])
        ->and($bulk->json('data.price_source'))->toMatchArray(['min_quantity' => '10.0000', 'is_discount' => true, 'discount_label' => 'Ten or more',
            'explanation' => 'Price for group [RETAIL] from 10.0000 units', 'effective_from' => '2026-01-01', 'effective_to' => null]);
});

it('prices a named customer as themselves only with the customer ability, and refuses a customer of another business', function () {
    $rahim = $this->world[$this->a->id]['rahim'];

    ($this->tokenFor)($this->a);
    $this->getJson(($this->url)("prices/HON-500?customer_id={$rahim->id}"))->assertOk()
        ->assertJsonPath('data.unit_price', '780.0000')
        ->assertJsonPath('data.priced_as', 'customer')
        ->assertJsonPath('data.price_source.tier', 'customer')
        ->assertJsonPath('data.price_source.customer_specific', true);

    $betaRahim = $this->world[$this->b->id]['rahim'];
    $this->getJson(($this->url)("prices/HON-500?customer_id={$betaRahim->id}"))->assertStatus(404)->assertJsonPath('error.code', 'unknown_customer');

    ($this->tokenFor)($this->a, ['sales.view']);
    $this->getJson(($this->url)("prices/HON-500?customer_id={$rahim->id}"))->assertStatus(403)->assertJsonPath('error.message', 'Token lacks ability sales.customers.view.');
    $this->getJson(($this->url)('prices/HON-500?store=laladia'))->assertOk();
});

it('says no price is in force rather than guessing one, and refuses an unknown store, a bad date and two bases at once', function () {
    ($this->tokenFor)($this->a);

    $this->getJson(($this->url)('prices/OIL-1L'))->assertStatus(404)->assertJsonPath('error.code', 'no_price_in_force');
    // Before any rule starts, the honey has no price either.
    $this->getJson(($this->url)('prices/HON-500?on=2025-12-31'))->assertStatus(404)->assertJsonPath('error.code', 'no_price_in_force');
    // The ghee has only a Retail group price, so with nobody known it has none.
    $this->getJson(($this->url)('prices/GHE-250'))->assertStatus(404)->assertJsonPath('error.code', 'no_price_in_force');
    $this->getJson(($this->url)('prices/NOPE-1'))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    $this->getJson(($this->url)('prices/HON-500?store=nowhere'))->assertStatus(404)->assertJsonPath('error.code', 'unknown_store');
    $this->getJson(($this->url)('prices/HON-500?on=21-09-2026'))->assertStatus(422);
    $this->getJson(($this->url)('prices/HON-500?quantity=0'))->assertStatus(422);
    $this->getJson(($this->url)('prices/HON-500?store=laladia&customer_id='.$this->world[$this->a->id]['rahim']->id))->assertStatus(422);
});

// ── availability ──────────────────────────────────────────────────────────────────────────────

it('keeps stock and availability as two facts, withholds warehouses without the reports ability, and needs a store for a verdict', function () {
    ($this->tokenFor)($this->a);
    $plain = $this->getJson(($this->url)('availability/HON-500'))->assertOk();
    expect($plain->json('data.stock'))->toBe(['on_hand' => '5', 'unit' => 'pcs', 'warehouses' => [
        ['code' => 'MAIN', 'name' => 'Main', 'on_hand' => '3'],
        ['code' => 'SHOP', 'name' => 'Shop floor', 'on_hand' => '2'],
    ], 'warehouses_withheld' => false])
        ->and($plain->json('data.availability'))->toMatchArray(['decided_per_store' => true, 'product_is_active' => true, 'product_type_is_stocked' => true,
            'availability_override' => null, 'verdict_needs_store' => true, 'store' => null]);

    ($this->tokenFor)($this->a, ['inventory.view']);
    expect($this->getJson(($this->url)('availability/HON-500'))->assertOk()->json('data.stock'))
        ->toBe(['on_hand' => '5', 'unit' => 'pcs', 'warehouses' => null, 'warehouses_withheld' => true]);
});

it('gives the per-store verdict the ERP itself gives a basket: published, priced, and the channel policy narrowed by the product', function () {
    ($this->tokenFor)($this->a);

    expect($this->getJson(($this->url)('availability/HON-500?store=laladia'))->assertOk()->json('data.availability.store'))->toBe([
        'key' => 'laladia', 'policy' => 'status_based', 'published' => true, 'has_price' => true, 'sellable' => true, 'made_to_order' => false, 'message' => null,
    ]);
    // The ghee holds no stock and its own override is strict, so even the web shop refuses it at zero.
    expect($this->getJson(($this->url)('availability/GHE-250?store=laladia'))->assertOk()->json('data.availability.store'))
        ->toMatchArray(['policy' => 'strict', 'published' => true, 'has_price' => true, 'sellable' => false, 'message' => 'Out of stock.']);
    // Not published on that shop at all.
    expect($this->getJson(($this->url)('availability/HON-500?store=counter-web'))->assertOk()->json('data.availability.store'))
        ->toMatchArray(['published' => false, 'sellable' => false, 'message' => 'This product is not available on this shop.']);
    // Inactive: out of season, never sellable.
    expect($this->getJson(($this->url)('availability/OIL-1L'))->assertOk()->json('data.availability'))
        ->toMatchArray(['product_is_active' => false]);

    $this->getJson(($this->url)('availability/HON-500?store=nowhere'))->assertStatus(404)->assertJsonPath('error.code', 'unknown_store');
});

// ── customer eligibility ──────────────────────────────────────────────────────────────────────

it('says whether a phone names a customer the way the website would pick one, and states the flags the ERP does not have', function () {
    ($this->tokenFor)($this->a);
    $w = $this->world[$this->a->id];

    $rahim = $this->postJson(($this->url)('customer-eligibility'), ['phone' => '+880 1711 000001'])->assertOk()->json('data');
    expect($rahim)->toMatchArray([
        'phone_matchable' => true, 'phone_normalized' => '01711000001', 'customer_exists' => true,
        'match' => ['id' => $w['rahim']->id, 'display_name' => 'Rahim Uddin', 'is_active' => true],
        'active_matches' => 1, 'inactive_matches' => 0, 'would_create_new_customer' => false,
        'refusal_rules_the_erp_has' => ['inactive_customer'],
        'fields_the_erp_does_not_have' => ['blocked', 'cod_eligible', 'credit_hold'],
        'credit_limit_enforced' => false,
    ]);

    // Only an inactive row holds the number: the website skips it and would create a new customer.
    expect($this->postJson(($this->url)('customer-eligibility'), ['phone' => '01711000002'])->assertOk()->json('data'))
        ->toMatchArray(['customer_exists' => true, 'match' => null, 'active_matches' => 0, 'inactive_matches' => 1, 'would_create_new_customer' => true]);

    // The seeded walk-in row is never a match.
    expect($this->postJson(($this->url)('customer-eligibility'), ['phone' => '01711000003'])->assertOk()->json('data'))
        ->toMatchArray(['customer_exists' => false, 'match' => null, 'would_create_new_customer' => true]);

    // A household sharing a number: the first by id, as the website picks, and the count says so.
    expect($this->postJson(($this->url)('customer-eligibility'), ['phone' => '01711000004'])->assertOk()->json('data'))
        ->toMatchArray(['match' => ['id' => $w['mother']->id, 'display_name' => 'Salma Begum', 'is_active' => true], 'active_matches' => 2]);

    // Nothing to match on.
    expect($this->postJson(($this->url)('customer-eligibility'), ['phone' => 'no number'])->assertOk()->json('data'))
        ->toMatchArray(['phone_matchable' => false, 'phone_normalized' => null, 'customer_exists' => false, 'would_create_new_customer' => true]);

    $this->postJson(($this->url)('customer-eligibility'), [])->assertStatus(422);
    $this->postJson(($this->url)('customer-eligibility'), ['phone' => str_repeat('1', 33)])->assertStatus(422);
});

it('never matches a customer of another business by phone', function () {
    Customer::withoutGlobalScopes()->create(['business_id' => $this->b->id, 'code' => 'C-9', 'name' => 'Only in Beta', 'type' => 'retail', 'phone' => '01711000009', 'is_active' => true]);

    ($this->tokenFor)($this->a);
    expect($this->postJson(($this->url)('customer-eligibility'), ['phone' => '01711000009'])->assertOk()->json('data'))
        ->toMatchArray(['customer_exists' => false, 'match' => null, 'would_create_new_customer' => true]);
});

// ── the contract fixtures HQ carries a copy of ─────────────────────────────────────────────────

it('holds the exact bodies HQ is built against, and their digest, so the two repositories cannot drift', function () {
    ($this->tokenFor)($this->a);
    $rahim = $this->world[$this->a->id]['rahim'];

    $bodies = [
        'products' => $this->getJson(($this->url)('products?per_page=2'))->assertOk()->json(),
        'sku' => $this->getJson(($this->url)('skus/HON-500'))->assertOk()->json(),
        'sku-not-found' => $this->getJson(($this->url)('skus/NOPE-1'))->assertStatus(404)->json(),
        'price-store' => $this->getJson(($this->url)('prices/HON-500?store=laladia&quantity=12'))->assertOk()->json(),
        'price-customer' => $this->getJson(($this->url)("prices/HON-500?customer_id={$rahim->id}"))->assertOk()->json(),
        'price-nobody' => $this->getJson(($this->url)('prices/HON-500'))->assertOk()->json(),
        'price-none' => $this->getJson(($this->url)('prices/OIL-1L'))->assertStatus(404)->json(),
        'availability' => $this->getJson(($this->url)('availability/HON-500'))->assertOk()->json(),
        'availability-store' => $this->getJson(($this->url)('availability/GHE-250?store=laladia'))->assertOk()->json(),
        'eligibility' => $this->postJson(($this->url)('customer-eligibility'), ['phone' => '01711000004'])->assertOk()->json(),
        'eligibility-none' => $this->postJson(($this->url)('customer-eligibility'), ['phone' => '01711000002'])->assertOk()->json(),
        'forbidden' => (function () {
            ($this->tokenFor)($this->a, ['inventory.view']);

            return $this->getJson(($this->url)('prices/HON-500'))->assertStatus(403)->json();
        })(),
    ];

    // Ids as a fresh database numbers them, so the fixtures hold on MySQL too (freshIds).
    $fresh = $this->a->id - freshIdOffset('businesses');
    $bodies = array_map(fn (array $body) => json_decode(strtr(orderReadCanonical($body), ["/v1/{$this->a->id}/" => "/v1/{$fresh}/"]), true), $bodies);
    $bodies = array_map(fn (array $body) => freshIds($body, [
        'data.[].id' => 'products', 'data.id' => 'products', 'data.product_id' => 'products',
        'data.match.id' => 'customers', 'data.price_source.rule_id' => 'product_prices', 'meta.business_id' => 'businesses',
    ]), $bodies);

    ksort($bodies);
    $digests = array_map(fn (array $body) => hash('sha256', orderReadCanonical($body)), $bodies);
    $contract = hash('sha256', orderReadCanonical($digests));
    $dir = base_path(ORDER_READS_FIXTURES);

    if (getenv('HQ_API_WRITE_FIXTURES') === '1') {
        @mkdir($dir, 0775, true);
        foreach ($bodies as $name => $body) {
            file_put_contents("{$dir}/{$name}.json", json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
        file_put_contents("{$dir}/manifest.json", json_encode([
            'contract' => 'hq-order-reads.v1',
            'origin' => 'bordeguna-erp tests/Feature/HqApi/HqOrderReadTest.php',
            'canonical' => 'sha256 of json_encode(json_decode(file), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)',
            'files' => $digests,
            'contract_digest' => $contract,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    foreach ($bodies as $name => $body) {
        $file = "{$dir}/{$name}.json";
        expect(is_file($file))->toBeTrue("fixture [{$name}] is missing: run with HQ_API_WRITE_FIXTURES=1 and copy the folder to HQ");
        expect(json_decode((string) file_get_contents($file), true))->toBe($body, "fixture [{$name}] no longer matches the endpoint");
    }
    $manifest = json_decode((string) file_get_contents("{$dir}/manifest.json"), true);
    expect($manifest['files'])->toBe($digests)
        ->and($manifest['contract_digest'])->toBe($contract)
        ->and($contract)->toBe(ORDER_READS_CONTRACT_DIGEST, 'the contract changed: re-pin ORDER_READS_CONTRACT_DIGEST here AND in HQ, and copy the fixtures to HQ');
});
