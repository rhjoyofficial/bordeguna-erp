<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\StockReservationOverride;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvailableToSell;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\CancelSalesOrder;
use App\Modules\Sales\Actions\CloseSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\EditSalesOrder;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Exceptions\StockShortfall;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\ReservedStockPass;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ B17 (Joy, 24 Oct 2026, Q4 = A, R81): "If you have 500 bottles in the
 * warehouse, and an SO is placed for 400, the system tells your other
 * salespeople that only 100 are 'Available to Sell'."
 */
beforeEach(function () {
    // The new page is not in the built manifest until the next build.
    $this->withoutVite();
    $this->business = Business::create(['name' => 'Reserve Co', 'slug' => 'reserve-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    foreach (['sales.orders.approve', 'sales.orders.record', 'sales.stock.override', 'sales.sell', 'sales.reports.view'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    Role::firstOrCreate(['name' => 'Owner', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.orders.approve', 'sales.orders.record', 'sales.stock.override', 'sales.sell', 'sales.reports.view']);
    Role::firstOrCreate(['name' => 'Staff', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo(['sales.orders.record', 'sales.sell']);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR', 'is_default' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Bottles', 'abbreviation' => 'btl']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-OIL',
        'name' => 'Mustard Oil 1L',
        'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'type' => CustomerType::Dealer,
        'credit_limit' => '100000000',
    ]);
    $this->other = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Rahim Store',
        'type' => CustomerType::Dealer,
        'credit_limit' => '100000000',
    ]);

    $this->owner = User::factory()->create(['name' => 'Joy']);
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
    $this->staff = User::factory()->create(['name' => 'Sifat']);
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    Carbon::setTestNow('2026-10-25 10:00:00');

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'unit_price' => '300',
        'effective_from' => '2026-01-01',
        'created_by' => $this->owner->id,
    ]);

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '500', '200', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->order = fn (string $qty, ?User $by = null, ?Customer $customer = null) => app(CreateSalesOrder::class)->handle(
        customer: $customer ?? $this->customer,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $by ?? $this->owner,
        warehouse: $this->warehouse,
    );

    $this->available = fn () => AvailableToSell::forProduct($this->product->id)['available'];
});

afterEach(fn () => Carbon::setTestNow());

it('holds 400 of 500 when an order is confirmed, so only 100 is available to sell', function () {
    $order = ($this->order)('400');

    expect($order->approvalRequest->isComplete())->toBeTrue()
        ->and(($this->available)())->toBe('100')
        ->and($this->product->fresh()->sellableStockQuantity())->toBe('100')
        ->and(AvailableToSell::forProduct($this->product->id)['reserved'])->toBe('400')
        ->and(StockReservation::query()->live()->for($order->getMorphClass(), $order->id)->value('stock_batch_id'))->toBeNull();
});

it('holds nothing while a staff order waits, and holds it when approved', function () {
    $order = ($this->order)('400', $this->staff);

    expect($order->approvalRequest->isPending())->toBeTrue()
        ->and(($this->available)())->toBe('500');

    app(ApproveSalesOrder::class)->handle($order, $this->owner);

    expect(($this->available)())->toBe('100');
});

it('refuses to confirm more than is available, with available against wanted', function () {
    ($this->order)('400');
    $second = ($this->order)('150', $this->staff, $this->other);

    expect(fn () => app(ApproveSalesOrder::class)->handle($second, $this->owner))
        ->toThrow(StockShortfall::class, 'only 100 of Mustard Oil 1L is available to sell in Savar; this order wants 150');

    expect($second->fresh()->approvalRequest->isPending())->toBeTrue()
        ->and(($this->available)())->toBe('100');
});

it('lets the Owner confirm a short order with a reason: what is there is held, the rest is a backorder', function () {
    ($this->order)('400');
    $second = ($this->order)('150', $this->staff, $this->other);

    app(ApproveSalesOrder::class)->handle($second, $this->owner, null, 'Dealer waits for the next lot');

    $override = StockReservationOverride::query()->sole();

    expect($second->fresh()->approvalRequest->isComplete())->toBeTrue()
        ->and(($this->available)())->toBe('0')
        ->and((float) StockReservation::query()->live()->for($second->getMorphClass(), $second->id)->sum('quantity'))->toBe(100.0)
        ->and($override->kind)->toBe('backorder')
        ->and((float) $override->quantity)->toBe(50.0)
        ->and($override->reason)->toBe('Dealer waits for the next lot');
});

