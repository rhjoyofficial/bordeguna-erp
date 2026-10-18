<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Receipt Notes, purchase returns and outstanding orders (purchase
 * redesign PR2, 18 Oct 2026): the three Tally screens, reached by route,
 * screen and tab, every record linked to the others.
 *
 * One story: an order for 10 honey and 5 ghee, 6 honey arrive, 2 go back.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-18 10:00:00');

    $this->business = Business::create(['name' => 'Honey House', 'slug' => 'honey-house']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    (new InventoryPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV']);
    $jar = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Jar', 'abbreviation' => 'jar']);

    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $jar->id, 'sku' => 'HNY-500',
        'name' => 'Sundarban honey 500g', 'type' => ProductType::FinishedGood,
    ]);
    $this->ghee = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $jar->id, 'sku' => 'GHE-250',
        'name' => 'Cow ghee 250g', 'type' => ProductType::FinishedGood,
    ]);

    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Mouchak Apiary']);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->manager->assignRole('Manager');

    $this->as = fn (User $user) => $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);

    $this->order = app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [
            ['product' => $this->honey, 'quantity' => '10', 'unit_cost' => '450'],
            ['product' => $this->ghee, 'quantity' => '5', 'unit_cost' => '300'],
        ],
        createdBy: $this->manager,
    );
    approveIfPending($this->order->approvalRequest, $this->manager);
    $this->order->update(['expected_date' => '2026-10-10']);
    $this->order = $this->order->fresh(['lines', 'approvalRequest']);

    ($this->as)($this->manager)->post(route('purchasing.purchase-orders.receive', $this->order), [
        'supplier_challan_number' => 'CH-77',
        'lines' => [
            ['purchase_order_line_id' => $this->order->lines[0]->id, 'quantity' => '6'],
            ['purchase_order_line_id' => $this->order->lines[1]->id, 'quantity' => '0'],
        ],
    ])->assertSessionHasNoErrors();

    ($this->as)($this->manager)->post(route('purchasing.purchase-orders.returns.store', $this->order), [
        'note' => 'Two jars cracked',
        'lines' => [['purchase_order_line_id' => $this->order->lines[0]->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    $this->receipt = GoodsReceipt::sole();
    $this->return = PurchaseReturn::sole();
});

afterEach(fn () => Carbon::setTestNow());

it('lists every delivery with its order, supplier, value and bill state', function () {
    ($this->as)($this->manager)->get('/purchasing/receipts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/receipts/index')
            ->has('receipts.data', 1)
            ->where('receipts.data.0.id', $this->receipt->id)
            ->where('receipts.data.0.supplier_challan_number', 'CH-77')
            ->where('receipts.data.0.order.po_number', $this->order->po_number)
            ->where('receipts.data.0.supplier.name', 'Mouchak Apiary')
            ->where('receipts.data.0.warehouse', 'Savar')
            ->where('receipts.data.0.value', '2700.00')
            // 6 arrived, 2 went back, nothing billed: 4 still to bill.
            ->where('receipts.data.0.bill_state', 'open'));
});

it('filters receipts by bill state, supplier, date and order', function () {
    $count = fn (array $query) => ($this->as)($this->manager)->get('/purchasing/receipts?'.http_build_query($query))
        ->assertOk()->viewData('page')['props']['receipts']['total'];

    expect($count(['billing' => 'open']))->toBe(1)
        ->and($count(['billing' => 'billed']))->toBe(0)
        ->and($count(['supplier_id' => $this->supplier->id]))->toBe(1)
        ->and($count(['supplier_id' => $this->supplier->id + 99]))->toBe(0)
        ->and($count(['from' => '2026-10-19']))->toBe(0)
        ->and($count(['to' => '2026-10-18']))->toBe(1)
        ->and($count(['purchase_order_id' => $this->order->id]))->toBe(1)
        ->and($count(['search' => 'CH-77']))->toBe(1);
});

it('shows a receipt with its batch, what is left to bill and the return from it', function () {
    ($this->as)($this->manager)->get("/purchasing/receipts/{$this->receipt->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/receipts/show')
            ->where('receipt.order.id', $this->order->id)
            ->where('receipt.supplier.id', $this->supplier->id)
            ->where('receipt.value', '2700.00')
            ->has('lines', 1)
            ->where('lines.0.product', 'Sundarban honey 500g')
            ->where('lines.0.batch.batch_number', 'HNY-500-261018-01')
            ->where('lines.0.batch.current_quantity', '4.0000')
            ->where('lines.0.billable', '4.0000')
            ->has('returns', 1)
            ->where('returns.0.id', $this->return->id)
            ->where('returns.0.quantity', '2.0000')
            ->has('landed_costs', 0)
            ->has('bills', 0));
});

it('lists returns and shows one linked to the delivery its batch came on', function () {
    ($this->as)($this->manager)->get('/purchasing/returns')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/returns/index')
            ->has('returns.data', 1)
            ->where('returns.data.0.id', $this->return->id)
            ->where('returns.data.0.order.id', $this->order->id)
            ->where('returns.data.0.value', '900.00')
            ->where('returns.data.0.credited', '0.00'));

    ($this->as)($this->manager)->get("/purchasing/returns/{$this->return->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/returns/show')
            ->where('purchaseReturn.note', 'Two jars cracked')
            ->where('lines.0.receipt.id', $this->receipt->id)
            ->where('lines.0.batch.batch_number', 'HNY-500-261018-01')
            ->has('credit_notes', 0));
});

