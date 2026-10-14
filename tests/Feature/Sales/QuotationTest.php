<?php

use App\Models\User;
use App\Modules\Crm\database\seeders\CrmPermissionsSeeder;
use App\Modules\Crm\Models\Lead;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 4, Slice 2 (CRM spec §13): quotations.
 *
 * A quotation is a PROMISE OF A PRICE with an expiry date, nothing more: it
 * reserves no stock and posts nothing. Accepting it is what creates the
 * sales order - carrying the quoted prices verbatim, because the negotiated
 * number is the whole point of having quoted. A quote past its validity
 * refuses acceptance; a quote to a lead refuses until the lead is a
 * customer, through the existing gated conversion.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Quote Co', 'slug' => 'quote-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new SalesPermissionsSeeder)->run();
    (new CrmPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-09-03 10:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);
    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $this->product->id,
        'unit_price' => '850', 'effective_from' => '2026-01-01',
    ]);

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Rahim Traders', 'type' => 'dealer', 'is_active' => true,
    ]);
    $this->lead = Lead::create([
        'business_id' => $this->business->id, 'name' => 'Karim Store', 'phone' => '01722222222',
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

function makeQuote(array $overrides = []): int
{
    (test()->asOwner)()->post(route('sales.quotations.store'), array_merge([
        'customer_id' => test()->customer->id,
        'valid_until' => '2026-09-20',
        'lines' => [
            // A negotiated price below list: the point of quoting at all.
            ['product_id' => test()->product->id, 'quantity' => '100', 'unit_price' => '800'],
        ],
    ], $overrides))->assertSessionHasNoErrors();

    return (int) DB::table('quotations')->latest('id')->value('id');
}

it('creates a numbered quotation that reserves nothing and posts nothing', function () {
    $journalsBefore = DB::table('journals')->count();

    makeQuote();

    $quote = DB::table('quotations')->first();
    expect($quote->quotation_number)->toStartWith('QT-')
        ->and($quote->status)->toBe('draft')
        ->and(DB::table('journals')->count())->toBe($journalsBefore)
        ->and(DB::table('sales_orders')->count())->toBe(0);

    ($this->asOwner)()->get(route('sales.quotations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/quotations/index')
            ->has('quotations.data', 1)
            ->where('quotations.data.0.party', 'Rahim Traders'));
});

it('accepting a quote creates the sales order at the quoted prices, verbatim', function () {
    $quote = makeQuote();

    ($this->asOwner)()->post(route('sales.quotations.send', $quote))->assertSessionHasNoErrors();
    ($this->asOwner)()->post(route('sales.quotations.accept', $quote), [
        'warehouse_id' => $this->warehouse->id,
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::query()->with('lines')->first();
    $updated = DB::table('quotations')->find($quote);

    expect($order)->not->toBeNull()
        ->and((float) $order->lines->first()->unit_price)->toBe(800.0)
        ->and($order->lines->first()->price_note)->toContain('QT-')
        ->and($updated->status)->toBe('accepted')
        ->and($updated->sales_order_id)->toBe($order->id);
});

it('refuses to accept a quote past its validity', function () {
    $quote = makeQuote(['valid_until' => '2026-09-04']);

    Carbon::setTestNow('2026-09-10 10:00:00');

    ($this->asOwner)()->post(route('sales.quotations.accept', $quote), [
        'warehouse_id' => $this->warehouse->id,
    ])->assertSessionHasErrors('quotation');

    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('accepts a quote on its last valid day, all of that day, and refuses it the day after (SAL-005)', function () {
    $quote = makeQuote(['valid_until' => '2026-09-03']);

    Carbon::setTestNow('2026-09-03 23:30:00');

    ($this->asOwner)()->post(route('sales.quotations.accept', $quote), [
        'warehouse_id' => $this->warehouse->id,
    ])->assertSessionHasNoErrors();

    expect(DB::table('sales_orders')->count())->toBe(1);

    $late = makeQuote(['valid_until' => '2026-09-03']);

    Carbon::setTestNow('2026-09-04 00:05:00');

    ($this->asOwner)()->post(route('sales.quotations.accept', $late), [
        'warehouse_id' => $this->warehouse->id,
    ])->assertSessionHasErrors('quotation');

    expect(DB::table('sales_orders')->count())->toBe(1);
});

it('creates one sales order when a quote is accepted twice at once (SAL-003)', function () {
    $quote = makeQuote();

    // The other request commits its acceptance between this request's first
    // read of the quote and its transaction: what two clicks do on MySQL.
    $raced = false;
    DB::listen(function ($query) use (&$raced, $quote) {
        if (! $raced && str_contains($query->sql, 'from "quotations"')) {
            $raced = true;
            DB::table('quotations')->where('id', $quote)->update(['status' => 'accepted']);
        }
    });

    ($this->asOwner)()->post(route('sales.quotations.accept', $quote), [
        'warehouse_id' => $this->warehouse->id,
    ])->assertSessionHasErrors('quotation');

    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('quotes a lead but refuses acceptance until the lead is a customer', function () {
    $quote = makeQuote(['customer_id' => null, 'lead_id' => $this->lead->id]);

    ($this->asOwner)()->post(route('sales.quotations.accept', $quote), [
        'warehouse_id' => $this->warehouse->id,
    ])->assertSessionHasErrors('quotation');

    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('rejects with the decision kept, and prints on the letterhead', function () {
    $quote = makeQuote();

    ($this->asOwner)()->post(route('sales.quotations.reject', $quote))->assertSessionHasNoErrors();
    expect(DB::table('quotations')->find($quote)->status)->toBe('rejected');

    ($this->asOwner)()->get(route('sales.quotations.print', $quote))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/quotations/print')
            ->has('issuer.name')
            ->where('quotation.lines.0.unit_price', fn ($value) => (float) $value === 800.0));
});
