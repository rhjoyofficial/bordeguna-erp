<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockReservationOverride;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvailableToSell;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ B18 (Joy's initial plan R91 to R96, 25 Oct 2026, Q2 = B): website, phone,
 * WhatsApp, Facebook and walk-in orders typed with a channel, confirmed by a
 * call, with gifts, an order discount, a delivery method and a payment way.
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

it('lets the order challan take stock held for another order only with an Owner reason', function () {
    ($this->order)('400');
    $second = ($this->order)('150', $this->staff, $this->other);
    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/approve", ['backorder_reason' => 'Dealer waits for the next lot'])->assertSessionHas('success');
    $line = $second->fresh('lines')->lines->first();
    $challan = fn (array $extra = []) => ['warehouse_id' => $this->warehouse->id, 'delivered_at' => '2026-10-25', 'lines' => [['sales_order_line_id' => $line->id, 'quantity' => '150']], ...$extra];

    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/deliver", $challan())->assertSessionHasErrors(['reserved', 'lines']);
    $this->actingAs($this->staff)->post("/sales/orders/{$second->id}/deliver", $challan(['take_reserved_reason' => 'Customer is waiting']))->assertSessionHasErrors('reserved');

    $this->actingAs($this->owner)->post("/sales/orders/{$second->id}/deliver", $challan(['take_reserved_reason' => 'Customer is waiting']))->assertSessionHasNoErrors();

    expect(StockReservationOverride::query()->where('kind', 'take_reserved')->sole()->reason)->toBe('Customer is waiting');
});
