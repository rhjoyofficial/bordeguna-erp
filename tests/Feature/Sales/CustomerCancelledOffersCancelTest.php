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
use App\Modules\Sales\Enums\OrderConfirmation;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * IP21 (25 Oct 2026): after the confirmation call records "Customer
 * cancelled", the order page offers its own Cancel right in the call panel,
 * under the same permission and stage rules. Recording the call never cancels.
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

it('offers the order Cancel right after a Customer cancelled call, by the same rules, and never cancels by itself', function () {
    ($this->post)(['channel' => 'whatsapp'], $this->staff)->assertSessionHasNoErrors();
    $order = ($this->latest)();

    $this->actingAs($this->staff)
        ->post("/sales/orders/{$order->id}/confirmation", ['outcome' => 'customer_cancelled', 'tries_seen' => 0, 'note' => 'Bought elsewhere'])
        ->assertSessionHasNoErrors();

    // Recorded, and nothing more: the order still stands.
    expect($order->fresh()->confirmation_status)->toBe(OrderConfirmation::CustomerCancelled)
        ->and($order->fresh()->status)->toBe(SalesOrderStatus::Open);

    // Owner and Manager may cancel: the page carries the Cancel row the panel offers.
    foreach ([$this->owner, $this->manager] as $user) {
        $this->actingAs($user)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
            ->where('channel_info.confirmation', 'customer_cancelled')
            ->where('order_actions', fn ($actions) => collect($actions)->contains(fn ($a) => $a['action'] === 'cancel' && $a['allowed'] === true)));
    }

    // Staff took the call but may not cancel: no Cancel row, and the server refuses.
    $this->actingAs($this->staff)->get("/sales/orders/{$order->id}")->assertInertia(fn (Assert $page) => $page
        ->where('order_actions', fn ($actions) => ! collect($actions)->contains(fn ($a) => $a['action'] === 'cancel')));
    $this->actingAs($this->staff)->post("/sales/orders/{$order->id}/cancel", ['reason' => 'Customer called to cancel'])->assertForbidden();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Open);

    // The Manager presses it, with a reason.
    $this->actingAs($this->manager)->post("/sales/orders/{$order->id}/cancel", ['reason' => 'Customer called to cancel'])->assertRedirect();

    expect($order->fresh()->status)->toBe(SalesOrderStatus::Cancelled);
});