it('reports what is still to come, late against the expected date, by line, supplier and product', function () {
    ($this->as)($this->manager)->get('/purchasing/outstanding-orders')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('purchasing/outstanding-orders/index')
            ->has('rows', 2)
            ->where('totals.lines', 2)
            ->where('totals.orders', 1)
            ->where('totals.overdue_lines', 2)
            // 4 honey at 450 + 5 ghee at 300.
            ->where('totals.pending_value', '3300.00')
            ->where('rows.0.pending', '4.0000')
            ->where('rows.0.overdue_days', 8)
            ->where('rows.0.approved', true));

    ($this->as)($this->manager)->get('/purchasing/outstanding-orders?view=supplier')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 0)
            ->has('groups', 1)
            ->where('groups.0.name', 'Mouchak Apiary')
            ->where('groups.0.lines', 2)
            ->where('groups.0.pending_value', '3300.00'));

    ($this->as)($this->manager)->get("/purchasing/outstanding-orders?view=product&product_id={$this->ghee->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups', 1)
            ->where('groups.0.pending', '5.0000')
            ->where('groups.0.pending_value', '1500.00'));
});

it('drops a fully received order from the outstanding list', function () {
    ($this->as)($this->manager)->post(route('purchasing.purchase-orders.receive', $this->order), [
        'lines' => [
            ['purchase_order_line_id' => $this->order->lines[0]->id, 'quantity' => '4'],
            ['purchase_order_line_id' => $this->order->lines[1]->id, 'quantity' => '5'],
        ],
    ])->assertSessionHasNoErrors();

    ($this->as)($this->manager)->get('/purchasing/outstanding-orders')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('rows', 0)->where('totals.lines', 0));
});

it('keeps all three screens behind the purchasing read permission', function (string $url) {
    $nobody = User::factory()->create();
    $nobody->businesses()->attach($this->business->id);

    ($this->as)($nobody)->get(str_replace(['{receipt}', '{return}'], [$this->receipt->id, $this->return->id], $url))->assertForbidden();
})->with(['/purchasing/receipts', '/purchasing/receipts/{receipt}', '/purchasing/returns', '/purchasing/returns/{return}', '/purchasing/outstanding-orders']);

it('links each delivery and return from the order page', function () {
    $source = file_get_contents(resource_path('js/pages/purchasing/purchase-orders/show.tsx'));

    expect($source)->toContain('/purchasing/receipts/${receipt.id}')
        ->and($source)->toContain('/purchasing/returns/${purchaseReturn.id}');
});
