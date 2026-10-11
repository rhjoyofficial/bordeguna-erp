<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\ParcelWeight;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier hub slice 3 (Joy, 11 Oct 2026): the parcel weight is Σ quantity x the
 * product's weight, worked out by the ERP, and directly editable - NO reason
 * asked - by whoever takes the order or hands it to a courier. The worked-out
 * value is kept beside the typed one, with who typed it, for the fee analysis.
 *
 * ⚠ A missing product weight is never zero (ruling 10): the known part is
 * reported, the items without weight are named, and the auto weight a record
 * keeps stays null until every line has one.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Weigh Co', 'slug' => 'weigh-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->ghee = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'GHEE-400', 'name' => 'Ghee 400g', 'type' => ProductType::FinishedGood, 'weight_grams' => 450,
    ]);
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HONEY-1KG', 'name' => 'Honey 1 kg', 'type' => ProductType::FinishedGood, 'weight_grams' => 1180,
    ]);
    // A liquid nobody has weighed yet: no weight, and never counted as zero.
    $this->oil = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'OIL-5L', 'name' => 'Mustard oil 5 L', 'type' => ProductType::FinishedGood,
    ]);

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Parcel buyer', 'type' => CustomerType::Retail]);

    foreach ([$this->ghee, $this->honey, $this->oil] as $product) {
        ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'unit_price' => '500', 'effective_from' => '2026-01-01']);
    }

    Carbon::setTestNow('2026-10-11 10:00:00');

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    $this->staff = User::factory()->create();
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    foreach ([$this->ghee, $this->honey, $this->oil] as $product) {
        app(RecordStockIn::class)->handle($product, $this->warehouse, '50', '200', $this->owner, occurredAt: '2026-10-01 09:00:00');
    }

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    $this->order = fn (array $lines, ?int $weight = null): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: array_map(fn ($line) => ['product' => $line[0], 'quantity' => $line[1]], $lines),
        createdBy: $this->owner,
        weightGrams: $weight,
    );

    $this->deliver = fn (SalesOrder $order, array $quantities): Delivery => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: $order->lines->map(fn ($line, $i) => ['product' => $line->product, 'quantity' => $quantities[$i], 'orderLine' => $line])
            ->filter(fn ($line) => (float) $line['quantity'] > 0)->values()->all(),
        createdBy: $this->owner,
        deliveredAt: '2026-10-11 09:30:00',
    );

    $this->book = fn (Delivery $delivery, array $extra = []) => $this->actingAs($this->staff)
        ->post("/sales/deliveries/{$delivery->id}/consignment", [
            'money_account_id' => $this->courier->id,
            'consignment_number' => 'PTH-'.$delivery->id,
            'cod_amount' => '0',
            ...$extra,
        ]);

    $this->page = fn (SalesOrder $order) => $this->actingAs($this->owner)
        ->get('/sales/orders/'.$order->id)
        ->original->getData()['page']['props'];
});

afterEach(fn () => Carbon::setTestNow());

// ── The one calculation ─────────────────────────────────────────────────────

it('adds up quantity times product weight for an order', function () {
    $order = ($this->order)([[$this->ghee, '2'], [$this->honey, '1']]);

    $weight = ParcelWeight::forOrder($order);

    expect($weight->knownGrams)->toBe(2 * 450 + 1180)
        ->and($weight->isComplete())->toBeTrue()
        ->and($weight->autoGrams())->toBe(2080);
});

it('names the items without weight and never counts them as zero', function () {
    $order = ($this->order)([[$this->ghee, '2'], [$this->oil, '1']]);

    $weight = ParcelWeight::forOrder($order);

    expect($weight->knownGrams)->toBe(900)
        ->and($weight->isComplete())->toBeFalse()
        // ⚠ The auto weight a record keeps is null, not 900: 900 g would be a
        // parcel under-declared by a 5 L jar.
        ->and($weight->autoGrams())->toBeNull()
        ->and($weight->toArray()['missing_count'])->toBe(1)
        ->and($weight->missing[0]['sku'])->toBe('OIL-5L');
});

