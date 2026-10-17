<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\CataloguePublishing;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\RecordGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Actions\ConfirmMarketPrice;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\PricingMode;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\MarketPrice;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\ProductPriceHistory;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Support\ProductPricing;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Market-priced goods (Phase 2 / 1B, 17 Oct 2026; ChatGPT ruling Q4, Q5, Q6).
 *
 * The rules this file pins, red to green, one each:
 *   the formula and its round-UP to the step (the worked example 123.45 / 20 / 25 / 10 = 170);
 *   category defaults with a SKU override; the receipt PROPOSES and the receiver
 *   CONFIRMS (an unconfirmed receipt leaves the old price); below cost refused;
 *   below the margin needs a reason AND the price-setting permission; no cost
 *   basis = not sellable, and a typed price does not unlock it; the MODE decides
 *   over an MRP and over a hand-typed row; one active price per product; the
 *   badge and the snapshot on a counter sale; a weighed line's total is qty x
 *   unit price with paisa rounding, never step-rounded; two receipts of the same
 *   product: last confirmed wins and both are in the history; the history is
 *   immutable; capReferencePrice for Builder E; shops refuse market products
 *   until the flag is on.
 */
const MP_GRANTS = [
    'Owner' => ['sales.prices.manage', 'sales.sell', 'sales.price.override', 'sales.reports.view', 'sales.orders.record', 'purchasing.receipt.record', 'purchasing.reports.view', 'purchasing.po.approve', 'inventory.catalog.manage', 'inventory.reports.view'],
    'Staff' => ['sales.sell', 'sales.reports.view', 'sales.orders.record', 'purchasing.receipt.record', 'purchasing.reports.view'],
];

beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:15:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Fish Co', 'slug' => 'fish-co']);
    TenantScope::enter($this->business->id);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (MP_GRANTS as $role => $permissions) {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::create(['name' => $role, 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new PriceGroupSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->member = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };
    $this->owner = ($this->member)('Owner');
    $this->staff = ($this->member)('Staff');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Karwan Bazar', 'code' => 'KB', 'is_default' => true]);
    $kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->fish = Category::create([
        'business_id' => $this->business->id, 'name' => 'Fish', 'slug' => 'fish', 'code' => 'FSH',
        'market_markup_percent' => '20', 'market_min_margin_percent' => '25', 'market_rounding_step' => '10',
    ]);
    $this->hilsa = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $kg->id, 'category_id' => $this->fish->id,
        'sku' => 'FSH-HILSA', 'name' => 'Hilsa', 'type' => ProductType::FinishedGood, 'pricing_mode' => PricingMode::Market->value,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Ghat Arot', 'code' => 'SUP-001']);
    // ⚠ A bank, not a till: a cash sale needs an open shift, which is the till's rule, not this file's subject.
    $this->cash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'account_id' => Account::bySlug('cash_at_bank')->id]);
    $this->resolver = app(ResolveSellingPrice::class);
    $this->retailId = PriceGroup::where('code', 'RETAIL')->value('id');
});

afterEach(fn () => Carbon::setTestNow());

/** An approved order for the hilsa: qty kg at cost per kg. */
function mpOrder($test, string $quantity = '10', string $cost = '123.45')
{
    $order = app(CreatePurchaseOrder::class)->handle(
        supplier: $test->supplier, warehouse: $test->warehouse,
        lines: [['product' => $test->hilsa, 'quantity' => $quantity, 'unit_cost' => $cost]],
        createdBy: $test->owner,
    );
    approveIfPending($order->approvalRequest, $test->owner);

    return $order->fresh(['lines', 'approvalRequest']);
}

/** Receive the whole order, confirming today's price (or not, when null). */
function mpReceive($test, $order, ?string $price, ?string $reason = null, ?User $by = null): GoodsReceipt
{
    return app(RecordGoodsReceipt::class)->handle($order, [[
        'line' => $order->lines->first(),
        'quantity' => (string) $order->lines->first()->quantity_ordered,
        'market_price' => $price,
        'market_price_reason' => $reason,
    ]], $by ?? $test->owner)->fresh('lines');
}

// ─── G1: THE FORMULA ─────────────────────────────────────────────────────────

