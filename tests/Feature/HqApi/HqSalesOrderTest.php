<?php

use App\Console\Commands\HqApiToken;
use App\Models\User;
use App\Modules\Commerce\Enums\StoreStatus;
use App\Modules\Commerce\Models\ShippingZone;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Notifications\PendingApprovalNotification;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderDraft;
use App\Modules\Sales\Models\SalesOrderHqCancellation;
use App\Modules\Sales\Models\SalesOrderHqConfirmation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 | HQ's order routes after the draft (HQ docs/88 A point 3, docs/79 F).
 |
 | What matters most: an approved HQ draft becomes an order ONLY through the ERP's own approval path. The
 | order is written by `CreateSalesOrder`, created by the system actor, and WAITS in the approval inbox
 | with the approvers notified, even when the HQ user itself holds the approval; it is exactly the draft,
 | re-priced now, refused rather than rewritten when anything moved; one draft makes one order, once.
 | HQ reads back and cancels ONLY orders it placed, under the ERP's own cancel rule. The last test writes
 | (HQ_API_WRITE_FIXTURES=1) or checks the contract fixtures HQ carries a copy of.
 */

const ORDERS_FIXTURES = 'tests/fixtures/hq-api/sales-orders';

/*
 | The contract identity. HQ's `tests/Feature/Erp/SalesOrderContractTest.php` pins the SAME value.
 */
const ORDERS_CONTRACT_DIGEST = 'b3aaf4087f03a2bd669cc5693108f853a6772aaa0a81f0c04728cdbafcfa21b6';

/** Tables a quote and a refused request must leave exactly as they found them. */
const ORDERS_UNTOUCHED = [
    'sales_orders', 'sales_order_lines', 'sales_order_addresses', 'approval_requests', 'notifications', 'customers',
    'stock_movements', 'journals', 'journal_lines', 'sales_order_drafts', 'sales_order_hq_confirmations', 'sales_order_hq_cancellations',
];

const DRAFT_KEY = 'hq:draft-0000000001';