// ── The order ───────────────────────────────────────────────────────────────

it('stores the weight from the lines when the order is taken', function () {
    $order = ($this->order)([[$this->ghee, '3']]);

    expect($order->fresh()->weight_grams_auto)->toBe(1350)
        ->and($order->fresh()->weight_grams)->toBeNull()
        ->and($order->fresh()->effectiveWeightGrams())->toBe(1350)
        ->and($order->fresh()->weight_edited_by)->toBeNull();
});

it('keeps no auto weight for an order with an item that has no weight', function () {
    $order = ($this->order)([[$this->ghee, '1'], [$this->oil, '1']]);

    expect($order->fresh()->weight_grams_auto)->toBeNull()
        ->and($order->fresh()->effectiveWeightGrams())->toBeNull();
});

it('saves a typed weight with no reason asked, keeping the auto weight and who typed it', function () {
    $this->actingAs($this->owner)->post(route('sales.orders.store'), [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->ghee->id, 'quantity' => '2']],
        'weight_grams' => 1100,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $order = SalesOrder::query()->latest('id')->firstOrFail();

    expect($order->weight_grams)->toBe(1100)
        ->and($order->weight_grams_auto)->toBe(900)
        ->and($order->effectiveWeightGrams())->toBe(1100)
        ->and($order->weight_edited_by)->toBe($this->owner->id)
        ->and($order->weight_edited_at?->toDateTimeString())->toBe('2026-10-11 10:00:00');
});

it('treats a typed weight equal to the auto weight as no edit', function () {
    $order = ($this->order)([[$this->ghee, '2']], 900);

    expect($order->fresh()->weight_grams)->toBeNull()
        ->and($order->fresh()->weight_edited_by)->toBeNull();
});

it('takes a typed weight for an order whose items have no weight', function () {
    $order = ($this->order)([[$this->oil, '1']], 5200);

    expect($order->fresh()->weight_grams_auto)->toBeNull()
        ->and($order->fresh()->weight_grams)->toBe(5200)
        ->and($order->fresh()->effectiveWeightGrams())->toBe(5200);
});

it('refuses a weight that is not whole grams', function () {
    $this->actingAs($this->owner)->post(route('sales.orders.store'), [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $this->ghee->id, 'quantity' => '1']],
        'weight_grams' => '450.5',
    ])->assertSessionHasErrors('weight_grams');

    expect(SalesOrder::count())->toBe(0);
});

it('offers each product weight to the order form so it can add up live', function () {
    $products = $this->actingAs($this->owner)->get('/sales/orders')->original->getData()['page']['props']['products'];

    $byId = collect($products)->keyBy('id');

    expect($byId[$this->ghee->id]['weight_grams'])->toBe(450)
        ->and($byId[$this->oil->id]['weight_grams'])->toBeNull();
});

it('shows the order weight and the items still without weight on the order page', function () {
    $order = ($this->order)([[$this->ghee, '1'], [$this->oil, '2']], 5600);

    $weight = ($this->page)($order)['weight'];

    expect($weight['typed_grams'])->toBe(5600)
        ->and($weight['auto_grams'])->toBeNull()
        ->and($weight['effective_grams'])->toBe(5600)
        ->and($weight['now']['known_grams'])->toBe(450)
        ->and($weight['now']['missing_count'])->toBe(1);
});

// ── The parcel ──────────────────────────────────────────────────────────────

it('weighs a part shipment by what the challan carries, not by the whole order', function () {
    $order = ($this->order)([[$this->ghee, '3'], [$this->honey, '2']], 4000);
    $delivery = ($this->deliver)($order, ['1', '1']);

    expect(ParcelWeight::forDelivery($delivery)->autoGrams())->toBe(450 + 1180)
        // ⚠ The order's typed 4,000 g is for the whole order, not this part.
        ->and(ParcelWeight::suggestedForDelivery($delivery))->toBe(1630);

    $challan = ($this->page)($order)['challans'][0];

    expect($challan['weight']['auto_grams'])->toBe(1630)
        ->and($challan['weight_suggested'])->toBe(1630);
});

