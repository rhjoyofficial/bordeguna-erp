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
use App\Modules\Inventory\Models\Brand;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\Actions\RecordDelivery;
use App\Modules\Sales\Actions\RecordSalesInvoice;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\DeliveryBilling;
use App\Modules\Sales\Support\OrderBrand;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Joy, 11 Oct 2026: every LaLaDia and Bionic order is recorded in Bor de Guna,
 * and each order carries its BRAND so sales, parcels and profit filter per
 * brand. Typed orders choose one (required while the business has brands);
 * challans, parcels and invoices read it through the order; a brand of another
 * business never narrows, widens or reaches anything.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();

    $this->business = obBusiness('Bor de Guna', 'bdg');
    $this->owner = obUser($this->business, 'Owner');
    $this->manager = obUser($this->business, 'Manager');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 450,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);

    $this->lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $this->bnc = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden']);
});

afterEach(fn () => Carbon::setTestNow());

function obBusiness(string $name, string $slug): Business
{
    $business = Business::create(['name' => $name, 'slug' => $slug]);
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web', 'business_id' => $business->id]);
    }

    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

    return $business;
}

function obUser(Business $business, string $role): User
{
    $user = User::factory()->create();
    $user->businesses()->attach($business->id);
    $user->assignRole($role);

    return $user;
}

function obOrder(object $t, ?Brand $brand, string $quantity = '1'): SalesOrder
{
    return app(CreateSalesOrder::class)->handle(
        customer: $t->customer,
        lines: [['product' => $t->product, 'quantity' => $quantity]],
        createdBy: $t->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal),
        brandId: $brand?->id,
    );
}

function obDeliver(object $t, SalesOrder $order): Delivery
{
    return app(RecordDelivery::class)->handle(
        customer: $t->customer,
        warehouse: $t->warehouse,
        lines: [['product' => $t->product, 'quantity' => (string) $order->lines->first()->quantity_ordered, 'orderLine' => $order->lines->first()]],
        createdBy: $t->owner,
        deliveredAt: '2026-10-11 09:00:00',
    );
}

