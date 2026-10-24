<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Commerce\Support\Availability;
use App\Modules\Commerce\Models\Store;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Actions\RecordStockIn;
use App\Modules\Inventory\Actions\SaveBillOfMaterial;
use App\Modules\Inventory\database\seeders\InventoryAccountMappingSeeder;
use App\Modules\Inventory\database\seeders\InventoryPermissionsSeeder;
use App\Modules\Inventory\Enums\MaterialKind;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\database\seeders\PurchasingPermissionsSeeder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Support\PurchaseOrderForm;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordCounterSale;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy's initial plan B4 (24 Oct 2026; R3, R7, R16 to R18, R22): the product
 * master fields.
 *
 * ⚠⚠ "Can be sold" is enforced by the SERVER on every sales path, not only by
 * the pickers: "Ensure 'Is Sales Item' is unchecked so sales reps can't
 * accidentally invoice raw mustard seeds." Each path is called here with the
 * raw material directly, as a crafted request or an import would send it.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-24 11:00:00');

    $this->business = Business::create(['name' => 'Catalogue Co', 'slug' => 'catalogue-co-b4']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new InventoryPermissionsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    (new PurchasingPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Savar', 'code' => 'SAV', 'is_default' => true]);
    $this->kg = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Kilogram', 'abbreviation' => 'kg']);
    $this->pc = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Piece', 'abbreviation' => 'pc']);
    $this->packagingCategory = Category::create(['business_id' => $this->business->id, 'name' => 'Packaging Bottles', 'slug' => 'packaging-bottles']);

    $this->owner = User::factory()->create();
    $this->owner->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->owner->assignRole('Owner');
    $this->as = fn () => $this->actingAs($this->owner)->withSession(['current_business_id' => $this->business->id]);

    $this->mustard = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $this->kg->id,
        'sku' => 'RM-MST-SEED', 'name' => 'Raw mustard seed', 'type' => ProductType::RawMaterial,
    ]);
    $this->oil = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $this->pc->id,
        'sku' => 'BNC-OIL-MST-1L', 'name' => 'Bionic Mustard Oil 1L', 'type' => ProductType::FinishedGood,
    ]);

    foreach ([$this->mustard, $this->oil] as $product) {
        ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $product->id, 'unit_price' => '300', 'effective_from' => '2026-01-01']);
        app(RecordStockIn::class)->handle($product, $this->warehouse, '50', '150', $this->owner, occurredAt: '2026-10-23 09:00:00');
    }

    $this->dealer = Customer::create(['business_id' => $this->business->id, 'name' => 'Rahim Traders', 'type' => CustomerType::Retail]);
    $this->notForSale = 'Raw mustard seed is not for sale: its Can be sold switch is off. Switch it on in the product popup if you really sell it as it is.';

    $this->props = function (TestResponse $response): array {
        $response->assertOk();

        return $response->viewData('page')['props'];
    };

    $this->refusal = function (callable $sale): ?string {
        try {
            $sale();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return null;
    };
});

it('starts a raw material not for sale and a finished good for sale, whatever path created them', function () {
    expect($this->mustard->fresh()->can_be_sold)->toBeFalse()
        ->and($this->oil->fresh()->can_be_sold)->toBeTrue()
        ->and(Product::query()->sellable()->pluck('id')->all())->toBe([$this->oil->id]);
});

it('reads packaging off the category or a PK- code, and only a raw material has a kind', function () {
    $bottle = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->pc->id, 'sku' => 'BTL-1L', 'name' => 'Bottle 1 L', 'type' => ProductType::RawMaterial, 'category_id' => $this->packagingCategory->id]);
    $cap = Product::create(['business_id' => $this->business->id, 'unit_of_measure_id' => $this->pc->id, 'sku' => 'PK-CAP-28', 'name' => 'Cap 28 mm', 'type' => ProductType::RawMaterial]);

    expect($bottle->fresh()->material_kind)->toBe(MaterialKind::Packaging)
        ->and($cap->fresh()->material_kind)->toBe(MaterialKind::Packaging)
        ->and($this->mustard->fresh()->material_kind)->toBe(MaterialKind::Raw)
        ->and($this->oil->fresh()->material_kind)->toBeNull();
});

it('sets Can be sold, the kind and the usual supplier from the product popup', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Supplier B', 'code' => 'SUP-B']);

    ($this->as)()->putJson(route('inventory.products.update', $this->mustard), [
        'sku' => 'RM-MST-SEED', 'name' => 'Raw mustard seed', 'type' => 'raw_material',
        'unit_of_measure_id' => $this->kg->id,
        'can_be_sold' => true,
        'material_kind' => 'packaging',
        'default_supplier_id' => $supplier->id,
    ])->assertOk();

    $seed = $this->mustard->fresh();

    expect($seed->can_be_sold)->toBeTrue()
        ->and($seed->material_kind)->toBe(MaterialKind::Packaging)
        ->and($seed->default_supplier_id)->toBe($supplier->id);

    // The Raw materials list says it, with the usual supplier's name.
    $row = collect(($this->props)(($this->as)()->get(route('inventory.raw-materials.index')))['products']['data'])->firstWhere('id', $this->mustard->id);

    expect($row)->toMatchArray(['can_be_sold' => true, 'material_kind' => 'packaging', 'default_supplier_id' => $supplier->id, 'default_supplier' => 'Supplier B']);
});