it('suggests roundUpToStep(max(C x (1 + m/100), C / (1 - g/100))): the worked example gives 170', function () {
    // 123.45 x 1.20 = 148.14; 123.45 / 0.75 = 164.60; the margin term wins; up to the next 10.
    expect(ProductPricing::suggest('123.45', '20', '25', '10'))->toBe('170.0000')
        // Markup only (no minimum margin): 148.14 up to 150.
        ->and(ProductPricing::suggest('123.45', '20', null, '10'))->toBe('150.0000')
        // The markup term wins when it is the larger: 100 x 1.5 = 150 over 100 / 0.9 = 111.11.
        ->and(ProductPricing::suggest('100', '50', '10', '10'))->toBe('150.0000')
        // No step: up to the paisa, never down. 164.604 becomes 164.61.
        ->and(ProductPricing::suggest('123.453', '0', '25', null))->toBe('164.6100')
        // An exact multiple stays where it is: no step is added for nothing.
        ->and(ProductPricing::roundUpToStep('170', '10'))->toBe('170.0000')
        ->and(ProductPricing::roundUpToStep('170.0001', '10'))->toBe('180.0000')
        // A half-taka step.
        ->and(ProductPricing::roundUpToStep('164.60', '0.5'))->toBe('165.0000')
        // No rules at all: the cost itself, rounded up to the paisa.
        ->and(ProductPricing::suggest('123.456', null, null, null))->toBe('123.4600');
});

it('reads the category defaults and lets a SKU override each one on its own', function () {
    expect(ProductPricing::rulesFor($this->hilsa))->toMatchArray(['markup' => '20.0000', 'min_margin' => '25.0000', 'step' => '10.00'])
        ->and(ProductPricing::rulesFor($this->hilsa)['source'])->toBe(['markup' => 'category', 'min_margin' => 'category', 'step' => 'category']);

    $this->hilsa->update(['market_rounding_step' => '5']);
    $rules = ProductPricing::rulesFor($this->hilsa->fresh());

    expect($rules['step'])->toBe('5.00')
        ->and($rules['markup'])->toBe('20.0000')
        ->and($rules['source'])->toBe(['markup' => 'category', 'min_margin' => 'category', 'step' => 'product'])
        ->and(ProductPricing::proposal($this->hilsa->fresh(), '123.45')['suggested'])->toBe('165.0000');
});

// ─── G2: THE RECEIPT PROPOSES, THE RECEIVER CONFIRMS ─────────────────────────

it('confirms the proposed price at receipt: a live RETAIL row, one history row, and the badge', function () {
    $receipt = mpReceive($this, mpOrder($this), '170');

    $line = $receipt->lines->first();
    $history = ProductPriceHistory::sole();
    $live = ProductPrice::where('product_id', $this->hilsa->id)->sole();

    expect($line->market_price_status)->toBe('confirmed')
        ->and($line->market_price_history_id)->toBe($history->id)
        ->and((string) $history->cost_snapshot)->toBe('123.4500')
        ->and((string) $history->suggested_price)->toBe('170.0000')
        ->and((string) $history->new_price)->toBe('170.0000')
        ->and($history->prior_price)->toBeNull()
        ->and($history->is_override)->toBeFalse()
        ->and((string) $history->markup_percent)->toBe('20.0000')
        ->and((string) $history->min_margin_percent)->toBe('25.0000')
        ->and((string) $history->rounding_step)->toBe('10.00')
        ->and($history->stock_batch_id)->toBe(StockBatch::sole()->id)
        ->and($history->confirmed_by)->toBe($this->owner->id)
        ->and($history->product_price_id)->toBe($live->id)
        ->and($live->price_group_id)->toBe($this->retailId)
        ->and($live->market_price_history_id)->toBe($history->id)
        ->and(MarketPrice::sole()->sinceLabel())->toBe('10:15');

    $resolved = $this->resolver->resolve($this->hilsa, Customer::walkIn());

    expect($resolved->unitPrice)->toBe('170.0000')
        ->and($resolved->isMarket())->toBeTrue()
        ->and($resolved->marketSince())->toBe('10:15')
        ->and($resolved->explain())->toBe("Today's price, since 10:15");
});

it('leaves the old price in force when the receipt is not confirmed, and marks the line', function () {
    mpReceive($this, mpOrder($this, '10', '100'), '150');
    $receipt = mpReceive($this, mpOrder($this, '10', '123.45'), null);

    expect($receipt->lines->first()->market_price_status)->toBe('unconfirmed')
        ->and(ProductPriceHistory::count())->toBe(1)
        ->and($this->resolver->resolve($this->hilsa, Customer::walkIn())->unitPrice)->toBe('150.0000')
        ->and((string) MarketPrice::sole()->unit_price)->toBe('150.0000');
});

