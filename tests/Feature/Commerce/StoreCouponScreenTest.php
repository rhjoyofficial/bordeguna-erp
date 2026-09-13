<?php

use App\Models\User;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCoupon;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\SystemActor;
use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\UnitOfMeasure;
use App\Modules\Sales\Actions\CreateSalesOrder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Website slice 2.5b: the screen where a shop's coupons are made.
 *
 * ⚠⚠ A coupon decides what a customer pays, so writing one needs
 * `sales.prices.manage`; a content editor may read the screen and not change it.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    (new CommercePermissionsSeeder)->run();
    (new SalesPermissionsSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    };

    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->honey = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'HON-250', 'name' => 'Sundarban Honey 250g', 'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);
    ProductPrice::create(['product_id' => $this->honey->id, 'unit_price' => '450', 'effective_from' => '2026-01-01']);

    $this->store = Store::create(['key' => 'laladia', 'name' => 'LaLaDia', 'order_prefix' => 'LLD']);

    $this->payload = fn (array $overrides = []) => array_merge([
        'code' => 'welcome10', 'type' => 'percent', 'value' => '10', 'applies_to' => 'all',
        'is_active' => true, 'is_public' => false, 'is_free_delivery' => false,
    ], $overrides);
});

it('lets somebody who manages prices create a coupon, spelled one way', function () {
    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/coupons", ($this->payload)())
        ->assertSessionHasNoErrors();

    expect(StoreCoupon::sole()->code)->toBe('WELCOME10');
});

it('does not let a content editor create or change a coupon', function () {
    ($this->asRole)('Staff')
        ->post("/commerce/stores/{$this->store->id}/coupons", ($this->payload)())
        ->assertForbidden();

    ($this->asRole)('Staff')
        ->get("/commerce/stores/{$this->store->id}/coupons")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('commerce/stores/coupons')->where('can_manage', false));
});

it('refuses a code already used on this shop, and one that gives nothing', function () {
    StoreCoupon::create(['store_id' => $this->store->id, 'code' => 'WELCOME10', 'type' => 'percent', 'value' => '10', 'applies_to' => 'all']);

    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/coupons", ($this->payload)())
        ->assertSessionHasErrors('code');

    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/coupons", ($this->payload)(['code' => 'NOTHING', 'value' => '0']))
        ->assertSessionHasErrors(['value' => 'A code must take something off, give free delivery or give a gift.']);
});

it('keeps only the scope list that is in force', function () {
    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/coupons", ($this->payload)(['applies_to' => 'products', 'product_ids' => [$this->honey->id]]))
        ->assertSessionHasNoErrors();

    $coupon = StoreCoupon::sole();
    expect($coupon->products()->pluck('products.id')->all())->toBe([$this->honey->id]);

    ($this->asRole)('Owner')
        ->put("/commerce/stores/{$this->store->id}/coupons/{$coupon->id}", ($this->payload)(['applies_to' => 'all', 'product_ids' => [$this->honey->id]]))
        ->assertSessionHasNoErrors();

    expect($coupon->products()->count())->toBe(0);
});

it('makes many single-use codes, none of them ambiguous to read aloud', function () {
    ($this->asRole)('Owner')
        ->post("/commerce/stores/{$this->store->id}/coupons/bulk", ['prefix' => 'eid', 'count' => 25, 'type' => 'fixed', 'value' => '100'])
        ->assertSessionHasNoErrors();

    $codes = StoreCoupon::pluck('code');

    expect($codes)->toHaveCount(25)
        ->and($codes->unique())->toHaveCount(25)
        ->and($codes->every(fn ($code) => preg_match('/^EID[A-HJ-NP-Z2-9]{8}$/', $code) === 1))->toBeTrue()
        ->and(StoreCoupon::where('usage_limit', 1)->where('limit_per_customer', 1)->count())->toBe(25);
});

it('shows usage counted from orders, and will not delete a code an order used', function () {
    $coupon = StoreCoupon::create(['store_id' => $this->store->id, 'code' => 'WELCOME10', 'type' => 'percent', 'value' => '10', 'applies_to' => 'all']);
    $customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Buyer', 'type' => CustomerType::Retail]);

    $order = fn () => app(CreateSalesOrder::class)->handle(
        customer: $customer,
        lines: [['product' => $this->honey, 'quantity' => '1']],
        createdBy: SystemActor::user(),
        origin: new SalesOrderOrigin(channel: SalesChannel::Website, storeId: $this->store->id, couponCode: 'WELCOME10', orderDiscount: '45'),
    );

    $order();
    $order()->forceFill(['status' => SalesOrderStatus::Cancelled])->save();

    // ⭐ Two orders carry the code; the cancelled one does not count.
    ($this->asRole)('Owner')
        ->get("/commerce/stores/{$this->store->id}/coupons")
        ->assertInertia(fn (Assert $page) => $page->where('coupons.0.times_used', 1));

    ($this->asRole)('Owner')
        ->delete("/commerce/stores/{$this->store->id}/coupons/{$coupon->id}")
        ->assertSessionHasErrors(['coupon' => 'WELCOME10 has been used on an order, so it cannot be deleted. Switch it off instead.']);

    expect(SalesOrder::count())->toBe(2)->and(StoreCoupon::count())->toBe(1);
});
