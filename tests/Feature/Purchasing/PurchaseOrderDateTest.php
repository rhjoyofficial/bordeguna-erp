<?php

use App\Models\User;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\PurchaseOrderDateBackfill;
use App\Support\ApprovalCards;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ D-G (Joy, 18 Oct 2026): "many order done previous date but add later, so
 * current date will be default, but can be changed". A purchase order keeps
 * the day it was placed: today unless told otherwise, never the future, never
 * inside closed books, and every screen that says when it was ordered reads it.
 */
beforeEach(function () {
    // 05:00 Dhaka is 23:00 UTC the day before: the hour a UTC date slips.
    Carbon::setTestNow(Carbon::parse('2026-10-18 05:00:00', 'Asia/Dhaka'));

    $this->business = Business::create(['name' => 'Order Date Co', 'slug' => 'order-date-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id,
        'unit_of_measure_id' => $unit->id,
        'sku' => 'PO-DATE-1',
        'name' => 'Jar',
        'type' => ProductType::RawMaterial,
    ]);
    $this->supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Glass House']);

    $permissions = ['purchasing.view', 'purchasing.po.create', 'purchasing.po.approve', 'purchasing.receipt.record', 'purchasing.reports.view'];

    foreach ($permissions as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    Role::firstOrCreate(['name' => 'Manager', 'business_id' => $this->business->id, 'guard_name' => 'web'])->givePermissionTo($permissions);

    $this->manager = User::factory()->create();
    $this->manager->businesses()->attach($this->business->id);
    $this->manager->assignRole('Manager');

    $this->as = fn () => $this->actingAs($this->manager)->withSession(['current_business_id' => $this->business->id]);

    $this->store = fn (array $extra = []) => ($this->as)()->post(route('purchasing.purchase-orders.store'), $extra + [
        'supplier_id' => $this->supplier->id,
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '5']],
    ]);

    $this->create = fn (?string $orderDate = null) => app(CreatePurchaseOrder::class)->handle(
        supplier: $this->supplier,
        warehouse: $this->warehouse,
        lines: [['product' => $this->product, 'quantity' => '10', 'unit_cost' => '5']],
        createdBy: $this->manager,
        orderDate: $orderDate,
    );
});

afterEach(fn () => Carbon::setTestNow());

it('dates an order today in Dhaka when no day is given', function () {
    $order = ($this->create)();

    expect($order->fresh()->order_date->toDateString())->toBe('2026-10-18');
});

it('accepts an earlier order date from the form and keeps it', function () {
    ($this->store)(['order_date' => '2026-10-12'])->assertSessionHasNoErrors()->assertRedirect();

    expect(PurchaseOrder::sole()->order_date->toDateString())->toBe('2026-10-12');
});

it('refuses an order date in the future, under the date box', function () {
    ($this->store)(['order_date' => '2026-10-19'])->assertSessionHasErrors('order_date');

    expect(PurchaseOrder::count())->toBe(0)
        ->and(fn () => ($this->create)('2026-10-19'))->toThrow(InvalidArgumentException::class, 'in the future');
});

it('requires the order date on the form', function () {
    ($this->store)()->assertSessionHasErrors('order_date');

    expect(PurchaseOrder::count())->toBe(0);
});

it('refuses an order date inside closed books, naming the period', function () {
    AccountingPeriod::create(['business_id' => $this->business->id, 'closed_through' => '2026-09-30', 'closed_by' => $this->manager->id]);

    ($this->store)(['order_date' => '2026-09-25'])->assertSessionHasErrors([
        'order_date' => 'The books are closed through 2026-09-30, so nothing can be posted or backdated to 2026-09-25. Ask the Founder to unlock the period if this is unavoidable.',
    ]);

    expect(PurchaseOrder::count())->toBe(0);

    // The first open day is fine.
    ($this->store)(['order_date' => '2026-10-01'])->assertSessionHasNoErrors();
});

it('backfills an older order with the Dhaka day of its created_at', function () {
    ($this->create)();

    // created_at is written in the app's clock (Asia/Dhaka). 23:30 on the 11th
    // is 17:30 UTC and 00:15 on the 12th is 18:15 UTC on the 11th: both keep
    // their Dhaka day, which a UTC reading would get wrong for the second.
    expect(PurchaseOrderDateBackfill::dateFor('2026-10-11 23:30:00'))->toBe('2026-10-11')
        ->and(PurchaseOrderDateBackfill::dateFor('2026-10-12 00:15:00'))->toBe('2026-10-12')
        ->and(PurchaseOrderDateBackfill::remaining())->toBe(0);
});

it('shows the order date on the order page, the list and the print', function () {
    ($this->store)(['order_date' => '2026-10-12']);
    $order = PurchaseOrder::sole();

    ($this->as)()->get(route('purchasing.purchase-orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('order.order_date', '2026-10-12'));

    ($this->as)()->get(route('purchasing.purchase-orders.index'))
        ->assertInertia(fn ($page) => $page->where('orders.data.0.order_date', '2026-10-12'));

    ($this->as)()->get(route('purchasing.purchase-orders.print', $order))
        ->assertInertia(fn ($page) => $page->where('order.order_date', '2026-10-12'));
});

it('puts the order date on the approval card', function () {
    $order = ($this->create)('2026-10-12')->load('supplier', 'lines.product');
    $request = $order->approvalRequest()->firstOrFail()->load('requestedBy');
    $request->setRelation('approvable', $order);
    $card = app(ApprovalCards::class)->present($request);

    expect(collect($card['details'])->firstWhere('label', 'Ordered')['value'] ?? null)->toBe('2026-10-12');
});

it('warns, and still records, a receipt dated before the order date', function () {
    ($this->store)(['order_date' => '2026-10-15']);
    $order = PurchaseOrder::sole();

    ($this->as)()->post(route('purchasing.purchase-orders.receive', $order), [
        'received_on' => '2026-10-14',
        'backdate_reason' => 'Challan reached the office late',
        'lines' => [['purchase_order_line_id' => $order->lines()->first()->id, 'quantity' => '10']],
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('warning', fn (string $warning) => str_contains($warning, 'Received before the order date (15 Oct 2026): check the dates.'));

    expect($order->goodsReceipts()->count())->toBe(1);
});

it('says nothing about dates when the receipt is on or after the order date', function () {
    ($this->store)(['order_date' => '2026-10-15']);
    $order = PurchaseOrder::sole();

    ($this->as)()->post(route('purchasing.purchase-orders.receive', $order), [
        'lines' => [['purchase_order_line_id' => $order->lines()->first()->id, 'quantity' => '10']],
    ])->assertSessionHasNoErrors()->assertSessionMissing('warning');
});