const ORDER_KEY = 'hq:order-0000000001';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-21 10:00:00'));

    $this->a = Business::create(['name' => 'Alpha Foods', 'slug' => 'alpha', 'fiscal_year_start_month' => 7]);
    $this->b = Business::create(['name' => 'Beta Foods', 'slug' => 'beta', 'fiscal_year_start_month' => 7]);
    $this->hq = User::factory()->create(['email' => 'hq@bordeguna.test']);
    $this->hq->businesses()->attach([$this->a->id, $this->b->id]);

    // An approver in business A, so the notification to the approval inbox can be seen.
    Permission::firstOrCreate(['name' => 'sales.orders.approve', 'guard_name' => 'web']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->a->id);
    $owner = Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->a->id, 'guard_name' => 'web']);
    $owner->givePermissionTo(['sales.orders.approve']);
    $this->approver = User::factory()->create();
    $this->approver->businesses()->attach($this->a->id);
    $this->approver->assignRole('Owner');

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

        StockBatch::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'warehouse_id' => $warehouse->id,
            'batch_number' => "B-{$biz->id}", 'unit_cost' => '437.25', 'conversion_factor' => '1', 'initial_quantity' => '5',
            'current_quantity' => '5', 'status' => 'active', 'received_at' => now()->subDays(3)]);

        $retail = PriceGroup::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'RETAIL', 'name' => 'Retail']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['honey']->id, 'price_group_id' => $retail->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
        ProductPrice::withoutGlobalScopes()->create(['business_id' => $biz->id, 'product_id' => $w['ghee']->id, 'price_group_id' => $retail->id, 'unit_price' => '650.5', 'effective_from' => '2026-01-01']);

        $w['store'] = Store::withoutGlobalScopes()->create(['business_id' => $biz->id, 'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD'.$biz->id,
            'theme_key' => 'laladia-classic', 'price_group_id' => $retail->id]);
        $w['store']->status = StoreStatus::Live;
        $w['store']->delivery_promise_min_hours = 24;
        $w['store']->delivery_promise_max_hours = 72;
        $w['store']->save();
        foreach (['honey' => 'honey', 'ghee' => 'ghee'] as $product => $slug) {
            $w['store']->products()->create(['product_id' => $w[$product]->id, 'slug' => $slug])->publish();
        }
        ShippingZone::create(['store_id' => $w['store']->id, 'name' => 'Outside Dhaka', 'charge' => '120'])->makeDefault();

        $w['rahim'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'C-1', 'name' => 'Rahim Uddin', 'type' => 'retail',
            'phone' => '01711000001', 'is_active' => true]);
        $w['walkin'] = Customer::withoutGlobalScopes()->create(['business_id' => $biz->id, 'code' => 'WALK-IN', 'name' => 'Walk-in customer', 'type' => 'retail',
            'phone' => '01711000003', 'is_active' => true, 'is_system' => true]);

        $this->world[$biz->id] = $w;
    }
    app(BusinessContext::class)->forget();

    $this->token = fn (Business $biz, array $abilities) => Sanctum::actingAs($this->hq, array_merge($abilities, ["hq:business:{$biz->id}"]));
    $this->writer = fn (Business $biz) => ($this->token)($biz, ['sales.orders.draft', 'sales.orders.record', 'sales.orders.cancel', 'sales.view']);
    $this->url = fn (string $path = '', ?Business $biz = null) => '/api/hq/v1/'.($biz ?? $this->a)->id.'/sales-orders'.($path === '' ? '' : '/'.$path);
    $this->destination = [
        'name' => 'Rahim Uddin', 'phone' => '+880 1711-000001', 'district' => 'Bagerhat', 'thana' => 'Fakirhat',
        'address_line' => 'House 4, Road 2', 'landmark' => 'Near the mosque',
    ];
    $this->draft = function (array $over = [], ?Business $biz = null) {
        return $this->postJson('/api/hq/v1/'.($biz ?? $this->a)->id.'/sales-order-drafts', array_replace_recursive([
            'idempotency_key' => DRAFT_KEY,
            'store_key' => 'laladia',
            'destination' => $this->destination,
            'lines' => [['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.00'], ['sku' => 'GHE-250', 'quantity' => 1, 'unit_price' => '650.50']],
            'payment_method' => 'cod',
            'proposed' => ['goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50'],
            'hq_reference' => ['proposal_fingerprint' => str_repeat('f0', 32), 'approval_uuid' => 'apr-0001', 'tool_call_id' => 41, 'conversation_uuid' => null],
        ], $over))->assertCreated()->json('data');
    };
    $this->order = fn (array $over = []) => array_replace_recursive([
        'idempotency_key' => ORDER_KEY,
        'draft_key' => DRAFT_KEY,
        'proposed' => ['goods_total' => '2350.50', 'delivery_charge' => '120.00', 'agreed_total' => '2470.50'],
        'hq_reference' => ['proposal_fingerprint' => str_repeat('f0', 32), 'approval_uuid' => 'apr-0001', 'tool_call_id' => 42, 'conversation_uuid' => null],
    ], $over);
    $this->quote = fn (array $over = []) => array_replace_recursive([
        'store_key' => 'laladia',
        'destination' => $this->destination,
        'lines' => [['sku' => 'HON-500', 'quantity' => 2], ['sku' => 'GHE-250', 'quantity' => 1]],
    ], $over);
});

/** @return array<string, int> */
function orderTableCounts(): array
{
    return collect(ORDERS_UNTOUCHED)->mapWithKeys(fn (string $t) => [$t => DB::table($t)->count()])->all();
}

function orderCanonical(array $decoded): string
{
    return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
}

// ── the quote ──────────────────────────────────────────────────────────────────────────────────

it('quotes what the ERP would charge now, the draft\'s own figures, and writes nothing', function () {
    ($this->token)($this->a, ['sales.view']);
    $before = orderTableCounts();

    $body = $this->postJson(($this->url)('quote'), ($this->quote)())->assertOk()->json();

    expect($body['meta']['contract'])->toBe('hq-sales-orders.v1')
        ->and($body['data'])->toMatchArray([
            'store' => ['key' => 'laladia'], 'payment_methods' => ['cod'],
            'goods_total' => '2350.50', 'delivery_charge' => '120.00', 'order_discount' => '0.00', 'agreed_total' => '2470.50',
            'delivery_promise' => 'Delivered within 24 to 72 hours after we confirm your order.',
            'effects' => ['written' => false],
        ])
        ->and($body['data']['lines'][0])->toMatchArray(['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.0000', 'line_total' => '1700.00', 'sellable' => true, 'made_to_order' => false])
        ->and($body['data']['lines'][1])->toMatchArray(['sku' => 'GHE-250', 'made_to_order' => true])
        ->and(orderTableCounts())->toBe($before);
});