it('refuses a price below cost outright, whoever asks', function () {
    expect(fn () => mpReceive($this, mpOrder($this), '120'))->toThrow(InvalidArgumentException::class, 'never sold below cost');

    // The whole receipt rolled back: no stock, no receipt, no price.
    expect(StockBatch::count())->toBe(0)
        ->and(GoodsReceipt::count())->toBe(0)
        ->and(MarketPrice::count())->toBe(0);
});

it('needs a reason and the price-setting permission to go under the minimum margin', function () {
    // 123.45 / 0.75 = 164.60 is the floor. 160 is under it.
    expect(fn () => mpReceive($this, mpOrder($this), '160'))->toThrow(InvalidArgumentException::class, 'Say why');
    expect(fn () => mpReceive($this, mpOrder($this), '160', 'Soft fish, must clear today', $this->staff))
        ->toThrow(InvalidArgumentException::class, 'price-setting permission');

    $receipt = mpReceive($this, mpOrder($this), '160', 'Soft fish, must clear today');
    $history = ProductPriceHistory::sole();

    expect($receipt->lines->first()->market_price_status)->toBe('confirmed')
        ->and($history->is_override)->toBeTrue()
        ->and($history->override_reason)->toBe('Soft fish, must clear today')
        ->and((string) $history->new_price)->toBe('160.0000');

    // Above the floor but not the suggestion: an override on record, no reason needed.
    mpReceive($this, mpOrder($this), '165');
    expect(ProductPriceHistory::orderByDesc('id')->first()->is_override)->toBeTrue()
        ->and(ProductPriceHistory::orderByDesc('id')->first()->override_reason)->toBeNull();

    // The receiver (Staff) may confirm the proposal itself.
    mpReceive($this, mpOrder($this), '170', null, $this->staff);
    expect((string) MarketPrice::sole()->unit_price)->toBe('170.0000');
});

// ─── G4: FAIL CLOSED ─────────────────────────────────────────────────────────

it('is not sellable with no received cost basis and no confirmed price, and a typed price does not unlock it', function () {
    expect($this->resolver->find($this->hilsa, Customer::walkIn()))->toBeNull()
        ->and(ProductPricing::capReferencePrice($this->hilsa))->toBeNull();

    // The counter grid hides it and names it with the reason.
    $props = $this->actingAs($this->owner)->get(route('sales.counter.create'))->assertOk()->viewData('page')['props'];
    expect($props['cardPrices'])->not->toHaveKey($this->hilsa->id)
        ->and($props['marketUnpriced'])->toBe(['Hilsa']);

    // The counter's price check says why.
    $this->actingAs($this->owner)->getJson(route('sales.counter.price', ['product_id' => $this->hilsa->id, 'quantity' => '1']))
        ->assertOk()->assertJsonPath('unit_price', null)->assertJsonPath('refusal', fn ($text) => str_contains($text, 'no confirmed price today'));

    // The order form's price check refuses the line.
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Buyer']);
    $this->actingAs($this->owner)->getJson(route('sales.orders.price', ['product_id' => $this->hilsa->id, 'quantity' => '1', 'customer_id' => $customer->id]))
        ->assertOk()->assertJsonPath('refusal', fn ($text) => str_contains($text, 'cannot be ordered'));

    // Stock exists but nobody confirmed a price: still not sellable, even for the Owner typing a price.
    mpReceive($this, mpOrder($this), null);
    $this->actingAs($this->owner)->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id, 'warehouse_id' => $this->warehouse->id, 'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->hilsa->id, 'quantity' => '1', 'unit_price' => '200', 'override_reason' => 'Boss said so']],
    ])->assertSessionHasErrors();
    expect(SalesInvoice::count())->toBe(0);
});

