<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The practice run of 17 Oct 2026 at the counter and in the sales pickers.
 *
 * F24: the counter started on the default warehouse and went back to it after
 * every sale, though the jars were in Savar. F25: sales pickers offered bulk
 * ghee, jars, lids and labels. F28: a tile said nothing about stock.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Practice Co', 'slug' => 'practice-co']);
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

    Carbon::setTestNow('2026-10-17 11:00:00');

    $this->office = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Corporate Office', 'code' => 'CO', 'is_default' => true]);
    $this->savar = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar Warehouse', 'code' => 'SAV']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pcs']);

    $make = fn (string $sku, string $name, ProductType $type) => Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => $sku, 'name' => $name, 'type' => $type, 'is_active' => true,
    ]);

    $this->jar = $make('LLD-GHE-GHE-500G', 'LaLaDia Pure Ghee 500g', ProductType::FinishedGood);
    $this->glass = $make('PK-JAR-500ML', 'Glass jar 500 ml', ProductType::RawMaterial);
    $this->bulk = $make('RM-GHE', 'Ghee (bulk)', ProductType::RawMaterial);

    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->jar->id, 'unit_price' => '950', 'effective_from' => '2026-01-01']);

    $stocker = User::factory()->create();
    app(RecordStockIn::class)->handle($this->jar, $this->savar, '18', '762', $stocker, occurredAt: '2026-10-17 09:00:00');
    app(RecordStockIn::class)->handle($this->glass, $this->savar, '20', '35', $stocker, occurredAt: '2026-10-17 09:00:00');

    $this->bank = MoneyAccount::create(['business_id' => $this->business->id, 'name' => 'BRAC Bank', 'type' => 'bank', 'account_id' => Account::bySlug('cash_at_bank')->id]);

    $this->cashier = User::factory()->create();
    $this->cashier->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->cashier->assignRole('Owner');

    $this->props = function (TestResponse $response): array {
        $response->assertOk();

        return $response->viewData('page')['props'];
    };
});

afterEach(fn () => Carbon::setTestNow());

it('F25: offers no raw material or packaging in the counter, order or quotation pickers', function () {
    $counter = ($this->props)($this->actingAs($this->cashier)->get(route('sales.counter.create')));
    $orders = ($this->props)($this->actingAs($this->cashier)->get(route('sales.orders.index')));
    $quotes = ($this->props)($this->actingAs($this->cashier)->get(route('sales.quotations.index')));

    foreach ([$counter, $orders, $quotes] as $props) {
        $ids = collect($props['products'])->pluck('id')->all();
        expect($ids)->toContain($this->jar->id)
            ->and($ids)->not->toContain($this->glass->id)
            ->and($ids)->not->toContain($this->bulk->id);
    }
});

it('F25: still offers a raw material somebody gave a selling price on purpose', function () {
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->bulk->id, 'unit_price' => '1500', 'effective_from' => '2026-01-01']);

    $orders = ($this->props)($this->actingAs($this->cashier)->get(route('sales.orders.index')));

    expect(collect($orders['products'])->pluck('id')->all())->toContain($this->bulk->id);
});

it('F28: tells each tile what is on hand in each warehouse', function () {
    $props = ($this->props)($this->actingAs($this->cashier)->get(route('sales.counter.create')));

    expect($props['onHand'][$this->savar->id][$this->jar->id])->toBe('18')
        ->and($props['onHand'][$this->office->id] ?? [])->toBe([]);
});

it('F24: starts the counter where this cashier last sold from, and keeps it after the sale', function () {
    $first = ($this->props)($this->actingAs($this->cashier)->get(route('sales.counter.create')));
    expect($first['preferredWarehouseId'])->toBeNull();

    $this->actingAs($this->cashier)->post('/sales/counter', [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->savar->id,
        'money_account_id' => $this->bank->id,
        'lines' => [['product_id' => $this->jar->id, 'quantity' => '2']],
    ])->assertRedirect(route('sales.counter.create'))->assertSessionHasNoErrors();

    $after = ($this->props)($this->actingAs($this->cashier)->get(route('sales.counter.create')));
    expect($after['preferredWarehouseId'])->toBe($this->savar->id)
        ->and($after['onHand'][$this->savar->id][$this->jar->id])->toBe('16');

    // Another cashier is not moved by it.
    $other = User::factory()->create();
    $other->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $other->assignRole('Owner');

    expect(($this->props)($this->actingAs($other)->get(route('sales.counter.create')))['preferredWarehouseId'])->toBeNull();

    // A remembered warehouse that was switched off is not offered.
    $this->savar->update(['is_active' => false]);
    expect(($this->props)($this->actingAs($this->cashier)->get(route('sales.counter.create')))['preferredWarehouseId'])->toBeNull();
});