it('offers the order typed weight when the challan carries the whole order', function () {
    $order = ($this->order)([[$this->ghee, '2'], [$this->honey, '1']], 2300);
    $delivery = ($this->deliver)($order, ['2', '1']);

    expect(ParcelWeight::suggestedForDelivery($delivery))->toBe(2300)
        ->and(ParcelWeight::forDelivery($delivery)->autoGrams())->toBe(2080);
});

it('declares the offered weight when the parcel is booked with the box left as it was', function () {
    $order = ($this->order)([[$this->ghee, '2']]);
    $delivery = ($this->deliver)($order, ['2']);

    ($this->book)($delivery)->assertSessionHasNoErrors();

    $consignment = Consignment::query()->where('delivery_id', $delivery->id)->sole();

    expect($consignment->declared_weight_auto_grams)->toBe(900)
        ->and($consignment->declared_weight_grams)->toBe(900)
        ->and($consignment->created_by)->toBe($this->staff->id);
});

it('saves a weight typed at booking with no reason, and keeps the challan auto weight beside it', function () {
    $order = ($this->order)([[$this->ghee, '3']]);
    $delivery = ($this->deliver)($order, ['2']);

    ($this->book)($delivery, ['declared_weight_grams' => 1000])->assertSessionHasNoErrors();

    $consignment = Consignment::query()->where('delivery_id', $delivery->id)->sole();

    expect($consignment->declared_weight_grams)->toBe(1000)
        // Worked out by the server from the 2 jars on this challan, never sent by the form.
        ->and($consignment->declared_weight_auto_grams)->toBe(900);

    expect(($this->page)($order)['challans'][0]['consignment']['declared_weight_grams'])->toBe(1000);
});

it('books a parcel with an unweighed item: no auto weight, the typed weight declared', function () {
    $order = ($this->order)([[$this->oil, '1']]);
    $delivery = ($this->deliver)($order, ['1']);

    $challan = ($this->page)($order)['challans'][0];
    expect($challan['weight']['missing_count'])->toBe(1)
        ->and($challan['weight_suggested'])->toBeNull();

    ($this->book)($delivery, ['declared_weight_grams' => 5150])->assertSessionHasNoErrors();

    $consignment = Consignment::query()->where('delivery_id', $delivery->id)->sole();

    expect($consignment->declared_weight_auto_grams)->toBeNull()
        ->and($consignment->declared_weight_grams)->toBe(5150);
});

it('refuses a booking weight below one gram', function () {
    $order = ($this->order)([[$this->ghee, '1']]);
    $delivery = ($this->deliver)($order, ['1']);

    ($this->book)($delivery, ['declared_weight_grams' => 0])->assertSessionHasErrors('declared_weight_grams');

    expect(Consignment::count())->toBe(0);
});

// ── Business isolation ──────────────────────────────────────────────────────

it('never reads another business\'s product weight into an order weight', function () {
    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-weigh-co']);
    $otherUnit = UnitOfMeasure::withoutGlobalScopes()->create(['business_id' => $other->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $foreign = Product::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'unit_of_measure_id' => $otherUnit->id,
        'sku' => 'GHEE-400', 'name' => 'Their ghee', 'type' => ProductType::FinishedGood, 'weight_grams' => 9999,
    ]);

    $this->actingAs($this->owner)->post(route('sales.orders.store'), [
        'customer_id' => $this->customer->id,
        'lines' => [['product_id' => $foreign->id, 'quantity' => '1']],
    ]);

    // The other business's product is not reachable from this one at all.
    expect(SalesOrder::count())->toBe(0);

    $order = ($this->order)([[$this->ghee, '1']]);
    expect($order->fresh()->weight_grams_auto)->toBe(450);
});
