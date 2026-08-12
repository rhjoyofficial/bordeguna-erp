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
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesReturn;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The returns screens and their permission layer: Staff RECORD returns (goods
 * arrive when they arrive, C-SR-9), cash going back is Owner/Manager only
 * (C-SR-6), and everything ships reachable - route, screen, sidebar link - in
 * the same commit as the engine.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Return Screen Co', 'slug' => 'return-screen-co']);
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
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY',
        'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood,
    ]);
    $this->customer = Customer::create([
        'business_id' => $this->business->id,
        'name' => 'Karim Traders',
        'type' => CustomerType::Distributor,
    ]);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id,
        'name' => 'Cash in Hand',
        'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);

    Carbon::setTestNow('2026-08-12 10:00:00');

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        setPermissionsTeamId($this->business->id);
        $user->assignRole($role);
        $this->currentUser = $user;

        return $this->actingAs($user);
    };

    app(RecordStockIn::class)->handle(
        $this->product, $this->warehouse, '100', '500',
        User::factory()->create(), occurredAt: '2026-08-01 09:00:00'
    );

    $this->delivery = app(RecordDelivery::class)->handle(
        customer: $this->customer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10']],
        createdBy: User::factory()->create(),
    );
});

afterEach(fn () => Carbon::setTestNow());

it('shows the returns book to anyone who may view sales', function () {
    ($this->asRole)('Staff')->get('/sales/returns')->assertOk();
});

it('shows the create screen with the caps the engine will enforce', function () {
    ($this->asRole)('Staff')->get('/sales/returns/create?delivery_id='.$this->delivery->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/returns/create')
            ->where('lines.0.quantity_returnable', '10.0000')
            ->where('lines.0.quantity_uninvoiced', '10.0000'));
});

it('lets Staff record a return through the screen', function () {
    ($this->asRole)('Staff')->post('/sales/returns', [
        'delivery_id' => $this->delivery->id,
        'lines' => [[
            'delivery_line_id' => $this->delivery->lines->first()->id,
            'quantity_to_stock' => '3',
            'quantity_written_off' => '1',
        ]],
    ])->assertRedirect();

    $return = SalesReturn::first();
    expect($return)->not->toBeNull()
        ->and((float) $return->lines->first()->quantity)->toBe(4.0)
        ->and((float) StockBatch::first()->current_quantity)->toBe(93.0); // 100 - 10 + 3
});

// ⚠ C-SR-9: recording is Staff-level; CASH leaving the till is not. The
// request records nothing at all when the refund half is refused - one
// transaction, not a return with a missing refund.
it('refuses a Staff return that asks for a cash refund, and records nothing', function () {
    ($this->asRole)('Staff')->post('/sales/returns', [
        'delivery_id' => $this->delivery->id,
        'lines' => [[
            'delivery_line_id' => $this->delivery->lines->first()->id,
            'quantity_to_stock' => '4',
            'quantity_written_off' => '0',
        ]],
        'refund' => ['money_account_id' => $this->cash->id, 'amount' => '100'],
    ])->assertForbidden();

    expect(SalesReturn::count())->toBe(0);
});

it('blocks the refund route for Staff and allows it for a Manager', function () {
    $return = app(RecordSalesReturn::class)->handle(
        delivery: $this->delivery,
        lines: [[
            'deliveryLine' => $this->delivery->lines->first(),
            'quantityToStock' => '4',
            'quantityWrittenOff' => '0',
        ]],
        createdBy: User::factory()->create(),
    );

    ($this->asRole)('Staff')->post("/sales/returns/{$return->id}/refunds", [
        'money_account_id' => $this->cash->id,
        'amount' => '100',
        'refunded_on' => '2026-08-12',
    ])->assertForbidden();

    // An uninvoiced return created no credit, so even a Manager's refund is
    // refused by the CAP, not the permission - proving both layers exist.
    ($this->asRole)('Manager')->post("/sales/returns/{$return->id}/refunds", [
        'money_account_id' => $this->cash->id,
        'amount' => '100',
        'refunded_on' => '2026-08-12',
    ])->assertSessionHasErrors('amount');

    expect(CustomerRefund::count())->toBe(0);
});

it('shows a recorded return with its journal', function () {
    $return = app(RecordSalesReturn::class)->handle(
        delivery: $this->delivery,
        lines: [[
            'deliveryLine' => $this->delivery->lines->first(),
            'quantityToStock' => '4',
            'quantityWrittenOff' => '0',
        ]],
        createdBy: User::factory()->create(),
    );

    ($this->asRole)('Staff')->get("/sales/returns/{$return->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sales/returns/show')
            ->where('salesReturn.return_number', $return->return_number)
            ->has('journal.lines', 2));
});