it('refuses a quote the website would refuse, and takes nothing it does not name', function () {
    ($this->token)($this->a, ['sales.view']);

    $this->postJson(($this->url)('quote'), ($this->quote)(['lines' => [['sku' => 'NOPE-1']]]))->assertStatus(422)
        ->assertJsonPath('error.code', 'refused')->assertJsonFragment(['lines.0.sku' => ['No product with SKU NOPE-1 in this business.']]);
    $this->postJson(($this->url)('quote'), ($this->quote)() + ['coupon_code' => 'EID10'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_field');
});

// ── an approved draft becomes an order, only through the ERP's approval ─────────────────────────

it('turns an approved draft into an order that waits in the ERP approval inbox, made by nobody who can approve', function () {
    Notification::fake();
    ($this->writer)($this->a);
    ($this->draft)();
    $draftBefore = SalesOrderDraft::withoutGlobalScopes()->firstOrFail()->toArray();
    $movements = DB::table('stock_movements')->count();
    $journals = DB::table('journals')->count();

    $body = $this->postJson(($this->url)(), ($this->order)(), ['Idempotency-Key' => ORDER_KEY])->assertCreated()->json();

    app(BusinessContext::class)->set($this->a->id);
    $order = SalesOrder::query()->sole();
    expect($order->status)->toBe(SalesOrderStatus::Open)
        ->and($order->approvalRequest->isPending())->toBeTrue()
        ->and($order->isDeliverable())->toBeFalse()
        ->and($order->created_by)->toBe(SystemActor::id())
        ->and($order->channel)->toBe(SalesChannel::Website)
        ->and($order->customer_id)->toBe($this->world[$this->a->id]['rahim']->id)
        ->and($order->note)->toContain(DRAFT_KEY)
        ->and($order->shop_reference)->toStartWith('LLD'.$this->a->id.'-20260921-');

    expect($body['meta']['contract'])->toBe('hq-sales-orders.v1')
        ->and($body['data'])->toMatchArray([
            'status' => 'open', 'approval_status' => 'pending', 'deliverable' => false, 'channel' => 'website', 'replayed' => false,
            'goods_total' => '2350.50', 'delivery_charge' => '120.00', 'order_discount' => '0.00', 'agreed_total' => '2470.50',
            'payment_method' => 'cod', 'delivery_promise' => 'Delivered within 24 to 72 hours after we confirm your order.',
        ])
        ->and($body['data']['hq'])->toBe(['draft_key' => DRAFT_KEY, 'idempotency_key' => ORDER_KEY, 'proposal_fingerprint' => str_repeat('f0', 32), 'approval_uuid' => 'apr-0001'])
        ->and($body['data']['lines'][0])->toMatchArray(['sku' => 'HON-500', 'quantity' => 2, 'unit_price' => '850.0000'])
        ->and($body['data']['effects'])->toBe([
            'sales_order_created' => true, 'approval_requested' => true, 'approved_by_hq' => false, 'stock_moved' => false,
            'journal_posted' => false, 'payment_taken' => false, 'courier_booked' => false,
        ]);

    // The ERP's approvers are told; the HQ user that asked is not an approver of it.
    Notification::assertSentTo($this->approver, PendingApprovalNotification::class);
    Notification::assertNotSentTo($this->hq, PendingApprovalNotification::class);

    // The draft is exactly as written, the link is its own row, and nothing moved or posted.
    expect(SalesOrderDraft::withoutGlobalScopes()->firstOrFail()->toArray())->toBe($draftBefore)
        ->and(SalesOrderHqConfirmation::withoutGlobalScopes()->sole()->only(['sales_order_id', 'idempotency_key', 'requested_by']))
        ->toBe(['sales_order_id' => $order->id, 'idempotency_key' => ORDER_KEY, 'requested_by' => $this->hq->id])
        ->and(DB::table('stock_movements')->count())->toBe($movements)
        ->and(DB::table('journals')->count())->toBe($journals);
});

it('never approves the order, even when the HQ user itself holds the approval', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->a->id);
    $this->hq->assignRole('Owner');
    expect($this->hq->fresh()->can('sales.orders.approve'))->toBeTrue();

    ($this->writer)($this->a);
    ($this->draft)();
    $this->postJson(($this->url)(), ($this->order)())->assertCreated()->assertJsonPath('data.approval_status', 'pending');

    app(BusinessContext::class)->set($this->a->id);
    expect(SalesOrder::query()->sole()->approvalRequest->isPending())->toBeTrue();
});