it('refuses a switched-off or another business supplier as the usual supplier', function () {
    $off = Supplier::create(['business_id' => $this->business->id, 'name' => 'Old supplier', 'code' => 'OLD', 'is_active' => false]);
    $otherBusiness = Business::create(['name' => 'Other', 'slug' => 'other-b4']);
    $foreign = Supplier::withoutGlobalScopes()->create(['business_id' => $otherBusiness->id, 'name' => 'Foreign', 'code' => 'FOR']);
    app(BusinessContext::class)->set($this->business->id);

    foreach ([$off->id, $foreign->id] as $supplierId) {
        ($this->as)()->putJson(route('inventory.products.update', $this->mustard), [
            'sku' => 'RM-MST-SEED', 'name' => 'Raw mustard seed', 'type' => 'raw_material',
            'unit_of_measure_id' => $this->kg->id, 'default_supplier_id' => $supplierId,
        ])->assertJsonValidationErrors('default_supplier_id');
    }

    expect($this->mustard->fresh()->default_supplier_id)->toBeNull();
});

it('proposes the usual supplier on a new purchase', function () {
    $supplier = Supplier::create(['business_id' => $this->business->id, 'name' => 'Supplier A', 'code' => 'SUP-A']);
    $this->mustard->update(['default_supplier_id' => $supplier->id]);

    $option = collect(PurchaseOrderForm::props($this->owner)['products'])->firstWhere('id', $this->mustard->id);

    expect($option['default_supplier_id'])->toBe($supplier->id);
});

it('shows Made here only while a recipe for it is in use', function () {
    $row = fn () => collect(($this->props)(($this->as)()->get(route('inventory.products.index')))['products']['data'])->firstWhere('id', $this->oil->id);

    expect($row()['made_here'])->toBeFalse();

    $recipe = app(SaveBillOfMaterial::class)->handle(
        outputProduct: $this->oil,
        lines: [['product_id' => $this->mustard->id, 'quantity_per_output' => '3']],
        by: $this->owner,
    );

    expect($row()['made_here'])->toBeTrue()
        ->and(($this->props)(($this->as)()->get(route('inventory.products.show', $this->oil)))['product']['made_here'])->toBeTrue();

    $recipe->update(['is_active' => false]);

    expect($row()['made_here'])->toBeFalse();
});

it('refuses a raw material on a sales order typed on the form', function () {
    ($this->as)()->post(route('sales.orders.store'), [
        'customer_id' => $this->dealer->id,
        'warehouse_id' => $this->warehouse->id,
        'lines' => [['product_id' => $this->mustard->id, 'quantity' => '5']],
    ])->assertSessionHasErrors();

    expect(SalesOrder::count())->toBe(0);
});

it('refuses it in the order engine itself, which the website, imports and HQ go through', function () {
    expect(($this->refusal)(fn () => app(CreateSalesOrder::class)->handle(
        customer: $this->dealer,
        lines: [['product' => $this->mustard, 'quantity' => '5']],
        createdBy: $this->owner,
        warehouse: $this->warehouse,
    )))->toBe($this->notForSale)
        ->and(SalesOrder::count())->toBe(0);
});

it('refuses it at the counter', function () {
    expect(($this->refusal)(fn () => app(RecordCounterSale::class)->handle(
        customer: Customer::query()->where('is_system', true)->firstOrFail(),
        warehouse: $this->warehouse,
        lines: [['product' => $this->mustard, 'quantity' => '1']],
        createdBy: $this->owner,
    )))->toBe($this->notForSale)
        ->and(Delivery::count())->toBe(0);
});

it('refuses it on a challan with no order behind it', function () {
    expect(($this->refusal)(fn () => app(RecordDelivery::class)->handle(
        customer: $this->dealer,
        warehouse: $this->warehouse,
        lines: [['product' => $this->mustard, 'quantity' => '1']],
        createdBy: $this->owner,
    )))->toBe($this->notForSale)
        ->and(Delivery::count())->toBe(0);
});

it('refuses it on an invoice line billed straight from the product', function () {
    expect(($this->refusal)(fn () => app(RecordSalesInvoice::class)->handle(
        customer: $this->dealer,
        lines: [['product' => $this->mustard, 'quantity' => '1', 'unitPrice' => '300']],
        createdBy: $this->owner,
    )))->toBe($this->notForSale)
        ->and(SalesInvoice::count())->toBe(0);
});

it('refuses it on a quotation', function () {
    ($this->as)()->post(route('sales.quotations.store'), [
        'customer_id' => $this->dealer->id,
        'valid_until' => '2026-11-30',
        'lines' => [['product_id' => $this->mustard->id, 'quantity' => '5']],
    ])->assertSessionHasErrors(['lines.0.product_id' => $this->notForSale]);
});

it('does not offer it on the website', function () {
    $decision = Availability::decide(new Store(['business_id' => $this->business->id]), $this->mustard->fresh());

    expect($decision->sellable)->toBeFalse();
});

it('sells it everywhere once somebody switches Can be sold on', function () {
    $this->mustard->update(['can_be_sold' => true]);

    $order = app(CreateSalesOrder::class)->handle(
        customer: $this->dealer,
        lines: [['product' => $this->mustard->fresh(), 'quantity' => '5']],
        createdBy: $this->owner,
        warehouse: $this->warehouse,
    );

    expect($order->lines()->sole()->product_id)->toBe($this->mustard->id)
        ->and(Product::query()->sellable()->pluck('id')->sort()->values()->all())->toBe(collect([$this->mustard->id, $this->oil->id])->sort()->values()->all());
});

it('keeps selling a finished good with the switch left on', function () {
    $order = app(CreateSalesOrder::class)->handle(
        customer: $this->dealer,
        lines: [['product' => $this->oil, 'quantity' => '2']],
        createdBy: $this->owner,
        warehouse: $this->warehouse,
    );

    expect($order->lines()->count())->toBe(1);
});