it('lets the mode decide: a market product never falls back to its MRP or to a hand-typed row', function () {
    $this->hilsa->update(['mrp' => '500']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->hilsa->id, 'price_group_id' => $this->retailId, 'unit_price' => '480', 'effective_from' => '2026-01-01']);

    expect($this->resolver->find($this->hilsa->fresh(), Customer::walkIn()))->toBeNull();

    // Switched back to fixed_mrp, the same rows price it as they always did.
    $this->hilsa->update(['pricing_mode' => 'fixed_mrp']);
    expect($this->resolver->resolve($this->hilsa->fresh(), Customer::walkIn())->unitPrice)->toBe('480.0000');

    // A null mode (a row older than the column) is fixed_mrp.
    $this->hilsa->update(['pricing_mode' => null]);
    expect($this->hilsa->fresh()->pricingMode())->toBe(PricingMode::FixedMrp);
});

// ─── ONE ACTIVE PRICE, CONCURRENCY, IMMUTABILITY ─────────────────────────────

it('keeps one active price per product: the last confirmed wins and both are in the history', function () {
    // Two receipts of the same fish this morning, two confirmations.
    $first = mpReceive($this, mpOrder($this, '10', '100'), '150');
    Carbon::setTestNow('2026-10-18 11:40:00');
    $second = mpReceive($this, mpOrder($this, '10', '123.45'), '170');

    $rows = ProductPrice::where('product_id', $this->hilsa->id)->orderBy('id')->get();

    expect(MarketPrice::count())->toBe(1)
        ->and((string) MarketPrice::sole()->unit_price)->toBe('170.0000')
        ->and(MarketPrice::sole()->sinceLabel())->toBe('11:40')
        ->and(ProductPriceHistory::count())->toBe(2)
        ->and((string) ProductPriceHistory::orderByDesc('id')->first()->prior_price)->toBe('150.0000')
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->effective_to->toDateString())->toBe('2026-10-18')
        ->and($rows[1]->effective_to)->toBeNull()
        ->and($this->resolver->resolve($this->hilsa, Customer::walkIn())->unitPrice)->toBe('170.0000')
        ->and($this->resolver->resolve($this->hilsa, Customer::walkIn())->marketSince())->toBe('11:40')
        ->and($first->lines->first()->fresh()->market_price_status)->toBe('confirmed')
        ->and($second->lines->first()->fresh()->market_price_status)->toBe('confirmed');

    // A confirmed line is confirmed once; a later change is a manual reprice.
    $this->actingAs($this->owner)->post(route('sales.market-prices.confirm-receipt-line', $first->lines->first()->id), ['price' => '180'])
        ->assertSessionHasErrors('price');
});

it('confirms an unconfirmed receipt line later, from that lot\'s cost, by the receiver', function () {
    $receipt = mpReceive($this, mpOrder($this), null);

    $this->actingAs($this->staff)->post(route('sales.market-prices.confirm-receipt-line', $receipt->lines->first()->id), ['price' => '170'])
        ->assertSessionHasNoErrors();

    $history = ProductPriceHistory::sole();

    expect($receipt->lines->first()->fresh()->market_price_status)->toBe('confirmed')
        ->and((string) $history->cost_snapshot)->toBe('123.4500')
        ->and($history->goods_receipt_line_id)->toBe($receipt->lines->first()->id)
        ->and($history->confirmed_by)->toBe($this->staff->id)
        ->and((string) MarketPrice::sole()->unit_price)->toBe('170.0000');
});

it('reprices by hand from the latest received cost with the same gates, and never edits history', function () {
    mpReceive($this, mpOrder($this), '170');

    // Below the margin without a reason: refused under the price box.
    $this->actingAs($this->owner)->post(route('sales.market-prices.store'), ['product_id' => $this->hilsa->id, 'price' => '160'])
        ->assertSessionHasErrors('price');
    // Staff may not reprice by hand at all.
    $this->actingAs($this->staff)->post(route('sales.market-prices.store'), ['product_id' => $this->hilsa->id, 'price' => '180'])
        ->assertForbidden();

    $this->actingAs($this->owner)->post(route('sales.market-prices.store'), ['product_id' => $this->hilsa->id, 'price' => '180'])
        ->assertSessionHasNoErrors();

    $latest = ProductPriceHistory::orderByDesc('id')->first();

    expect(ProductPriceHistory::count())->toBe(2)
        ->and($latest->source)->toBe('manual')
        ->and((string) $latest->prior_price)->toBe('170.0000')
        ->and((string) $latest->new_price)->toBe('180.0000')
        ->and($latest->stock_batch_id)->toBe(StockBatch::sole()->id)
        ->and($this->resolver->resolve($this->hilsa, Customer::walkIn())->unitPrice)->toBe('180.0000');

    expect(fn () => $latest->update(['new_price' => '1']))->toThrow(DomainException::class, 'never edited');
    expect(fn () => $latest->delete())->toThrow(DomainException::class, 'never deleted');

    // The proposal the popup shows, from the latest lot.
    $this->actingAs($this->owner)->getJson(route('sales.market-prices.proposal', ['product_id' => $this->hilsa->id]))
        ->assertOk()->assertJsonPath('cost', '123.4500')->assertJsonPath('suggested', '170.0000')
        ->assertJsonPath('min_margin_price', '164.6000')->assertJsonPath('current_price', '180.0000');
});

