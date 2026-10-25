<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * IP21 (25 Oct 2026): changing a courier's fixed zone charge on an order is
 * the permission sales.delivery_charge.override (Owner and Manager by
 * migration and seeder), no longer the role names Owner or Manager.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::create(['name' => 'Channel Co', 'slug' => 'channel-co']);
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

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR', 'is_default' => true]);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Bottles', 'abbreviation' => 'btl']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-OIL', 'name' => 'Mustard Oil 1L', 'type' => ProductType::FinishedGood,
    ]);
    $this->gift = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-PICKLE', 'name' => 'Pickle sample', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Karim Traders', 'type' => CustomerType::Dealer, 'credit_limit' => '100000000']);
    $this->other = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Store', 'type' => CustomerType::Dealer, 'credit_limit' => '100000000']);

    $this->owner = User::factory()->create(['name' => 'Joy']);
    $this->owner->businesses()->attach($this->business->id);
    $this->owner->assignRole('Owner');
    $this->manager = User::factory()->create(['name' => 'Mitu']);
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');
    $this->staff = User::factory()->create(['name' => 'Sifat']);
    $this->staff->businesses()->attach($this->business->id);
    $this->staff->assignRole('Staff');

    Carbon::setTestNow('2026-10-25 10:00:00');

    foreach ([$this->product, $this->gift] as $product) {
        ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'unit_price' => '300', 'effective_from' => '2026-01-01', 'created_by' => $this->owner->id]);
    }

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '500', '200', $this->owner, occurredAt: '2026-10-01 09:00:00');
    app(RecordStockIn::class)->handle($this->gift, $this->warehouse, '50', '40', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->order = fn (string $qty, ?User $by = null, ?Customer $customer = null) => app(CreateSalesOrder::class)->handle(
        customer: $customer ?? $this->customer,
        lines: [['product' => $this->product, 'quantity' => $qty]],
        createdBy: $by ?? $this->owner,
        warehouse: $this->warehouse,
    );

    $this->address = ['name' => 'Karim', 'phone' => '01711000000', 'district' => 'Dhaka', 'thana' => 'Mirpur', 'address_line' => 'House 7, Road 3'];

    // A typed order, as the form sends it.
    $this->post = fn (array $extra = [], ?User $by = null) => $this->actingAs($by ?? $this->owner)->post('/sales/orders', [
        'customer_id' => $this->customer->id,
        'warehouse_id' => $this->warehouse->id,
        'ordered_at' => '2026-10-25',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '10']],
        ...$extra,
    ]);
    $this->latest = fn () => SalesOrder::query()->latest('id')->first();
});

afterEach(fn () => Carbon::setTestNow());

it('lets whoever holds sales.delivery_charge.override change the zone charge, whatever the role is called', function () {
    // Staff do not hold it: the zone charge stays fixed for them.
    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier', 'delivery_charge' => '40', 'address' => $this->address], $this->staff)
        ->assertSessionHasErrors('delivery_charge');

    // Given to the Staff role in Settings > Roles, it works for them.
    Role::findByName('Staff', 'web')->givePermissionTo('sales.delivery_charge.override');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->staff->refresh();

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier', 'delivery_charge' => '40', 'address' => $this->address], $this->staff)->assertSessionHasNoErrors();
    expect((string) ($this->latest)()->delivery_charge)->toBe('40.00')
        ->and(($this->latest)()->delivery_charge_set_by)->toBe($this->staff->id);

    // Taken from the Manager role, the role NAME no longer lets them through.
    Role::findByName('Manager', 'web')->revokePermissionTo('sales.delivery_charge.override');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->manager->refresh();

    ($this->post)(['channel' => 'phone', 'delivery_method' => 'courier', 'delivery_charge' => '40', 'address' => $this->address], $this->manager)
        ->assertSessionHasErrors('delivery_charge');

    $this->actingAs($this->manager)->get('/sales/orders')->assertInertia(fn (Assert $page) => $page->where('order_options.may_change_courier_charge', false));
    $this->actingAs($this->owner)->get('/sales/orders')->assertInertia(fn (Assert $page) => $page->where('order_options.may_change_courier_charge', true));
});

it('grants sales.delivery_charge.override to the Owner and Manager roles that already exist, by migration', function () {
    $permission = DB::table('permissions')->where('name', 'sales.delivery_charge.override')->value('id');
    DB::table('role_has_permissions')->where('permission_id', $permission)->delete();
    DB::table('permissions')->where('id', $permission)->delete();

    $migration = require base_path('app/Modules/Sales/database/migrations/2026_10_26_410000_delivery_charge_override_permission_is_granted_to_existing_roles.php');
    $migration->up();

    $granted = DB::table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('permissions.name', 'sales.delivery_charge.override')
        ->where('roles.business_id', $this->business->id)
        ->pluck('roles.name')->sort()->values()->all();

    expect($granted)->toBe(['Manager', 'Owner']);

    $migration->up();
    expect(DB::table('permissions')->where('name', 'sales.delivery_charge.override')->count())->toBe(1);
});
