<?php

use App\Console\Commands\HqApiToken;
use App\Models\User;
use App\Modules\Commerce\Enums\AvailabilityPolicy;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrderDraft;
use App\Modules\Sales\Models\SalesOrderDraftLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/*
 | HQ's draft sales order (HQ docs/83 C.3, docs/79): a draft and nothing after it.
 |
 | The negative space matters most. A draft takes no order number, opens no approval, notifies
 | nobody, creates no customer, reserves no stock and posts no journal; it cannot be changed or
 | deleted; a figure that no longer matches the ERP's own is refused rather than rewritten; a key is
 | answered once. The last test writes (HQ_API_WRITE_FIXTURES=1) or checks the contract fixtures HQ
 | carries a copy of, so the two repositories cannot drift without one of them failing.
 */

const DRAFTS_FIXTURES = 'tests/fixtures/hq-api/sales-order-drafts';

/*
 | The contract identity. HQ's `tests/Feature/Erp/SalesOrderDraftContractTest.php` pins the SAME value.
 */
const DRAFTS_CONTRACT_DIGEST = '414fdaa6dae0d89a260e3af7db2838211d2b7898cd32c72d4afc714862d2a02f';

/** Every table a draft must leave exactly as it found it. */
const DRAFTS_UNTOUCHED = [
    'sales_orders', 'sales_order_lines', 'sales_order_addresses', 'approval_requests', 'approval_decisions', 'notifications',
    'customers', 'stock_batches', 'stock_movements', 'journals', 'journal_lines', 'store_coupons', 'carts', 'crm_tickets', 'jobs',
    'products', 'product_prices',
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
        $warehouse = Warehouse::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
        $unit = UnitOfMeasure::withoutGlobalScopes()->create(['business_id' => $biz->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

        $w['honey'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id,
            'sku' => 'HON-500', 'name' => $biz->name.' honey 500g', 'type' => ProductType::FinishedGood, 'is_active' => true]);
        $w['ghee'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id,
            'sku' => 'GHE-250', 'name' => $biz->name.' ghee 250g', 'type' => ProductType::FinishedGood, 'is_active' => true]);
        $w['strict'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id,
            'sku' => 'OIL-1L', 'name' => $biz->name.' mustard oil 1L', 'type' => ProductType::FinishedGood, 'is_active' => true,
            'availability_override' => AvailabilityPolicy::Strict]);
        $w['service'] = Product::withoutGlobalScopes()->create(['business_id' => $biz->id, 'unit_of_measure_id' => $unit->id,
            'sku' => 'SVC-GIFT', 'name' => 'Gift wrapping', 'type' => ProductType::Service, 'is_active' => true]);

        StockBatch::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'warehouse_id' => $warehouse->id,
            'batch_number' => "B-{$biz->id}", 'unit_cost' => '437.25', 'conversion_factor' => '1', 'initial_quantity' => '5',
            'current_quantity' => '5', 'status' => 'active', 'received_at' => now()->subDays(3)]);

        $retail = PriceGroup::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'RETAIL', 'name' => 'Retail']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'price_group_id' => $retail->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'price_group_id' => $retail->id, 'min_quantity' => '10', 'unit_price' => '800', 'effective_from' => '2026-01-01',
            'is_discount' => true, 'discount_label' => 'Ten or more']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['ghee']->id, 'price_group_id' => $retail->id, 'unit_price' => '650.5', 'effective_from' => '2026-01-01']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['strict']->id, 'price_group_id' => $retail->id, 'unit_price' => '300', 'effective_from' => '2026-01-01']);

        $w['store'] = Store::withoutGlobalScopes()->create(['business_id' => $biz->id, 'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD'.$biz->id,
            'theme_key' => 'laladia-classic', 'price_group_id' => $retail->id]);
        $w['store']->status = StoreStatus::Live;
        $w['store']->save();
        foreach (['honey' => 'honey', 'ghee' => 'ghee', 'strict' => 'oil'] as $product => $slug) {
            $w['store']->products()->create(['product_id' => $w[$product]->id, 'slug' => $slug])->publish();
        }
        ShippingZone::create(['store_id' => $w['store']->id, 'name' => 'Outside Dhaka', 'charge' => '120'])->makeDefault();

        $w['draft_store'] = Store::withoutGlobalScopes()->create(['business_id' => $biz->id, 'key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BIO'.$biz->id,
            'theme_key' => 'laladia-classic', 'price_group_id' => $retail->id]);

        $w['rahim'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'C-1', 'name' => 'Rahim Uddin', 'type' => 'retail',
            'phone' => '01711000001', 'is_active' => true]);
        $w['walkin'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'WALK-IN', 'name' => 'Walk-in customer', 'type' => 'retail',
            'phone' => '01711000003', 'is_active' => true, 'is_system' => true]);

        $this->world[$biz->id] = $w;
    }
    app(BusinessContext::class)->forget();

    $this->token = fn (Business $biz, array $abilities = [HqApiToken::WRITE_ABILITIES[0]]) => Sanctum::actingAs($this->hq, array_merge($abilities, ["hq:business:{$biz->id}"]));
    $this->url = fn (string $path = '', ?Business $biz = null) => '/api/hq/v1/'.($biz ?? $this->a)->id.'/sales-order-drafts'.($path === '' ? '' : '/'.$path);
    $this->payload = fn (array $over = []) => array_replace_recursive([
        'idempotency_key' => 'hq:'.str_repeat('a1', 32),
        'store_key' => 'laladia',
        'destination' => [
            'name' => 'Rahim Uddin', 'phone' => '+880 1711-000001', 'district' => 'Bagerhat', 'thana' => 'Fakirhat',
            'address_line' => 'House 4, Road 2', 'landmark' => 'Near the mosque',
        ],
        'lines' => [['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.00'], ['sku' => 'GHE-250', 'quantity' => 1, 'unit_price' => '650.50']],
        'payment_method' => 'cod',
        'proposed' => ['goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50'],
        'hq_reference' => ['proposal_fingerprint' => str_repeat('f0', 32), 'approval_uuid' => 'apr-0001', 'tool_call_id' => 41, 'conversation_uuid' => null],
    ], $over);
});