it('refuses a backorder reason from somebody without sales.stock.override', function () {
    Permission::firstOrCreate(['name' => 'sales.orders.approve', 'guard_name' => 'web']);
    $approver = User::factory()->create();
    $approver->businesses()->attach($this->business->id);
    Role::firstOrCreate(['name' => 'Approver', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo('sales.orders.approve');
    $approver->assignRole('Approver');

    ($this->order)('400');
    $second = ($this->order)('150', $this->staff, $this->other);

    expect(fn () => app(ApproveSalesOrder::class)->handle($second, $approver, null, 'Dealer waits for the next lot'))
        ->toThrow(StockShortfall::class);
});

it('leaves an owner\'s own short order waiting instead of confirming it', function () {
    $order = ($this->order)('600');

    expect($order->approvalRequest->isPending())->toBeTrue()
        ->and(($this->available)())->toBe('500');
});

it('drops the hold by what a challan delivers, and ends it when all is delivered', function () {
    $order = ($this->order)('400');

    app(RecordDelivery::class)->handle($this->customer, $this->warehouse, [[
        'product' => $this->product, 'quantity' => '150', 'orderLine' => $order->lines->first(),
    ]], $this->owner);

    expect(($this->available)())->toBe('100')
        ->and(AvailableToSell::forProduct($this->product->id)['reserved'])->toBe('250');

    app(RecordDelivery::class)->handle($this->customer, $this->warehouse, [[
        'product' => $this->product, 'quantity' => '250', 'orderLine' => $order->lines->first()->fresh(),
    ]], $this->owner);

    expect(($this->available)())->toBe('100')
        ->and(AvailableToSell::forProduct($this->product->id)['reserved'])->toBe('0')
        ->and(StockReservation::query()->live()->count())->toBe(0);
});

it('ends the hold on cancel and on close short', function () {
    $first = ($this->order)('200');
    $second = ($this->order)('200', null, $this->other);

    app(CancelSalesOrder::class)->handle($first);
    expect(($this->available)())->toBe('300');

    app(RecordDelivery::class)->handle($this->other, $this->warehouse, [[
        'product' => $this->product, 'quantity' => '50', 'orderLine' => $second->lines->first(),
    ]], $this->owner);
    app(CloseSalesOrder::class)->handle($second->fresh(), $this->owner, 'Customer took only 50');

    expect(($this->available)())->toBe('450')
        ->and(StockReservation::query()->live()->count())->toBe(0);
});

it('stops a counter sale taking stock held for an order, and lets the Owner through with a reason', function () {
    ($this->order)('400');
    $walkIn = Customer::query()->where('type', CustomerType::WalkIn)->firstOrFail();
    $cash = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash', 'account_id' => Account::bySlug('cash_in_hand')->id]);

    $sell = fn (string $qty, ?ReservedStockPass $pass = null) => app(RecordCounterSale::class)->handle(
        customer: $walkIn, warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $this->staff, moneyAccount: $cash, takeReserved: $pass,
    );

    $sell('100');
    expect(($this->available)())->toBe('0');

    try {
        $sell('5');
        $this->fail('A counter sale took reserved stock.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('reserved')
            ->and($e->errors()['lines'][0])->toContain('Only 0 of Mustard Oil 1L is available to sell in Savar; 400 is reserved for SO-');
    }

    expect(fn () => $sell('5', new ReservedStockPass($this->staff, 'Customer is waiting')))->toThrow(ValidationException::class);

    $sell('5', new ReservedStockPass($this->owner, 'Customer is waiting'));

    expect(StockReservationOverride::query()->where('kind', 'take_reserved')->sole()->authorised_by)->toBe($this->owner->id);
});

it('leaves the shelf-short sentence alone when nothing is reserved', function () {
    $walkIn = Customer::query()->where('type', CustomerType::WalkIn)->firstOrFail();

    expect(fn () => app(RecordDelivery::class)->handle($walkIn, $this->warehouse, [['product' => $this->product, 'quantity' => '600']], $this->owner))
        ->toThrow(InsufficientStockException::class);
});

it('holds the new quantities when a confirmed order is edited', function () {
    $order = ($this->order)('400');
    $line = $order->lines->first();

    app(EditSalesOrder::class)->handle($order, [
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'ordered_at' => $order->ordered_at->toDateString(),
        'lines' => [['id' => $line->id, 'product_id' => $this->product->id, 'quantity' => '250', 'unit_price' => (string) $line->unit_price]],
    ], $this->owner, null, null);

    expect($order->fresh()->approvalRequest->isComplete())->toBeTrue()
        ->and(($this->available)())->toBe('250')
        ->and(StockReservation::query()->for($order->getMorphClass(), $order->id)->whereNotNull('released_at')->count())->toBe(1);
});

it('keeps a staff purchase or owner withdrawal off stock held for an order', function () {
    ($this->order)('400');

    expect(fn () => app(ReserveStock::class)->handle($this->product, $this->warehouse, '150', 'staff_purchase', SalesOrder::query()->first(), $this->owner))
        ->toThrow(InsufficientStockException::class);

    app(ReserveStock::class)->handle($this->product, $this->warehouse, '100', 'staff_purchase', $this->customer, $this->owner);

    expect(($this->available)())->toBe('0');
});

it('lists what each order holds, the backorders, and lets the Owner release a hold with a reason', function () {
    $first = ($this->order)('400');
    $second = ($this->order)('150', $this->staff, $this->other);
    app(ApproveSalesOrder::class)->handle($second, $this->owner, null, 'Dealer waits for the next lot');

    $this->actingAs($this->staff)->get('/sales/reserved-stock')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sales/reserved-stock/index')
        ->where('products.0.on_hand', '500')
        ->where('products.0.reserved', '500')
        ->where('products.0.available', '0')
        ->where('products.0.holds.0.document', $first->order_number)
        ->where('products.0.holds.0.quantity', '400')
        ->where('backorders.0.order', $second->order_number)
        ->where('backorders.0.short', '50')
        ->where('backorders.0.reason', 'Dealer waits for the next lot')
        ->where('can_release', false));

    $hold = StockReservation::query()->live()->for($first->getMorphClass(), $first->id)->sole();

    $this->actingAs($this->staff)->post("/sales/reserved-stock/{$hold->id}/release", ['reason' => 'Customer stopped answering'])->assertForbidden();
    $this->actingAs($this->owner)->post("/sales/reserved-stock/{$hold->id}/release", ['reason' => 'no'])->assertSessionHasErrors('reason');
    $this->actingAs($this->owner)->post("/sales/reserved-stock/{$hold->id}/release", ['reason' => 'Customer stopped answering'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("/sales/reserved-stock/{$hold->id}/release", ['reason' => 'Customer stopped answering'])
        ->assertSessionHasErrors(['reason' => 'That hold was already released.']);

    expect(($this->available)())->toBe('400')
        ->and($hold->fresh()->released_by)->toBe($this->owner->id)
        ->and(StockReservationOverride::query()->where('kind', 'release')->sole()->reason)->toBe('Customer stopped answering');
});

it('confirms a short order from the order page with a backorder reason, and says how short it is first', function () {
    ($this->order)('400');
    $second = ($this->order)('150', $this->staff, $this->other);

    $this->actingAs($this->owner)->get("/sales/orders/{$second->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stock_shortfall', fn (string $s) => str_contains($s, 'only 100 of Mustard Oil 1L is available to sell in Savar; this order wants 150'))
        ->where('can_backorder', true));

    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/approve")->assertSessionHas('error');
    expect($second->fresh()->approvalRequest->isPending())->toBeTrue();

    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/approve", ['backorder_reason' => 'Dealer waits for the next lot'])->assertSessionHas('success');
    expect($second->fresh()->approvalRequest->isComplete())->toBeTrue();
});

it('shows available to sell under each line of the order form', function () {
    ($this->order)('400');

    $this->actingAs($this->owner)->get('/sales/orders')->assertInertia(fn (Assert $page) => $page
        ->where('products.0.available', fn ($available) => collect($available)->get((string) $this->warehouse->id) === '100'
            && collect($available)->get('') === '100'));
});