it('makes the order for the customer the website would, and creates one only when nobody has the phone', function () {
    ($this->writer)($this->a);
    ($this->draft)(['destination' => ['phone' => '01811999999', 'name' => 'Nasrin Akter']]);
    $before = Customer::withoutGlobalScopes()->count();

    $this->postJson(($this->url)(), ($this->order)())->assertCreated();

    $created = Customer::withoutGlobalScopes()->latest('id')->first();
    expect(Customer::withoutGlobalScopes()->count())->toBe($before + 1)
        ->and($created->only(['name', 'phone', 'price_group_id', 'is_system']))->toBe(['name' => 'Nasrin Akter', 'phone' => '01811999999', 'price_group_id' => null, 'is_system' => false])
        ->and(SalesOrder::withoutGlobalScopes()->sole()->customer_id)->toBe($created->id);

    // The walk-in row's phone makes a new customer, never the walk-in row.
    ($this->draft)(['idempotency_key' => 'hq:draft-0000000002', 'destination' => ['phone' => '01711000003']]);
    $this->postJson(($this->url)(), ($this->order)(['idempotency_key' => 'hq:order-0000000002', 'draft_key' => 'hq:draft-0000000002']))->assertCreated();
    expect(SalesOrder::withoutGlobalScopes()->latest('id')->first()->customer_id)->not->toBe($this->world[$this->a->id]['walkin']->id);
});

it('replays the same key, refuses the same key for another request, and makes one order per draft', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $first = $this->postJson(($this->url)(), ($this->order)())->assertCreated()->json('data');

    $again = $this->postJson(($this->url)(), ($this->order)())->assertOk()->json('data');
    expect($again['id'])->toBe($first['id'])->and($again['replayed'])->toBeTrue();

    $this->postJson(($this->url)(), ($this->order)(['hq_reference' => ['tool_call_id' => 99]]))->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    $this->postJson(($this->url)(), ($this->order)(['idempotency_key' => 'hq:order-0000000009']))->assertStatus(409)
        ->assertJsonPath('error.code', 'draft_already_confirmed')
        ->assertJsonPath('error.message', "This draft is already order {$first['order_number']}; a draft becomes one order, once. Nothing was written.");

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(1)
        ->and(SalesOrderHqConfirmation::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses anything that is not exactly the approved draft, and writes nothing', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $before = orderTableCounts();

    $this->postJson(($this->url)(), ($this->order)(['hq_reference' => ['proposal_fingerprint' => str_repeat('0e', 32)]]))->assertStatus(422)
        ->assertJsonFragment(['hq_reference.proposal_fingerprint' => ['This draft was written for a different approved proposal.']]);
    $this->postJson(($this->url)(), ($this->order)(['proposed' => ['agreed_total' => '2400.00']]))->assertStatus(422)
        ->assertJsonPath('error.code', 'quote_mismatch')
        ->assertJsonFragment(['proposed.agreed_total' => ['Proposed 2400.00, the draft agreed 2470.50.']]);
    $this->postJson(($this->url)(), ($this->order)(['draft_key' => 'hq:no-such-draft-01']))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    $this->postJson(($this->url)(), ($this->order)() + ['unit_price' => '1'])->assertStatus(422)->assertJsonPath('error.code', 'unknown_field');

    expect(orderTableCounts())->toBe($before);
});

it('refuses when the ERP would now price the draft differently, and never rewrites it', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $before = orderTableCounts();

    ProductPrice::withoutGlobalScopes()->where('product_id', $this->world[$this->a->id]['honey']->id)->update(['unit_price' => '900']);
    $body = $this->postJson(($this->url)(), ($this->order)())->assertStatus(422)->json();
    expect($body['error']['code'])->toBe('quote_mismatch')
        ->and($body['errors'])->toBe([
            'lines.0.unit_price' => ['The draft agreed 850.00, the ERP\'s price is now 900.00. Write a new draft.'],
            'draft.goods_total' => ['The draft agreed 2350.50, the ERP\'s figure is now 2450.50. Write a new draft.'],
            'draft.agreed_total' => ['The draft agreed 2470.50, the ERP\'s figure is now 2570.50. Write a new draft.'],
        ]);

    ProductPrice::withoutGlobalScopes()->where('product_id', $this->world[$this->a->id]['honey']->id)->update(['unit_price' => '850']);
    $store = $this->world[$this->a->id]['store'];
    $store->status = StoreStatus::Draft;
    $store->save();
    $this->postJson(($this->url)(), ($this->order)())->assertStatus(422)
        ->assertJsonPath('errors.store_key.0', 'The store laladia is not live, so it takes no orders.');

    expect(orderTableCounts())->toBe($before);
});