// ─── G5: THE COUNTER ─────────────────────────────────────────────────────────

it('shows the badge on the counter and snapshots today\'s price on the invoice line', function () {
    mpReceive($this, mpOrder($this), '170');

    $props = $this->actingAs($this->owner)->get(route('sales.counter.create'))->assertOk()->viewData('page')['props'];
    expect($props['cardPrices'][$this->hilsa->id])->toBe(170.0)
        // ⚠ Cast `(object)` on the server (an empty map must reach the screen as {}), so stdClass here.
        ->and(((array) $props['marketSince'])[$this->hilsa->id])->toBe('10:15')
        ->and($props['marketUnpriced'])->toBe([]);

    // The screen always names the buyer (the walk-in by default); nobody named is the HQ-style standard-tier check.
    $this->actingAs($this->owner)->getJson(route('sales.counter.price', ['product_id' => $this->hilsa->id, 'quantity' => '2', 'customer_id' => Customer::walkIn()->id]))
        ->assertOk()->assertJsonPath('unit_price', '170.0000')->assertJsonPath('market_since', '10:15')
        ->assertJsonPath('explanation', "Today's price, since 10:15");

    $this->actingAs($this->staff)->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id, 'warehouse_id' => $this->warehouse->id, 'money_account_id' => $this->cash->id,
        'lines' => [['product_id' => $this->hilsa->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    $line = SalesInvoiceLine::sole();
    expect((string) $line->unit_price)->toBe('170.0000');

    // The price moves on; the invoice line does not.
    app(ConfirmMarketPrice::class)->handle($this->hilsa, '190', '123.45', $this->owner);
    expect((string) $line->fresh()->unit_price)->toBe('170.0000')
        ->and($this->resolver->resolve($this->hilsa, Customer::walkIn())->unitPrice)->toBe('190.0000');
});

it('totals a weighed line as qty x unit price with paisa rounding, never step-rounded', function () {
    mpReceive($this, mpOrder($this), '170');

    // 1.25 kg at Tk 170/kg = 212.50. Step-rounding the total would say 220.
    $sale = app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(), warehouse: $this->warehouse,
        lines: [['product' => $this->hilsa, 'quantity' => '1.25']],
        createdBy: $this->owner, moneyAccount: $this->cash,
    );

    expect((float) $sale->invoice->total)->toBe(212.5);

    // 0.333 kg at 170 = 56.61 exactly to the paisa (56.61), not 60.
    $sale = app(RecordCounterSale::class)->handle(
        customer: Customer::walkIn(), warehouse: $this->warehouse,
        lines: [['product' => $this->hilsa, 'quantity' => '0.333']],
        createdBy: $this->owner, moneyAccount: $this->cash,
    );

    expect((float) $sale->invoice->total)->toBe(56.61);
});

// ─── G6 / G7 ─────────────────────────────────────────────────────────────────

it('gives Builder E the cap reference price: MRP for fixed_mrp, today\'s price for market', function () {
    $jar = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->hilsa->unit_of_measure_id, 'sku' => 'HON', 'name' => 'Honey', 'type' => ProductType::FinishedGood, 'mrp' => '500']);
    $bare = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->hilsa->unit_of_measure_id, 'sku' => 'BARE', 'name' => 'Bare', 'type' => ProductType::FinishedGood]);

    mpReceive($this, mpOrder($this), '170');

    expect(ProductPricing::capReferencePrice($jar))->toBe('500.0000')
        ->and(ProductPricing::capReferencePrice($bare))->toBeNull()
        ->and(ProductPricing::capReferencePrice($this->hilsa))->toBe('170.0000');
});

