<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
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
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ 24 Oct 2026 (owner): the order form prefilled the customer's saved street
 * with no district, and the save was refused "We do not recognise that
 * district." Now the form prefills a WHOLE place (the customer's last delivery:
 * district, thana, street) or nothing, and an order handed over with no
 * delivery saves with no address at all, whatever the boxes hold.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->business = Business::create(['name' => 'Handover Co', 'slug' => 'handover-co']);
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

it('saves a hand-over order with no district, and keeps no address for it', function () {
    $this->shop->update(['address' => 'House 7, Road 3, Mirpur']);

    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-21',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
        'hand_over' => true,
        // What a prefilled street used to leave in the boxes.
        'address' => ['name' => 'Karim Store', 'phone' => '', 'district' => '', 'thana' => '', 'address_line' => 'House 7, Road 3, Mirpur'],
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::query()->with('address')->sole();
    expect($order->address)->toBeNull();
});

it('still asks for the district of a delivery that is not a hand-over', function () {
    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-21',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
        'address' => ['name' => 'Karim Store', 'phone' => '01711000000', 'district' => '', 'thana' => '', 'address_line' => 'House 7, Road 3, Mirpur'],
    ])->assertSessionHasErrors(['address.district' => 'We do not recognise that district.']);

    expect(SalesOrder::count())->toBe(0);
});

it('offers the last delivery place of the customer whole, district first, and never a street alone', function () {
    $this->shop->update(['address' => 'House 7, Road 3, Mirpur']);
    $other = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Store', 'type' => CustomerType::Retail, 'address' => 'Shop 2, Kawran Bazar']);

    // Karim's first order goes to Dhanmondi.
    $this->actingAs($this->staff)->post(route('sales.orders.store'), [
        'customer_id' => $this->shop->id,
        'ordered_at' => '2026-09-21',
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
        'address' => ['name' => 'Karim Store', 'phone' => '01711000000', 'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'area' => '', 'address_line' => 'House 4, Road 2, Jigatola', 'landmark' => 'Near the mosque'],
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->staff)->get(route('sales.orders.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('customers', function ($customers) use ($other) {
                $byId = collect($customers)->keyBy('id');
                $karim = $byId[$this->shop->id];
                $rahim = $byId[$other->id];

                return $karim['saved_place']['district'] === 'Dhaka'
                    && $karim['saved_place']['thana'] === 'Dhanmondi'
                    && $karim['saved_place']['address_line'] === 'House 4, Road 2, Jigatola'
                    && $karim['saved_place']['landmark'] === 'Near the mosque'
                    // The flat saved address is still there for the hint.
                    && $karim['address'] === 'House 7, Road 3, Mirpur'
                    // No delivery yet: no place, so the form prefills no street.
                    && $rahim['saved_place'] === null
                    && $rahim['address'] === 'Shop 2, Kawran Bazar';
            }));

    // The form takes the street only together with its place, never on its own.
    $form = file_get_contents(resource_path('js/pages/sales/orders/index.tsx'));
    expect($form)->not->toContain("address_line: current.address.address_line || (customer?.address ?? '')")
        ->and($form)->toContain("place.district !== ''");
});