// ── the read-back ──────────────────────────────────────────────────────────────────────────────

it('reads back only orders HQ placed: by the customer\'s reference, the order number and HQ\'s key', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $created = $this->postJson(($this->url)(), ($this->order)())->assertCreated()->json('data');

    foreach ([$created['shop_reference'], $created['order_number']] as $reference) {
        $read = $this->getJson(($this->url)($reference))->assertOk()->json('data');
        expect($read['id'])->toBe($created['id'])->and($read)->not->toHaveKey('replayed');
    }
    $this->getJson(($this->url)('by-key/'.ORDER_KEY))->assertOk()->assertJsonPath('data.id', $created['id']);
    $this->getJson(($this->url)('by-key/hq:order-nobody-0001'))->assertStatus(404);

    // An order a colleague typed in is not HQ's to read, whatever its number.
    app(BusinessContext::class)->set($this->a->id);
    $clerk = User::factory()->create();
    $internal = app(CreateSalesOrder::class)->handle(customer: $this->world[$this->a->id]['rahim'], lines: [['product' => $this->world[$this->a->id]['honey'], 'quantity' => '1']], createdBy: $clerk);
    app(BusinessContext::class)->forget();
    ($this->writer)($this->a);
    $this->getJson(($this->url)($internal->order_number))->assertStatus(404)->assertJsonPath('error.code', 'not_found');
});

// ── HQ's cancellation ──────────────────────────────────────────────────────────────────────────

it('cancels an order HQ placed while nothing has left, once per key', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $created = $this->postJson(($this->url)(), ($this->order)())->assertCreated()->json('data');
    $cancel = ['idempotency_key' => 'hq:cancel-000000001', 'reason' => 'The customer changed their mind on the phone.'];

    $body = $this->postJson(($this->url)($created['shop_reference'].'/cancel'), $cancel)->assertOk()->json('data');
    expect($body)->toMatchArray(['status' => 'cancelled', 'replayed' => false, 'next_step' => 'None. The order is cancelled.'])
        ->and($body['cancellation'])->toBe(['requested' => true, 'idempotency_key' => 'hq:cancel-000000001', 'reason' => 'The customer changed their mind on the phone.'])
        ->and(SalesOrder::withoutGlobalScopes()->sole()->status)->toBe(SalesOrderStatus::Cancelled);

    $this->postJson(($this->url)($created['shop_reference'].'/cancel'), $cancel)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson(($this->url)($created['shop_reference'].'/cancel'), ['reason' => 'Another reason altogether.'] + $cancel)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');
    $this->postJson(($this->url)($created['order_number'].'/cancel'), ['idempotency_key' => 'hq:cancel-000000002'] + $cancel)->assertStatus(409)->assertJsonPath('error.code', 'already_cancelled');

    expect(SalesOrderHqCancellation::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses to cancel an order with deliveries against it, or an order HQ did not place', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $created = $this->postJson(($this->url)(), ($this->order)())->assertCreated()->json('data');
    DB::table('sales_orders')->where('id', $created['id'])->update(['status' => SalesOrderStatus::PartiallyDelivered->value]);
    DB::table('sales_order_lines')->where('sales_order_id', $created['id'])->limit(1)->update(['quantity_delivered' => '1']);

    $this->postJson(($this->url)($created['shop_reference'].'/cancel'), ['idempotency_key' => 'hq:cancel-000000001', 'reason' => 'Too late now.'])->assertStatus(409)
        ->assertJsonPath('error.code', 'has_deliveries');
    $this->postJson(($this->url)('SO-NOT-HQ-0001/cancel'), ['idempotency_key' => 'hq:cancel-000000003', 'reason' => 'Not ours.'])->assertStatus(404);

    expect(SalesOrder::withoutGlobalScopes()->sole()->status)->toBe(SalesOrderStatus::PartiallyDelivered)
        ->and(SalesOrderHqCancellation::withoutGlobalScopes()->count())->toBe(0);
});

// ── abilities, isolation, immutability ─────────────────────────────────────────────────────────