it('keeps market products off the shops until shops.market_pricing is on', function () {
    mpReceive($this, mpOrder($this), '170');
    $store = Store::create(['business_id' => $this->business->id, 'key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD', 'price_group_id' => $this->retailId]);

    // ⚠ fresh(): the in-memory instance was created without `is_active`, which the DB defaults to true.
    config(['shops.market_pricing' => false]);
    expect(CataloguePublishing::refusalFor($store, $this->hilsa->fresh()))->toContain('priced from the market');

    config(['shops.market_pricing' => true]);
    expect(CataloguePublishing::refusalFor($store, $this->hilsa->fresh()))->toBeNull();
});

it('shows the proposal on the purchase order screen and keeps the unconfirmed line visible on the receipt', function () {
    $order = mpOrder($this);

    $props = $this->actingAs($this->owner)->get(route('purchasing.purchase-orders.show', $order->id))->assertOk()->viewData('page')['props'];
    $proposal = ((array) $props['marketProposals'])[$order->lines->first()->id];

    expect($proposal['suggested'])->toBe('170.0000')
        ->and($proposal['cost'])->toBe('123.4500')
        ->and($proposal['min_margin_price'])->toBe('164.6000')
        ->and($proposal['current_price'])->toBeNull()
        ->and($props['can_override_margin'])->toBeTrue();

    // Through the screen's own route, unconfirmed: the receipt says so, with a warning.
    $this->actingAs($this->staff)->post(route('purchasing.purchase-orders.receive', $order->id), [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '10', 'market_price' => '']],
    ])->assertSessionHasNoErrors()->assertSessionHas('warning', fn ($text) => str_contains($text, 'Price not confirmed for Hilsa'));

    $props = $this->actingAs($this->owner)->get(route('purchasing.purchase-orders.show', $order->id))->assertOk()->viewData('page')['props'];
    expect($props['receipts'][0]['lines'][0]['market_price_status'])->toBe('unconfirmed')
        ->and($props['receipts'][0]['lines'][0]['pricing_mode'])->toBe('market');

    // A price under the margin typed on the screen lands under its own box.
    $order = mpOrder($this);
    $this->actingAs($this->staff)->post(route('purchasing.purchase-orders.receive', $order->id), [
        'lines' => [['purchase_order_line_id' => $order->lines->first()->id, 'quantity' => '10', 'market_price' => '160']],
    ])->assertSessionHasErrors('lines.0.market_price');
});

it('stores the pricing mode and the rule overrides from the product form, and the defaults from the category form', function () {
    $this->actingAs($this->owner)->put(route('inventory.categories.update', $this->fish->id), ['name' => 'Fish', 'code' => 'FSH', 'market_markup_percent' => '30', 'market_min_margin_percent' => '', 'market_rounding_step' => '5'])
        ->assertSessionHasNoErrors();

    expect((string) $this->fish->fresh()->market_markup_percent)->toBe('30.0000')
        ->and($this->fish->fresh()->market_min_margin_percent)->toBeNull();

    // A minimum margin of 100% would divide by zero: refused.
    $this->actingAs($this->owner)->put(route('inventory.categories.update', $this->fish->id), ['name' => 'Fish', 'market_min_margin_percent' => '100'])
        ->assertSessionHasErrors('market_min_margin_percent');

    $this->actingAs($this->owner)->put(route('inventory.products.update', $this->hilsa->id), [
        'sku' => 'FSH-HILSA', 'name' => 'Hilsa', 'type' => 'finished_good', 'unit_of_measure_id' => $this->hilsa->unit_of_measure_id,
        'category_id' => $this->fish->id, 'pricing_mode' => 'market', 'market_min_margin_percent' => '15',
    ])->assertSessionHasNoErrors();

    $rules = ProductPricing::rulesFor($this->hilsa->fresh());
    expect($rules)->toMatchArray(['markup' => '30.0000', 'min_margin' => '15.0000', 'step' => '5.00']);

    $this->actingAs($this->owner)->put(route('inventory.products.update', $this->hilsa->id), [
        'sku' => 'FSH-HILSA', 'name' => 'Hilsa', 'type' => 'finished_good', 'unit_of_measure_id' => $this->hilsa->unit_of_measure_id, 'pricing_mode' => 'wholesale',
    ])->assertSessionHasErrors('pricing_mode');
});