function obParcel(object $t, Delivery $delivery): int
{
    $courier = MoneyAccount::query()->firstOrCreate(
        ['business_id' => $t->business->id, 'name' => 'CarryBee'],
        ['type' => MoneyAccountType::Courier, 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true],
    );

    return DB::table('consignments')->insertGetId([
        'business_id' => $t->business->id, 'delivery_id' => $delivery->id, 'money_account_id' => $courier->id,
        'consignment_number' => 'C-'.Str::random(8), 'cod_amount' => 0, 'status' => 'booked',
        'created_by' => $t->owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function obPost(object $t, array $data, ?User $as = null)
{
    return $t->actingAs($as ?? $t->manager)->post(route('sales.orders.store'), $data + [
        'customer_id' => $t->customer->id,
        'lines' => [['product_id' => $t->product->id, 'quantity' => '1']],
    ]);
}

it('requires a brand on a typed order while the business sells under brands, and records it', function () {
    obPost($this, [])->assertSessionHasErrors(['brand_id' => 'Choose the brand this order is sold under.']);
    expect(SalesOrder::query()->count())->toBe(0);

    obPost($this, ['brand_id' => $this->bnc->id])->assertSessionHasNoErrors();

    expect(SalesOrder::query()->sole()->brand_id)->toBe($this->bnc->id);
});

it('takes a typed order without a brand where the business has none in use', function () {
    $this->lld->update(['is_active' => false]);
    $this->bnc->update(['is_active' => false]);

    obPost($this, [])->assertSessionHasNoErrors();

    expect(SalesOrder::query()->sole()->brand_id)->toBeNull();
});

it('refuses a brand of another business and one no longer in use', function () {
    $other = obBusiness('Other Co', 'other');
    $foreign = Brand::create(['business_id' => $other->id, 'code' => 'XYZ', 'name' => 'Foreign', 'slug' => 'foreign']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    obPost($this, ['brand_id' => $foreign->id])->assertSessionHasErrors(['brand_id' => 'That brand belongs to a different business, or is in the Recycle bin.']);

    $this->lld->update(['is_active' => false]);
    obPost($this, ['brand_id' => $this->lld->id])->assertSessionHasErrors(['brand_id' => 'LaLaDia is no longer in use.']);

    expect(SalesOrder::query()->count())->toBe(0);

    // The action itself refuses too, whoever calls it.
    expect(fn () => obOrder($this, $foreign))->toThrow(ValidationException::class);
});

it('offers the brands and remembers the person\'s last brand on the order form', function () {
    $this->actingAs($this->manager)->get(route('sales.orders.index'))
        ->assertInertia(fn (Assert $page) => $page->has('brands', 2)->where('last_brand_id', null));

    obPost($this, ['brand_id' => $this->lld->id])->assertSessionHasNoErrors();
    obPost($this, ['brand_id' => $this->bnc->id])->assertSessionHasNoErrors();

    $this->actingAs($this->manager)->get(route('sales.orders.index'))
        ->assertInertia(fn (Assert $page) => $page->where('last_brand_id', $this->bnc->id));

    // Somebody else's last brand is theirs alone.
    $this->actingAs($this->owner)->get(route('sales.orders.index'))
        ->assertInertia(fn (Assert $page) => $page->where('last_brand_id', null));
});

it('filters the order list by brand, by "no brand", and shows the brand on each row and on the order', function () {
    $lld = obOrder($this, $this->lld);
    $bnc = obOrder($this, $this->bnc);
    $none = obOrder($this, null);

    $numbers = fn (string $query) => collect($this->actingAs($this->owner)->get('/sales/orders'.$query)
        ->viewData('page')['props']['orders']['data'])->pluck('order_number')->sort()->values()->all();

    expect($numbers(''))->toBe(collect([$lld, $bnc, $none])->pluck('order_number')->sort()->values()->all())
        ->and($numbers('?brand='.$this->lld->id))->toBe([$lld->order_number])
        ->and($numbers('?brand='.$this->bnc->id))->toBe([$bnc->order_number])
        ->and($numbers('?brand=none'))->toBe([$none->order_number]);

    $this->actingAs($this->owner)->get('/sales/orders?brand='.$this->bnc->id)
        ->assertInertia(fn (Assert $page) => $page->where('filters.brand', (string) $this->bnc->id)
            ->where('orders.data.0.brand.name', 'Bionic-Garden'));

    $this->actingAs($this->owner)->get(route('sales.orders.show', $lld))
        ->assertInertia(fn (Assert $page) => $page->where('order.brand.name', 'LaLaDia'));
});

it('ignores a brand of another business in a list filter instead of narrowing to it', function () {
    obOrder($this, $this->lld);
    obOrder($this, $this->bnc);

    $other = obBusiness('Other Co', 'other');
    $foreign = Brand::create(['business_id' => $other->id, 'code' => 'XYZ', 'name' => 'Foreign', 'slug' => 'foreign']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->actingAs($this->owner)->get('/sales/orders?brand='.$foreign->id)
        ->assertInertia(fn (Assert $page) => $page->where('filters.brand', '')->has('orders.data', 2)
            ->has('brands', 2)->where('brands.0.name', 'Bionic-Garden'));

    expect(OrderBrand::filter((string) $foreign->id, $this->business->id))->toBeNull()
        ->and(OrderBrand::filter('abc', $this->business->id))->toBeNull()
        ->and(OrderBrand::filter('none', $this->business->id))->toBe('none')
        ->and(OrderBrand::filter((string) $this->lld->id, $this->business->id))->toBe($this->lld->id);
});

it('reads the brand of challans, parcels and invoices through their order, and filters each list by it', function () {
    $lldDelivery = obDeliver($this, obOrder($this, $this->lld));
    $bncDelivery = obDeliver($this, obOrder($this, $this->bnc));
    $noneDelivery = obDeliver($this, obOrder($this, null));
    foreach ([$lldDelivery, $bncDelivery] as $delivery) {
        app(RecordSalesInvoice::class)->handle(
            customer: $this->customer,
            lines: DeliveryBilling::lines($delivery->fresh(['lines.product', 'lines.orderLine', 'customer']), DeliveryBilling::everythingUninvoiced($delivery), app(ResolveSellingPrice::class), Carbon::today()),
            createdBy: $this->owner,
            invoiceDate: '2026-10-11',
        );
    }

    // Written straight to the table after billing: only the brand path is
    // under test here, not the courier rules.
    obParcel($this, $lldDelivery);
    obParcel($this, $bncDelivery);
    obParcel($this, $noneDelivery);

    $get = fn (string $url) => $this->actingAs($this->owner)->get($url)->viewData('page')['props'];

    // Challans.
    $rows = collect($get('/sales/deliveries')['deliveries']['data'])->keyBy('id');
    expect($rows[$lldDelivery->id]['brand'])->toBe('LaLaDia')->and($rows[$noneDelivery->id]['brand'])->toBeNull()
        ->and(collect($get('/sales/deliveries?brand='.$this->bnc->id)['deliveries']['data'])->pluck('id')->all())->toBe([$bncDelivery->id])
        ->and(collect($get('/sales/deliveries?brand=none')['deliveries']['data'])->pluck('id')->all())->toBe([$noneDelivery->id]);

    // Parcels.
    $parcels = $get('/sales/consignments?status=all&brand='.$this->lld->id)['consignments']['data'];
    expect($parcels)->toHaveCount(1)->and($parcels[0]['delivery_number'])->toBe($lldDelivery->delivery_number)->and($parcels[0]['brand'])->toBe('LaLaDia')
        ->and($get('/sales/consignments?status=all')['consignments']['data'])->toHaveCount(3);

    // Invoices.
    $invoices = $get('/sales/invoices?brand='.$this->bnc->id)['invoices']['data'];
    expect($invoices)->toHaveCount(1)->and($invoices[0]['brand'])->toBe('Bionic-Garden')
        ->and($get('/sales/invoices')['invoices']['data'])->toHaveCount(2)
        ->and($get('/sales/invoices?brand=none')['invoices']['data'])->toHaveCount(0);
});