/** @return array<string, int> */
function draftTableCounts(): array
{
    return collect(DRAFTS_UNTOUCHED)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

function draftCanonical(array $decoded): string
{
    return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

// ── the draft, and everything it does not do ───────────────────────────────────────────────────

it('writes a draft priced and checked by the ERP, and nothing else anywhere', function () {
    Notification::fake();
    Mail::fake();
    Queue::fake();
    ($this->token)($this->a);
    $before = draftTableCounts();

    $body = $this->postJson(($this->url)(), ($this->payload)(), ['Idempotency-Key' => 'hq:'.str_repeat('a1', 32)])->assertCreated()->json();

    expect($body['meta'])->toMatchArray(['business_id' => $this->a->id, 'contract' => 'hq-sales-order-drafts.v1'])
        ->and($body['data'])->toMatchArray([
            'status' => 'draft', 'replayed' => false, 'payment_method' => 'cod',
            'goods_total' => '2350.50', 'delivery_charge' => '120.00', 'order_discount' => '0.00', 'agreed_total' => '2470.50',
            'hq_proposal_fingerprint' => str_repeat('f0', 32),
            'store' => ['key' => 'laladia'],
            'customer' => ['resolution' => 'existing', 'id' => $this->world[$this->a->id]['rahim']->id],
        ])
        ->and($body['data']['recipient'])->toBe(['name' => 'Rahim Uddin', 'phone' => '+880 1711-000001', 'phone_normalized' => '01711000001'])
        ->and($body['data']['lines'])->toHaveCount(2)
        ->and($body['data']['lines'][0])->toMatchArray(['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.0000', 'line_total' => '1700.00', 'made_to_order' => false])
        ->and($body['data']['lines'][1])->toMatchArray(['sku' => 'GHE-250', 'quantity' => 1, 'unit_price' => '650.5000', 'line_total' => '650.50', 'made_to_order' => true])
        ->and($body['data']['effects'])->toBe([
            'sales_order_created' => false, 'order_number_taken' => false, 'approval_requested' => false, 'customer_created' => false,
            'stock_reserved' => false, 'journal_posted' => false, 'notification_sent' => false,
        ]);

    // ⛔ Not one row anywhere else: no order, number, address, approval, notification, customer,
    // stock, journal, coupon, basket, ticket or job, and no price or product touched.
    expect(draftTableCounts())->toBe($before);
    Notification::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();

    expect(SalesOrderDraft::withoutGlobalScopes()->count())->toBe(1)
        ->and(SalesOrderDraftLine::withoutGlobalScopes()->count())->toBe(2);
});

it('names a new buyer as new and creates nobody, and never picks the walk-in row', function () {
    ($this->token)($this->a);
    $before = Customer::withoutGlobalScopes()->count();

    $new = $this->postJson(($this->url)(), ($this->payload)(['idempotency_key' => 'hq:new-buyer-000001', 'destination' => ['phone' => '01811999999']]))->assertCreated();
    $walkIn = $this->postJson(($this->url)(), ($this->payload)(['idempotency_key' => 'hq:walk-in-0000001', 'destination' => ['phone' => '01711000003']]))->assertCreated();

    expect($new->json('data.customer'))->toBe(['resolution' => 'new', 'id' => null])
        ->and($walkIn->json('data.customer'))->toBe(['resolution' => 'new', 'id' => null])
        ->and(Customer::withoutGlobalScopes()->count())->toBe($before);
});

it('replays the same key and the same request, and refuses the same key with a different one', function () {
    ($this->token)($this->a);
    $first = $this->postJson(($this->url)(), ($this->payload)())->assertCreated()->json('data');

    // A price change after the draft does not re-price it: the draft records what was agreed then.
    ProductPrice::withoutGlobalScopes()->where('product_id', $this->world[$this->a->id]['honey']->id)->update(['unit_price' => '999']);

    $again = $this->postJson(($this->url)(), ($this->payload)())->assertOk()->json('data');
    expect($again['id'])->toBe($first['id'])
        ->and($again['replayed'])->toBeTrue()
        ->and($again['goods_total'])->toBe('2350.50');

    // The same request with its keys in another order is the same request.
    $reordered = ($this->payload)();
    $reordered['destination'] = array_reverse($reordered['destination'], true);
    $this->postJson(($this->url)(), array_reverse($reordered, true))->assertOk()->assertJsonPath('data.replayed', true);

    $conflict = $this->postJson(($this->url)(), ($this->payload)(['lines' => [['quantity' => 3]]]))->assertStatus(409)->json();
    expect($conflict['error']['code'])->toBe('idempotency_key_reused')
        ->and(SalesOrderDraft::withoutGlobalScopes()->count())->toBe(1);
});

it('reads a draft back by its key, and says when there is none', function () {
    ($this->token)($this->a);
    $created = $this->postJson(($this->url)(), ($this->payload)())->assertCreated()->json('data');

    $read = $this->getJson(($this->url)('hq:'.str_repeat('a1', 32)))->assertOk()->json('data');
    expect($read['id'])->toBe($created['id'])
        ->and($read)->not->toHaveKey('replayed')
        ->and($read['lines'])->toBe($created['lines']);

    $this->getJson(($this->url)('hq:nothing-here-00000'))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

// ── the ERP asks its own questions ─────────────────────────────────────────────────────────────

it('refuses figures that no longer match its own, naming every one, and writes nothing', function () {
    ($this->token)($this->a);

    $body = $this->postJson(($this->url)(), ($this->payload)([
        'lines' => [['unit_price' => '800.00']],
        'proposed' => ['goods_total' => '2250.50', 'delivery_charge' => '60.00', 'agreed_total' => '2310.50'],
    ]))->assertStatus(422)->json();

    expect($body['error']['code'])->toBe('quote_mismatch')
        ->and($body['errors'])->toBe([
            'lines.0.unit_price' => ['Proposed 800.00, the ERP\'s price is 850.00.'],
            'proposed.goods_total' => ['Proposed 2250.50, the ERP\'s figure is 2350.50.'],
            'proposed.delivery_charge' => ['Proposed 60.00, the ERP\'s figure is 120.00.'],
            'proposed.agreed_total' => ['Proposed 2310.50, the ERP\'s figure is 2470.50.'],
        ])
        ->and(SalesOrderDraft::withoutGlobalScopes()->count())->toBe(0);
});

it('prices at the quantity asked, so a quantity break is the engine\'s answer and nobody else\'s', function () {
    ($this->token)($this->a);
    $payload = ($this->payload)(['lines' => [['quantity' => 12, 'unit_price' => '800'], ['quantity' => 1, 'unit_price' => '650.5']],
        'proposed' => ['goods_total' => '10250.50', 'delivery_charge' => '120', 'agreed_total' => '10370.50']]);

    $this->postJson(($this->url)(), $payload)->assertCreated()->assertJsonPath('data.lines.0.unit_price', '800.0000');
});

it('refuses what the website would refuse, each with its own sentence', function () {
    ($this->token)($this->a);

    $body = $this->postJson(($this->url)(), ($this->payload)(['lines' => [
        ['sku' => 'NOPE-1', 'quantity' => 1, 'unit_price' => '1'],
        ['sku' => 'SVC-GIFT', 'quantity' => 1, 'unit_price' => '1'],
        ['sku' => 'OIL-1L', 'quantity' => 1, 'unit_price' => '300'],
        ['sku' => 'NOPE-1', 'quantity' => 1, 'unit_price' => '1'],
    ]]))->assertStatus(422)->json();

    expect($body['error']['code'])->toBe('refused')
        ->and($body['errors']['lines.0.sku'][0])->toBe('No product with SKU NOPE-1 in this business.')
        ->and($body['errors']['lines.1.sku'][0])->toContain('is a service and cannot be ordered')
        ->and($body['errors']['lines.2.sku'][0])->toContain('Out of stock.')
        ->and($body['errors']['lines.3.sku'][0])->toContain('appears twice');

    $this->postJson(($this->url)(), ($this->payload)(['store_key' => 'bionic']))->assertStatus(422)
        ->assertJsonPath('errors.store_key.0', 'The store bionic is not live, so it takes no orders.');
    $this->postJson(($this->url)(), ($this->payload)(['store_key' => 'nowhere']))->assertStatus(422)->assertJsonPath('error.code', 'refused');
    $this->postJson(($this->url)(), ($this->payload)(['destination' => ['thana' => 'Nowhere']]))->assertStatus(422)
        ->assertJsonPath('errors', ['destination.thana' => ['We do not recognise that thana in Bagerhat.']]);
    expect($this->postJson(($this->url)(), ($this->payload)(['destination' => ['phone' => '12345']]))->assertStatus(422)->json('errors'))
        ->toBe(['destination.phone' => ['That is not a Bangladesh mobile number (eleven digits starting 013 to 019).']]);

    expect(SalesOrderDraft::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a store that cannot quote delivery for the district', function () {
    ($this->token)($this->a);
    ShippingZone::query()->where('store_id', $this->world[$this->a->id]['store']->id)->delete();

    expect($this->postJson(($this->url)(), ($this->payload)())->assertStatus(422)->json('errors'))
        ->toBe(['destination.district' => ['The store cannot work out a delivery charge for Bagerhat.']]);
});

it('takes cash on delivery only, no coupon, no note, and nothing it does not name', function () {
    ($this->token)($this->a);

    $this->postJson(($this->url)(), ($this->payload)(['payment_method' => 'sslcommerz']))->assertStatus(422);
    $this->postJson(($this->url)(), ($this->payload)() + ['coupon_code' => 'EID10'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_field');
    $this->postJson(($this->url)(), ($this->payload)() + ['note' => 'rush'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_field');
    $this->postJson(($this->url)(), ($this->payload)(['lines' => [['quantity' => 100]]]))->assertStatus(422);
    $this->postJson(($this->url)(), ($this->payload)(['lines' => [['quantity' => '1.5']]]))->assertStatus(422);
    $bad = ($this->payload)();
    $bad['lines'][0]['unit_price_override'] = '1';
    $this->postJson(($this->url)(), $bad)->assertStatus(422);
    $this->postJson(($this->url)(), ($this->payload)(['hq_reference' => ['proposal_fingerprint' => 'not-a-digest']]))->assertStatus(422);
    $this->postJson(($this->url)(), ($this->payload)(), ['Idempotency-Key' => 'hq:some-other-key-01'])->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_mismatch');

    expect(SalesOrderDraft::withoutGlobalScopes()->count())->toBe(0);
});

// ── the token, the business, the record ────────────────────────────────────────────────────────

it('needs its own ability, which no read token holds, for both routes', function () {
    ($this->token)($this->a, HqApiToken::READ_ABILITIES);
    $this->postJson(($this->url)(), ($this->payload)())->assertStatus(403)->assertJsonPath('error.message', 'Token lacks ability sales.orders.draft.');
    $this->getJson(($this->url)('hq:'.str_repeat('a1', 32)))->assertStatus(403);

    expect(in_array('sales.orders.draft', HqApiToken::READ_ABILITIES, true))->toBeFalse()
        ->and(SalesOrderDraft::withoutGlobalScopes()->count())->toBe(0);
});

it('issues the write ability only when it is named and --write is given', function () {
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'inventory.view,sales.orders.draft'])->assertFailed();
    expect($this->hq->tokens()->count())->toBe(0);

    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'inventory.view,sales.orders.draft', '--write' => true])->assertSuccessful();
    expect($this->hq->tokens()->first()->abilities)->toBe(['inventory.view', 'sales.orders.draft', "hq:business:{$this->a->id}"]);

    // --write widens nothing else.
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'sales.orders.record', '--write' => true])->assertFailed();
});

it('keeps each business to itself: another business\'s store, product and draft are not there', function () {
    ($this->token)($this->a);
    $this->postJson(($this->url)(), ($this->payload)())->assertCreated();

    ($this->token)($this->b);
    $this->getJson(($this->url)('hq:'.str_repeat('a1', 32), $this->b))->assertStatus(404);
    // The same key in another business is another draft, not a replay and not a conflict.
    $this->postJson(($this->url)('', $this->b), ($this->payload)())->assertCreated()->assertJsonPath('data.replayed', false);

    ($this->token)($this->b);
    $this->postJson(($this->url)('', $this->a), ($this->payload)())->assertStatus(403);
});

it('is immutable: a draft cannot be changed or deleted, and nothing moves it out of draft', function () {
    ($this->token)($this->a);
    $id = $this->postJson(($this->url)(), ($this->payload)())->assertCreated()->json('data.id');
    $draft = SalesOrderDraft::withoutGlobalScopes()->findOrFail($id);
    $line = SalesOrderDraftLine::withoutGlobalScopes()->where('sales_order_draft_id', $id)->firstOrFail();

    expect(fn () => $draft->update(['status' => 'open']))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $draft->update(['agreed_total' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $draft->delete())->toThrow(LogicException::class)
        ->and(fn () => $line->update(['unit_price' => '1']))->toThrow(LogicException::class)
        ->and(fn () => $line->delete())->toThrow(LogicException::class)
        ->and(fn () => SalesOrderDraft::create(['business_id' => $this->a->id, 'status' => 'open']))->toThrow(LogicException::class)
        ->and($draft->fresh()->status)->toBe('draft');
});

it('is written by one action only, and no route confirms, changes or cancels a draft', function () {
    $writers = collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->filter(fn ($f) => preg_match('/SalesOrderDraft(Line)?::(create|query\(\)->(update|delete|insert))|sales_order_drafts[\'"]\)->(insert|update|delete)|->lines\(\)->create/', (string) file_get_contents($f->getPathname())) === 1)
        ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))
        ->values()->all();
    expect(array_values(array_filter($writers, fn ($p) => str_contains((string) file_get_contents(app_path($p)), 'SalesOrderDraft'))))
        ->toBe(['Modules/Sales/Actions/CreateSalesOrderDraft.php']);

    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'sales-order-drafts'));
    expect($routes->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->sort()->values()->all())->toBe([
        'GET|HEAD api/hq/v1/{business}/sales-order-drafts/{key}',
        'POST api/hq/v1/{business}/sales-order-drafts',
    ]);
});

