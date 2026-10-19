<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy, 19 Oct 2026: "date i needs so i can add previous order also". The
 * order form takes the selling date; the order is priced as on that day, a
 * future date is refused, and a challan cannot be dated before its order.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Dated Co', 'slug' => 'dated-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['sales.reports.view', 'sales.orders.record', 'sales.orders.approve', 'sales.price.override', 'sales.sell'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.reports.view', 'sales.orders.record']);
    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.reports.view', 'sales.orders.record', 'sales.orders.approve', 'sales.price.override', 'sales.sell']);

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');
    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-PICKLE', 'name' => 'Hilsa Pickle 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);

    Carbon::setTestNow('2026-09-21 10:00:00');
    // The price went up on the 15th: an order sold on the 10th was at 850.
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-09-01']);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '900', 'effective_from' => '2026-09-15']);

    $this->shop = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Store', 'type' => CustomerType::Retail]);
});

afterEach(fn () => Carbon::setTestNow());

it('records an order on the day it was sold, priced as on that day', function () {
    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-10',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::with('lines')->sole();

    expect($order->ordered_at->toDateString())->toBe('2026-09-10')
        ->and((string) $order->lines->first()->unit_price)->toBe('850.0000');
});

it('keeps today as the order date when none is given', function () {
    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    expect(SalesOrder::with('lines')->sole())
        ->ordered_at->toDateString()->toBe('2026-09-21')
        ->and((string) SalesOrder::with('lines')->sole()->lines->first()->unit_price)->toBe('900.0000');
});

it('refuses an order dated in the future, and an expected date before the order', function () {
    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-22',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasErrors(['ordered_at' => 'An order cannot be dated in the future.']);

    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-10',
        'expected_date' => '2026-09-09',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasErrors('expected_date');

    expect(SalesOrder::count())->toBe(0);
});

it('takes a line typed by its total as the unit price worked out from it', function () {
    // 2,551 for 3: the form sends 850.3333, and 3 x 850.3333 is 2,551.00 to the
    // paisa. (Still inside the discount cap: the cap applies to typed totals too.)
    $this->actingAs($this->manager)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-10',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '3', 'unit_price' => '850.3333']],
    ])->assertSessionHasNoErrors();

    $line = SalesOrder::with('lines')->sole()->lines->first();

    expect((string) $line->unit_price)->toBe('850.3333')
        ->and(Money::ofProduct('3', (string) $line->unit_price)->toTaka())->toBe('2551.00');
});

it('answers the price in force on the order date', function () {
    $ask = fn (array $extra) => $this->actingAs($this->staff)->getJson(route('sales.orders.price', [
        'product_id' => $this->product->id, 'quantity' => 2, 'customer_id' => $this->shop->id, ...$extra,
    ]));

    $ask([])->assertOk()->assertJson(['unit_price' => '900.0000']);
    $ask(['on' => '2026-09-10'])->assertOk()->assertJson(['unit_price' => '850.0000']);
    $ask(['on' => '2026-09-22'])->assertUnprocessable()->assertJsonValidationErrors('on');
});

it('names the day when a past date has no price, and asks a price-setter to type it', function () {
    $ask = fn (User $as) => $this->actingAs($as)->getJson(route('sales.orders.price', [
        'product_id' => $this->product->id, 'quantity' => 2, 'customer_id' => $this->shop->id, 'on' => '2026-08-20',
    ]));

    $ask($this->manager)->assertOk()->assertJson([
        'unit_price' => null,
        'refusal' => 'No price was set for Karim Store on Hilsa Pickle 400g on 20/08/2026. Type the price it was sold at.',
    ]);
    $ask($this->staff)->assertOk()->assertJson([
        'refusal' => 'No price was set for Karim Store on Hilsa Pickle 400g on 20/08/2026.',
    ]);
});

it('refuses a challan dated before its order', function () {
    $this->actingAs($this->manager)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-10',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::with('lines')->sole();

    $this->actingAs($this->manager)->post(route('sales.orders.deliver', $order), [
        'warehouse_id' => $this->warehouse->id,
        'delivered_at' => '2026-09-09',
        'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '2']],
    ])->assertSessionHasErrors([
        'delivered_at' => "A challan cannot be dated before its order. {$order->order_number} was ordered on 10/09/2026.",
    ]);
});

it('says when a back-dated challan would take stock that arrived later, instead of failing', function () {
    // The goods came in on the 15th; a challan dated the 12th cannot take them.
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '500', $this->manager, occurredAt: '2026-09-15 09:00:00');

    $this->actingAs($this->manager)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-10',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::with('lines')->sole();
    $deliver = fn (string $on) => $this->actingAs($this->manager)->post(route('sales.orders.deliver', $order), [
        'warehouse_id' => $this->warehouse->id,
        'delivered_at' => $on,
        'lines' => [['sales_order_line_id' => $order->lines->first()->id, 'quantity' => '2']],
    ]);

    $deliver('2026-09-12')->assertSessionHasErrors([
        'delivered_at' => 'The goods for this challan came into stock on 15/09/2026, after 12/09/2026. Date the challan on or after 15/09/2026, or enter the earlier purchase or opening stock first.',
    ]);
    expect($order->fresh()->lines->first()->quantity_delivered)->toEqual(0);

    // On the day they arrived it goes through, dated that day.
    $deliver('2026-09-15')->assertSessionHasNoErrors();
    expect($order->fresh()->lines->first()->deliveryLines()->first()->delivery->delivered_at->toDateString())->toBe('2026-09-15');
});