it('F24: offers the sales order form the warehouse this person last took an order for', function () {
    $first = ($this->props)($this->actingAs($this->cashier)->get(route('sales.orders.index')));
    expect($first['last_warehouse_id'])->toBeNull();

    $this->actingAs($this->cashier)->post(route('sales.orders.store'), [
        'customer_id' => Customer::walkIn()->id,
        'warehouse_id' => $this->savar->id,
        'lines' => [['product_id' => $this->jar->id, 'quantity' => '1']],
    ])->assertSessionHasNoErrors();

    $after = ($this->props)($this->actingAs($this->cashier)->get(route('sales.orders.index')));
    expect($after['last_warehouse_id'])->toBe($this->savar->id);
});

it('carries a line note and the special instructions to the order, pick list, challan and invoice', function () {
    $dealer = Customer::create(['business_id' => $this->business->id, 'name' => 'Practice Dealer Store', 'type' => CustomerType::Retail, 'credit_limit' => '20000']);

    $this->actingAs($this->cashier)->post(route('sales.orders.store'), [
        'customer_id' => $dealer->id,
        'warehouse_id' => $this->savar->id,
        'lines' => [['product_id' => $this->jar->id, 'quantity' => '5', 'description' => 'extra sealed']],
        'special_instructions' => 'Deliver after 5 pm',
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::query()->with('lines')->sole();
    expect($order->special_instructions)->toBe('Deliver after 5 pm')
        ->and($order->lines->first()->description)->toBe('extra sealed');

    $show = ($this->props)($this->actingAs($this->cashier)->get(route('sales.orders.show', $order)));
    expect($show['order']['special_instructions'])->toBe('Deliver after 5 pm')
        ->and($show['order']['lines'][0]['description'])->toBe('extra sealed');

    $pick = ($this->props)($this->actingAs($this->cashier)->get(route('sales.orders.pick-list', $order)));
    expect($pick['order']['special_instructions'])->toBe('Deliver after 5 pm')
        ->and($pick['lines'][0]['description'])->toBe('extra sealed');

    $delivery = app(RecordDelivery::class)->handle(
        customer: $dealer,
        warehouse: $this->savar,
        lines: [['product' => $this->jar, 'quantity' => '5', 'orderLine' => $order->lines->first()]],
        createdBy: $this->cashier,
    );

    $challan = ($this->props)($this->actingAs($this->cashier)->get(route('sales.deliveries.print', $delivery)));
    $challanLine = json_decode(json_encode($challan['delivery']), true)['lines'][0];
    expect($challanLine['order_line']['description'])->toBe('extra sealed')
        ->and($challanLine['order_line']['order']['special_instructions'])->toBe('Deliver after 5 pm');

    $this->actingAs($this->cashier)->post('/sales/invoices', [
        'customer_id' => $dealer->id,
        'lines' => [['delivery_line_id' => $delivery->lines->first()->id, 'quantity' => '5']],
    ])->assertSessionHasNoErrors();

    expect(SalesInvoiceLine::query()->whereNotNull('delivery_line_id')->sole()->description)->toBe('extra sealed');
});

it('F21: tells a cost reader the last cost beside a price, and nobody else', function () {
    $dealer = Customer::create(['business_id' => $this->business->id, 'name' => 'Dealer', 'type' => CustomerType::Retail]);
    $query = ['product_id' => $this->jar->id, 'quantity' => '1', 'customer_id' => $dealer->id];

    $this->actingAs($this->cashier)->getJson(route('sales.orders.price', $query))
        ->assertOk()->assertJson(['unit_price' => '950.0000', 'last_cost' => '762.00']);

    $this->actingAs($this->cashier)->getJson(route('sales.group-prices.show', ['product_id' => $this->jar->id]))
        ->assertOk()->assertJson(['last_cost' => '762.00']);

    $staff = User::factory()->create();
    $staff->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $staff->assignRole('Staff');

    $this->actingAs($staff)->getJson(route('sales.orders.price', $query))
        ->assertOk()->assertJson(['unit_price' => '950.0000', 'last_cost' => null]);
});