// ── the contract fixtures HQ carries a copy of ─────────────────────────────────────────────────

it('holds the exact bodies HQ is built against, and their digest, so the two repositories cannot drift', function () {
    ($this->token)($this->a);
    $key = 'hq:'.str_repeat('a1', 32);

    $bodies = [
        'created' => $this->postJson(($this->url)(), ($this->payload)())->assertCreated()->json(),
        'replayed' => $this->postJson(($this->url)(), ($this->payload)())->assertOk()->json(),
        'read' => $this->getJson(($this->url)($key))->assertOk()->json(),
        'not-found' => $this->getJson(($this->url)('hq:nothing-here-00000'))->assertStatus(404)->json(),
        'key-reused' => $this->postJson(($this->url)(), ($this->payload)(['lines' => [['quantity' => 3]]]))->assertStatus(409)->json(),
        'quote-mismatch' => $this->postJson(($this->url)(), ($this->payload)(['idempotency_key' => 'hq:mismatch-000001', 'lines' => [['unit_price' => '800.00']]]))->assertStatus(422)->json(),
        'refused' => $this->postJson(($this->url)(), ($this->payload)(['idempotency_key' => 'hq:refused-0000001', 'store_key' => 'bionic']))->assertStatus(422)->json(),
        'forbidden' => (function () use ($key) {
            ($this->token)($this->a, ['inventory.view']);

            return $this->getJson(($this->url)($key))->assertStatus(403)->json();
        })(),
    ];

    ksort($bodies);
    $digests = array_map(fn (array $body) => hash('sha256', draftCanonical($body)), $bodies);
    $contract = hash('sha256', draftCanonical($digests));
    $dir = base_path(DRAFTS_FIXTURES);

    if (getenv('HQ_API_WRITE_FIXTURES') === '1') {
        @mkdir($dir, 0775, true);
        foreach ($bodies as $name => $body) {
            file_put_contents("{$dir}/{$name}.json", json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
        file_put_contents("{$dir}/manifest.json", json_encode([
            'contract' => SalesOrderDraft::CONTRACT,
            'origin' => 'bordeguna-erp tests/Feature/HqApi/HqSalesOrderDraftTest.php',
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
        ->and($contract)->toBe(DRAFTS_CONTRACT_DIGEST, 'the contract changed: re-pin DRAFTS_CONTRACT_DIGEST here AND in HQ, and copy the fixtures to HQ');
});
