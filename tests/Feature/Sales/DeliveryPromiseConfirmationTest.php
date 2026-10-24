<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\ApproveSalesOrder;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * D24, as Joy confirmed it on 13 September: a website order's delivery promise
 * counts from CONFIRMATION, so the expected date is written when the order is
 * approved - confirmation plus the slower end of the window it was promised.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-13 10:00:00');

    $this->business = Business::create(['name' => 'Promise Co', 'slug' => 'promise-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HON-500', 'name' => 'Honey', 'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);
    ProductPrice::create(['product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);

    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Buyer', 'type' => CustomerType::Retail]);
    $this->store = Store::create(['key' => 'bionic', 'name' => 'Bionic', 'order_prefix' => 'BNC']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');

    // ⭐ B17 (25 Oct 2026, Q4 = A): confirming holds stock, so there is some.
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    $warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
    app(RecordStockIn::class)->handle($this->product, $warehouse, '100', '500', $this->owner, occurredAt: '2026-09-01 09:00:00');

    $this->webOrder = fn (?int $min = 24, ?int $max = 72): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => '1']],
        // ⚠ The system actor: a website order is never self-approved, so it
        // arrives pending, exactly as a real one does.
        createdBy: SystemActor::user(),
        origin: new SalesOrderOrigin(channel: SalesChannel::Website, storeId: $this->store->id, promiseMinHours: $min, promiseMaxHours: $max),
    );
});

afterEach(fn () => Carbon::setTestNow());

it('writes the expected date when the order is confirmed, not when it is placed', function () {
    $order = ($this->webOrder)();

    expect($order->expected_date)->toBeNull();

    // Confirmed two days after it was placed: the promise counts from HERE.
    Carbon::setTestNow('2026-09-15 16:00:00');

    app(ApproveSalesOrder::class)->handle($order, $this->owner);

    // 15 Sep 16:00 plus 72 hours is 18 Sep.
    expect($order->fresh()->expected_date->toDateString())->toBe('2026-09-18');
});

it('writes no date for an order that was promised nothing', function () {
    $order = ($this->webOrder)(null, null);

    app(ApproveSalesOrder::class)->handle($order, $this->owner);

    expect($order->fresh()->expected_date)->toBeNull();
});