it('needs its own ability on every route, and the two writes are issued only by name with --write', function () {
    ($this->writer)($this->a);
    ($this->draft)();

    ($this->token)($this->a, HqApiToken::READ_ABILITIES);
    $this->postJson(($this->url)(), ($this->order)())->assertStatus(403)->assertJsonPath('error.message', 'Token lacks ability sales.orders.record.');
    $this->postJson(($this->url)('X/cancel'), ['idempotency_key' => 'hq:cancel-000000001', 'reason' => 'x x x'])->assertStatus(403);
    ($this->token)($this->a, ['sales.orders.draft']);
    $this->postJson(($this->url)('quote'), ($this->quote)())->assertStatus(403)->assertJsonPath('error.message', 'Token lacks ability sales.view.');

    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'sales.orders.record'])->assertFailed();
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'sales.orders.cancel'])->assertFailed();
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'sales.orders.approve', '--write' => true])->assertFailed();
    $this->artisan('hq:api:token', ['email' => 'hq@bordeguna.test', 'business' => $this->a->id, '--abilities' => 'sales.orders.record,sales.orders.cancel', '--write' => true])->assertSuccessful();

    expect(HqApiToken::WRITE_ABILITIES)->toBe(['sales.orders.draft', 'sales.orders.record', 'sales.orders.cancel'])
        ->and(in_array('sales.orders.approve', [...HqApiToken::READ_ABILITIES, ...HqApiToken::WRITE_ABILITIES], true))->toBeFalse()
        ->and(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps each business to itself', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $created = $this->postJson(($this->url)(), ($this->order)())->assertCreated()->json('data');

    ($this->writer)($this->b);
    $this->postJson(($this->url)('', $this->b), ($this->order)())->assertStatus(404);
    $this->getJson(($this->url)($created['order_number'], $this->b))->assertStatus(404);
    $this->postJson(($this->url)('', $this->a), ($this->order)())->assertStatus(403);
});

it('keeps its link and cancellation rows immutable, each written by one action, behind exactly five routes', function () {
    ($this->writer)($this->a);
    ($this->draft)();
    $created = $this->postJson(($this->url)(), ($this->order)())->assertCreated()->json('data');
    $this->postJson(($this->url)($created['shop_reference'].'/cancel'), ['idempotency_key' => 'hq:cancel-000000001', 'reason' => 'Changed mind.'])->assertOk();

    $link = SalesOrderHqConfirmation::withoutGlobalScopes()->sole();
    $cancellation = SalesOrderHqCancellation::withoutGlobalScopes()->sole();
    expect(fn () => $link->update(['idempotency_key' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $link->delete())->toThrow(LogicException::class)
        ->and(fn () => $cancellation->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $cancellation->delete())->toThrow(LogicException::class);

    $writers = fn (string $model) => collect(File::allFiles(app_path()))
        ->filter(fn ($f) => $f->getExtension() === 'php' && preg_match('/'.$model.'::(create|query\(\)->(update|delete|insert))/', (string) file_get_contents($f->getPathname())) === 1)
        ->map(fn ($f) => str_replace('\\', '/', $f->getRelativePathname()))->values()->all();
    expect($writers('SalesOrderHqConfirmation'))->toBe(['Modules/Sales/Actions/ConfirmHqSalesOrderDraft.php'])
        ->and($writers('SalesOrderHqCancellation'))->toBe(['Modules/Sales/Actions/CancelHqSalesOrder.php']);

    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), '/sales-orders'));
    expect($routes->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->sort()->values()->all())->toBe([
        'GET|HEAD api/hq/v1/{business}/sales-orders/by-key/{key}',
        'GET|HEAD api/hq/v1/{business}/sales-orders/{reference}',
        'POST api/hq/v1/{business}/sales-orders',
        'POST api/hq/v1/{business}/sales-orders/quote',
        'POST api/hq/v1/{business}/sales-orders/{reference}/cancel',
    ]);
});

// ── the contract fixtures HQ carries a copy of ─────────────────────────────────────────────────

