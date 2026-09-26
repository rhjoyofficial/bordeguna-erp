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
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Models\ProductPrice;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module 3, Slice 2 (POS spec): held / parked carts.
 *
 * A customer steps aside to fetch money; the queue must keep moving. Holding
 * a cart saves the counter state and NOTHING else: no stock reserved, no
 * document posted, no price frozen. On resume the counter re-prices through
 * the same resolver as always - a hold is a bookmark, never a transaction.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Hold Co', 'slug' => 'hold-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web', 'business_id' => $this->business->id]);
    }
    (new SalesPermissionsSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new InventoryAccountMappingSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    (new WalkInCustomerSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Carbon::setTestNow('2026-08-24 10:00:00');

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Shop', 'code' => 'SH']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id,
        'sku' => 'SKU-HONEY', 'name' => 'Sundarban Honey 500g',
        'type' => ProductType::FinishedGood, 'is_active' => true,
    ]);

    $this->cashier = User::factory()->create();
    $this->cashier->businesses()->attach($this->business->id);
    setPermissionsTeamId($this->business->id);
    $this->cashier->assignRole('Owner');

    $this->bystander = User::factory()->create();
    $this->bystander->businesses()->attach($this->business->id);

    $this->cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Till', 'type' => 'cash',
        'account_id' => Account::bySlug('cash_in_hand')->id,
    ]);
    openTill($this->cashier, $this->cash);

    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '10', '500', $this->cashier, occurredAt: '2026-08-01 09:00:00');

    ProductPrice::create([
        'business_id' => $this->business->id, 'product_id' => $this->product->id,
        'unit_price' => '850', 'effective_from' => '2026-01-01',
    ]);

    $this->asCashier = fn () => $this->actingAs($this->cashier)
        ->withHeader('X-Business-Id', (string) $this->business->id);
});

afterEach(fn () => Carbon::setTestNow());

function holdCart(array $overrides = []): int
{
    (test()->asCashier)()->post(route('sales.counter.hold'), array_merge([
        'label' => 'Man in the blue lungi',
        'warehouse_id' => test()->warehouse->id,
        'lines' => [['product_id' => test()->product->id, 'quantity' => '2']],
    ], $overrides))->assertSessionHasNoErrors();

    return (int) DB::table('pos_held_carts')->latest('id')->value('id');
}

it('holds a cart without touching stock or the ledger, and lists it on the counter', function () {
    $journalsBefore = DB::table('journals')->count();
    $movementsBefore = DB::table('stock_movements')->count();

    holdCart();

    expect(DB::table('pos_held_carts')->count())->toBe(1)
        ->and(DB::table('journals')->count())->toBe($journalsBefore)
        ->and(DB::table('stock_movements')->count())->toBe($movementsBefore);

    ($this->asCashier)()->get(route('sales.counter.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/counter/create')
            ->has('heldCarts', 1)
            ->where('heldCarts.0.label', 'Man in the blue lungi')
            ->where('heldCarts.0.item_count', 1));
});

it('resumes a held cart onto the counter, and the completed sale consumes the hold', function () {
    $cart = holdCart();

    // Resuming shows the saved lines - the hold survives the page load, so a
    // browser closed mid-resume loses nothing.
    ($this->asCashier)()->get(route('sales.counter.create', ['resume' => $cart]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/counter/create')
            ->where('resumeCart.id', $cart)
            ->where('resumeCart.lines.0.product_id', fn ($value) => (string) $value === (string) $this->product->id)
            ->where('resumeCart.lines.0.quantity', fn ($value) => (string) $value === '2'));

    expect(DB::table('pos_held_carts')->count())->toBe(1);

    // Completing the sale with the hold's id deletes it - the bookmark has
    // become a real invoice and must not linger to be sold twice.
    ($this->asCashier)()->post(route('sales.counter.store'), [
        'customer_id' => DB::table('customers')->where('is_system', true)->value('id'),
        'warehouse_id' => $this->warehouse->id,
        'money_account_id' => $this->cash->id,
        'held_cart_id' => $cart,
        'lines' => [['product_id' => $this->product->id, 'quantity' => '2']],
    ])->assertSessionHasNoErrors();

    expect(DB::table('pos_held_carts')->count())->toBe(0)
        ->and(DB::table('sales_invoices')->count())->toBe(1);
});

it('discards a held cart on request, and keeps outsiders off entirely', function () {
    $cart = holdCart();

    ($this->asCashier)()->delete(route('sales.counter.hold.discard', $cart))
        ->assertSessionHasNoErrors();

    expect(DB::table('pos_held_carts')->count())->toBe(0);

    $this->actingAs($this->bystander)->withHeader('X-Business-Id', (string) $this->business->id)
        ->post(route('sales.counter.hold'), [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1']],
        ])->assertForbidden();
});

it('refuses to hold an empty cart', function () {
    ($this->asCashier)()->post(route('sales.counter.hold'), [
        'warehouse_id' => $this->warehouse->id,
        'lines' => [],
    ])->assertSessionHasErrors('lines');
});
