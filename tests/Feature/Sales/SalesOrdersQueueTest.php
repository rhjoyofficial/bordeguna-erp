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
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The order book's queues and "Courier says" (Sales redesign, 15 Oct 2026):
 * chips whose counts are the rows a click brings, and the courier's own
 * last word beside each order, read from the parcel already on its challan,
 * at no extra query per row.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-15 10:00:00');
    $this->withoutVite();

    $this->business = Business::create(['name' => 'Queue Co', 'slug' => 'queue-co']);
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

    $this->user = function (string $role): User {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);

        return $user;
    };

    $this->owner = ($this->user)('Owner');
    $this->staff = ($this->user)('Staff');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '500', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');

    $this->courier = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Pathao', 'type' => MoneyAccountType::Courier,
        'provider' => 'Pathao', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);

    $this->order = fn (User $by, string $quantity = '2'): SalesOrder => app(CreateSalesOrder::class)->handle(
        customer: $this->customer,
        lines: [['product' => $this->product, 'quantity' => $quantity]],
        createdBy: $by,
    );

    $this->deliver = fn (SalesOrder $order, string $quantity): Delivery => app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => $quantity, 'orderLine' => $order->lines->first()]],
        createdBy: $this->owner,
        deliveredAt: '2026-10-15 09:00:00',
    );

    $this->book = function (Delivery $delivery, ?CourierStatus $status = null, ?string $at = null): Consignment {
        $this->actingAs($this->owner)
            ->post("/sales/deliveries/{$delivery->id}/consignment", [
                'money_account_id' => $this->courier->id,
                'consignment_number' => 'C-'.$delivery->id,
                'cod_amount' => '1700',
            ])->assertSessionHasNoErrors();

        $parcel = Consignment::query()->where('delivery_id', $delivery->id)->sole();

        if ($status !== null) {
            $parcel->forceFill(['courier_status' => $status, 'courier_status_at' => $at ?? now()])->save();
        }

        return $parcel;
    };

    // The book as the fixture fills it: one order per queue.
    $this->fill = function (): array {
        $orders = [];
        $orders['approval'] = ($this->order)($this->staff);
        $orders['dispatch'] = ($this->order)($this->owner);
        $orders['moving'] = ($this->order)($this->owner);
        ($this->book)(($this->deliver)($orders['moving'], '2'), CourierStatus::InTransit, '2026-10-15 09:20:00');
        $orders['problem'] = ($this->order)($this->owner);
        ($this->book)(($this->deliver)($orders['problem'], '2'), CourierStatus::DeliveryFailed);
        $orders['delivered'] = ($this->order)($this->owner);
        $parcel = ($this->book)(($this->deliver)($orders['delivered'], '2'), CourierStatus::Delivered);
        $this->actingAs($this->owner)->post("/sales/consignments/{$parcel->id}/outcome", [
            'outcome' => 'delivered', 'outcome_on' => '2026-10-15', 'collected_amount' => '1700', 'lines' => [],
        ])->assertSessionHasNoErrors();

        return $orders;
    };
});

afterEach(fn () => Carbon::setTestNow());

it('counts every queue on its chip and shows exactly those orders when the chip is pressed', function () {
    $orders = ($this->fill)();

    $this->actingAs($this->owner)->get('/sales/orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queues.all', 5)
            ->where('queues.approval', 1)
            ->where('queues.dispatch', 1)
            ->where('queues.moving', 1)
            ->where('queues.problem', 1)
            ->where('queues.delivered', 1)
            ->where('filters.queue', ''));

    foreach ($orders as $queue => $order) {
        $this->actingAs($this->owner)->get('/sales/orders?queue='.$queue)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $order->id)
                ->where('filters.queue', $queue));
    }

    // A word that is not a queue reads as all orders.
    $this->actingAs($this->owner)->get('/sales/orders?queue=nonsense')
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 5)->where('filters.queue', ''));
});

it('keeps the search and the status filter working beside the queue', function () {
    $orders = ($this->fill)();
    $orders['problem']->forceFill(['shop_reference' => 'BNC-20261015-ZZ'])->save();

    $this->actingAs($this->owner)->get('/sales/orders?search=ZZ')
        ->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $orders['problem']->id)
            // The chips count within the search: one order matches, and it is the problem one.
            ->where('queues.all', 1)
            ->where('queues.problem', 1)
            ->where('queues.moving', 0));

    // The moving order is fully challaned (its own status is "delivered":
    // the goods left), so the status filter and the queue narrow together.
    $this->actingAs($this->owner)->get('/sales/orders?status=open&queue=moving')
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    $this->actingAs($this->owner)->get('/sales/orders?status=delivered&queue=moving')
        ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.id', $orders['moving']->id));
});

it('says what the courier says beside each order, from the parcel on its challan', function () {
    $orders = ($this->fill)();
    $byHand = ($this->order)($this->owner);
    ($this->book)(($this->deliver)($byHand, '2'));

    $rows = collect($this->actingAs($this->owner)->get('/sales/orders')->original->getData()['page']['props']['orders']['data'])
        ->keyBy('id');

    expect($rows[$orders['approval']->id]['courier_says'])->toBeNull()
        ->and($rows[$orders['moving']->id]['courier_says']['label'])->toBe('In transit')
        ->and($rows[$orders['moving']->id]['courier_says']['tone'])->toBe('info')
        ->and($rows[$orders['moving']->id]['courier_says']['note'])->toBe('Pathao · heard 40 minutes ago')
        ->and($rows[$orders['problem']->id]['courier_says']['label'])->toBe('Delivery failed')
        ->and($rows[$orders['problem']->id]['courier_says']['tone'])->toBe('danger')
        // Once the outcome is recorded in the ERP, that is the last word.
        ->and($rows[$orders['delivered']->id]['courier_says']['label'])->toBe('Delivered')
        ->and($rows[$orders['delivered']->id]['courier_says']['tone'])->toBe('ok')
        ->and($rows[$orders['delivered']->id]['courier_says']['note'])->toBe('Pathao · recorded 15 Oct')
        ->and($rows[$byHand->id]['courier_says']['label'])->toBe('Booked by hand')
        ->and($rows[$byHand->id]['courier_says']['tone'])->toBe('muted')
        // The challan lines that found the parcel are not sent to the page.
        ->and($rows[$orders['moving']->id])->not->toHaveKey('lines');
});

it('reads the courier word for a page of orders at no extra query per order', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner)->get('/sales/orders')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    ($this->fill)();
    // The first visit warms the shared caches (approvals, attention); measure after it.
    $queries();
    $few = $queries();

    for ($i = 0; $i < 7; $i++) {
        ($this->book)(($this->deliver)(($this->order)($this->owner), '2'), CourierStatus::PickedUp);
    }

    expect($queries())->toBe($few);
});

it('offers the bulk-booking nudge only to whoever may book, with the count Parcels to book shows', function () {
    $orders = ($this->fill)();
    // Parcels to book wants a delivery address on the order.
    $orders['dispatch']->address()->create([
        'business_id' => $this->business->id, 'recipient_name' => 'Karim', 'recipient_phone' => '01711000000',
        'district' => 'Dhaka', 'thana' => 'Mirpur', 'address_line' => 'House 4',
    ]);

    $this->actingAs($this->owner)->get('/sales/orders')
        ->assertInertia(fn (Assert $page) => $page->where('to_book', 1));

    // Staff may sell but not book with a courier: no nudge.
    $this->actingAs($this->staff)->get('/sales/orders')
        ->assertInertia(fn (Assert $page) => $page->where('to_book', null));
});
