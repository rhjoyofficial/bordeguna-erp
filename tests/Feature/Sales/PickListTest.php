<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\database\seeders\PriceGroupSeeder;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The pick list: the first piece of warehouse management this system has.
 *
 * ⚠⚠ Its whole value is that it names the SAME batches the ledger will consume.
 * A sheet that sorted stock its own way would send somebody to the wrong shelf,
 * the engine would then take a different box, and the paper and the books would
 * disagree about which goods left the building.
 */
beforeEach(function () {
    $this->business = Business::create([
        'name' => 'Pick Co', 'slug' => 'pick-co', 'legal_name' => 'Pick Company Ltd',
    ]);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Outsider'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new PriceGroupSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAVAR']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);

    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'BNC-DAT-AJW-1KG',
        'name' => 'Ajwa Date 1 kg',
        'type' => ProductType::FinishedGood,
    ]);

    ProductPrice::create([
        'business_id' => $this->business->id,
        'product_id' => $this->product->id,
        'price_group_id' => PriceGroup::where('code', PriceGroup::DEFAULT_CODE)->value('id'),
        'unit_price' => 1200,
        'effective_from' => '2026-01-01',
    ]);

    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'code' => 'CUS-0001', 'name' => 'Karim Traders', 'type' => CustomerType::Retail,
    ]);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');

    Carbon::setTestNow('2026-09-01 10:00:00');

    $this->stockIn = fn (string $qty, string $expiry, string $on) => app(RecordStockIn::class)->handle(
        $this->product, $this->warehouse, $qty, '800', $this->owner,
        occurredAt: $on, expiryDate: $expiry,
    );

    $this->order = fn (string $qty) => app(CreateSalesOrder::class)->handle(
        $this->customer,
        [['product' => $this->product, 'quantity' => $qty]],
        $this->owner,
        $this->warehouse,
        '2026-09-01',
    );

    $this->asOwner = fn () => $this->actingAs($this->owner)
        ->withSession(['current_business_id' => $this->business->id]);
});

afterEach(fn () => Carbon::setTestNow());

it('names the batches in the order the ledger will consume them', function () {
    // ⚠⚠ Received later but expiring sooner: FEFO, not FIFO by arrival. The
    // engine's own scope orders by expiry first, and the sheet must agree.
    ($this->stockIn)('10', '2027-01-31', '2026-08-01 09:00:00');
    ($this->stockIn)('10', '2026-10-31', '2026-08-20 09:00:00');

    $order = ($this->order)('12');

    ($this->asOwner)()->get(route('sales.orders.pick-list', $order))
        ->assertOk()
        ->assertInertia(function ($page) {
            $line = $page->toArray()['props']['lines'][0];

            // ⚠ Compared as floats: JSON hands back 12 for 12.0.
            expect((float) $line['to_pick'])->toBe(12.0)
                ->and((float) $line['on_hand'])->toBe(20.0)
                ->and((float) $line['shortfall'])->toBe(0.0)
                ->and($line['batches'])->toHaveCount(2)
                // The October batch first, all ten of it, then two from January.
                ->and($line['batches'][0]['expiry_date'])->toBe('2026-10-31')
                ->and((float) $line['batches'][0]['take'])->toBe(10.0)
                ->and($line['batches'][1]['expiry_date'])->toBe('2027-01-31')
                ->and((float) $line['batches'][1]['take'])->toBe(2.0);

            return true;
        });
});

it('asks for what is left to pick, not for what was ordered', function () {
    ($this->stockIn)('20', '2027-01-31', '2026-08-01 09:00:00');

    $order = ($this->order)('10');

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '4', 'orderLine' => $order->lines()->first()]],
        createdBy: $this->owner,
    );

    ($this->asOwner)()->get(route('sales.orders.pick-list', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lines.0.ordered', fn ($v) => (float) $v === 10.0)
            ->where('lines.0.delivered', fn ($v) => (float) $v === 4.0)
            ->where('lines.0.to_pick', fn ($v) => (float) $v === 6.0)
            ->where('nothing_to_pick', false)
        );
});

it('says so when the whole order has already gone out', function () {
    ($this->stockIn)('20', '2027-01-31', '2026-08-01 09:00:00');

    $order = ($this->order)('5');

    app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '5', 'orderLine' => $order->lines()->first()]],
        createdBy: $this->owner,
    );

    ($this->asOwner)()->get(route('sales.orders.pick-list', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('nothing_to_pick', true));
});

it('prints the shortfall rather than a sheet somebody cannot fill', function () {
    ($this->stockIn)('3', '2027-01-31', '2026-08-01 09:00:00');

    $order = ($this->order)('8');

    ($this->asOwner)()->get(route('sales.orders.pick-list', $order))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('lines.0.on_hand', fn ($v) => (float) $v === 3.0)
            ->where('lines.0.shortfall', fn ($v) => (float) $v === 5.0)
        );
});

it('never points at expired stock, which the engine would refuse to issue', function () {
    // ⚠ The consumable scope excludes an expired batch by DATE. A pick list
    // that ignored that would send somebody for goods the delivery then rejects.
    ($this->stockIn)('10', '2026-08-31', '2026-08-01 09:00:00');
    ($this->stockIn)('4', '2027-06-30', '2026-08-02 09:00:00');

    $order = ($this->order)('6');

    ($this->asOwner)()->get(route('sales.orders.pick-list', $order))
        ->assertOk()
        ->assertInertia(function ($page) {
            $line = $page->toArray()['props']['lines'][0];

            expect((float) $line['on_hand'])->toBe(4.0)
                ->and((float) $line['shortfall'])->toBe(2.0)
                ->and(collect($line['batches'])->pluck('expiry_date')->all())->toBe(['2027-06-30']);

            return true;
        });

    expect(StockBatch::count())->toBe(2);
});

it('keeps money off the sheet entirely', function () {
    // ⚠⚠ This paper goes to the shelf, not to the customer. A rate on it invites
    // somebody to hand it over with the goods.
    ($this->stockIn)('10', '2027-01-31', '2026-08-01 09:00:00');

    $props = ($this->asOwner)()->get(route('sales.orders.pick-list', ($this->order)('2')))
        ->viewData('page')['props'];

    $serialised = json_encode($props['lines']).json_encode($props['order']);

    expect($serialised)->not->toContain('unit_price')
        ->and($serialised)->not->toContain('1200');
});

it('will not pick an order belonging to another business', function () {
    ($this->stockIn)('10', '2027-01-31', '2026-08-01 09:00:00');
    $order = ($this->order)('2');

    $other = Business::create(['name' => 'Rival', 'slug' => 'rival-pick']);
    DB::table('sales_orders')->where('id', $order->id)->update(['business_id' => $other->id]);

    ($this->asOwner)()->get("/sales/orders/{$order->id}/pick-list")->assertNotFound();
});

it('is shut to somebody who may not read sales', function () {
    ($this->stockIn)('10', '2027-01-31', '2026-08-01 09:00:00');
    $order = ($this->order)('2');

    $outsider = User::factory()->create();
    $outsider->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $outsider->assignRole('Outsider');

    $this->actingAs($outsider)->withSession(['current_business_id' => $this->business->id])
        ->get(route('sales.orders.pick-list', $order))
        ->assertForbidden();
});