it('holds the exact bodies HQ is built against, and their digest, so the two repositories cannot drift', function () {
    ($this->writer)($this->a);
    ($this->draft)();

    $bodies = [
        'quote' => $this->postJson(($this->url)('quote'), ($this->quote)())->assertOk()->json(),
        'quote-refused' => $this->postJson(($this->url)('quote'), ($this->quote)(['lines' => [['sku' => 'NOPE-1']]]))->assertStatus(422)->json(),
        'created' => $this->postJson(($this->url)(), ($this->order)())->assertCreated()->json(),
        'replayed' => $this->postJson(($this->url)(), ($this->order)())->assertOk()->json(),
        'key-reused' => $this->postJson(($this->url)(), ($this->order)(['hq_reference' => ['tool_call_id' => 99]]))->assertStatus(409)->json(),
        'already-confirmed' => $this->postJson(($this->url)(), ($this->order)(['idempotency_key' => 'hq:order-0000000009']))->assertStatus(409)->json(),
        'not-found' => $this->getJson(($this->url)('LLD-NOBODY-0001'))->assertStatus(404)->json(),
    ];
    $reference = $bodies['created']['data']['shop_reference'];
    $bodies['read'] = $this->getJson(($this->url)($reference))->assertOk()->json();
    $bodies['by-key'] = $this->getJson(($this->url)('by-key/'.ORDER_KEY))->assertOk()->json();

    // A second draft for the refusals that need a changed price, restored after.
    ($this->draft)(['idempotency_key' => 'hq:draft-0000000002']);
    ProductPrice::withoutGlobalScopes()->where('product_id', $this->world[$this->a->id]['honey']->id)->update(['unit_price' => '900']);
    $bodies['draft-repriced'] = $this->postJson(($this->url)(), ($this->order)(['idempotency_key' => 'hq:order-0000000002', 'draft_key' => 'hq:draft-0000000002']))->assertStatus(422)->json();
    ProductPrice::withoutGlobalScopes()->where('product_id', $this->world[$this->a->id]['honey']->id)->update(['unit_price' => '850']);

    $cancel = ['idempotency_key' => 'hq:cancel-000000001', 'reason' => 'The customer changed their mind on the phone.'];
    $bodies['cancelled'] = $this->postJson(($this->url)($reference.'/cancel'), $cancel)->assertOk()->json();
    $bodies['cancel-replayed'] = $this->postJson(($this->url)($reference.'/cancel'), $cancel)->assertOk()->json();
    $bodies['already-cancelled'] = $this->postJson(($this->url)($reference.'/cancel'), ['idempotency_key' => 'hq:cancel-000000002'] + $cancel)->assertStatus(409)->json();

    $second = $this->postJson(($this->url)(), ($this->order)(['idempotency_key' => 'hq:order-0000000003', 'draft_key' => 'hq:draft-0000000002']))->assertCreated()->json('data');
    DB::table('sales_orders')->where('id', $second['id'])->update(['status' => SalesOrderStatus::PartiallyDelivered->value]);
    $bodies['has-deliveries'] = $this->postJson(($this->url)($second['shop_reference'].'/cancel'), ['idempotency_key' => 'hq:cancel-000000003', 'reason' => 'Too late now.'])->assertStatus(409)->json();

    $bodies['forbidden'] = (function () use ($reference) {
        ($this->token)($this->a, ['inventory.view']);

        return $this->getJson(($this->url)($reference))->assertStatus(403)->json();
    })();

    // ⚠ The shop reference ends in eight random characters (`ShopOrderReference`); the fixtures carry a
    // fixed stand-in so the digest is stable. Everything else is exactly what the endpoint answered.
    $fixed = [$reference => 'LLD'.$this->a->id.'-20260921-ABCD2345', $second['shop_reference'] => 'LLD'.$this->a->id.'-20260921-EFGH6789'];
    $bodies = array_map(fn (array $body) => json_decode(strtr(orderCanonical($body), $fixed), true), $bodies);

    ksort($bodies);
    $digests = array_map(fn (array $body) => hash('sha256', orderCanonical($body)), $bodies);
    $contract = hash('sha256', orderCanonical($digests));
    $dir = base_path(ORDERS_FIXTURES);

    if (getenv('HQ_API_WRITE_FIXTURES') === '1') {
        @mkdir($dir, 0775, true);
        foreach ($bodies as $name => $body) {
            file_put_contents("{$dir}/{$name}.json", json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
        file_put_contents("{$dir}/manifest.json", json_encode([
            'contract' => 'hq-sales-orders.v1',
            'origin' => 'bordeguna-erp tests/Feature/HqApi/HqSalesOrderTest.php',
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
        ->and($contract)->toBe(ORDERS_CONTRACT_DIGEST, 'the contract changed: re-pin ORDERS_CONTRACT_DIGEST here AND in HQ, and copy the fixtures to HQ');
});
