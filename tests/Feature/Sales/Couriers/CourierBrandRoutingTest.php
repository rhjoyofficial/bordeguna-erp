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
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierLocation;
use App\Modules\Sales\Couriers\Models\CourierLocationMap;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use App\Modules\Sales\database\seeders\SalesPermissionsSeeder;
use App\Modules\Sales\database\seeders\WalkInCustomerSeeder;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\SalesOrderOrigin;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Courier routing by brand (Joy, 11 Oct 2026): the CarryBee LaLaDia account
 * carries LaLaDia parcels, the CarryBee Bionic account Bionic ones, Pathao both.
 * A link serves a brand (null = any); the default is per (business, courier,
 * brand); an account kept for another brand is shown disabled and refused by
 * the server.
 */
const CBR_CB = 'https://developers.carrybee.com';
const CBR_PATHAO = 'https://api-hermes.pathao.com';

beforeEach(function () {
    Carbon::setTestNow('2026-10-11 10:00:00');
    $this->withoutVite();
    Http::preventStrayRequests();

    $this->business = cbrBusiness('Bor de Guna', 'bdg');

    foreach (['owner' => 'Owner', 'manager' => 'Manager'] as $prop => $role) {
        $this->{$prop} = User::factory()->create();
        $this->{$prop}->businesses()->attach($this->business->id);
        $this->{$prop}->assignRole($role);
    }

    $this->warehouse = Warehouse::create(['business_id' => $this->business->id, 'name' => 'Main', 'code' => 'MAIN']);
    $unit = UnitOfMeasure::create(['business_id' => $this->business->id, 'name' => 'Pieces', 'abbreviation' => 'pcs']);
    $this->product = Product::create([
        'business_id' => $this->business->id, 'unit_of_measure_id' => $unit->id, 'weight_grams' => 450,
        'sku' => 'SKU-GHEE', 'name' => 'Royal Essence Ghee 400g', 'type' => ProductType::FinishedGood,
    ]);
    ProductPrice::create(['business_id' => $this->business->id, 'product_id' => $this->product->id, 'unit_price' => '850', 'effective_from' => '2026-01-01']);
    app(RecordStockIn::class)->handle($this->product, $this->warehouse, '100', '500', $this->owner, occurredAt: '2026-10-01 09:00:00');
    $this->customer = Customer::create(['business_id' => $this->business->id, 'name' => 'Web buyer', 'type' => CustomerType::Retail]);
    $this->money = cbrMoney($this->business);

    $this->lld = Brand::create(['business_id' => $this->business->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $this->bnc = Brand::create(['business_id' => $this->business->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden']);

    foreach ([['carrybee', '14', '101'], ['pathao', '1', '52']] as [$provider, $city, $zone]) {
        CourierLocation::create(['provider' => $provider, 'level' => 'city', 'provider_location_id' => $city, 'name' => 'Dhaka', 'synced_at' => now()]);
        CourierLocation::create(['provider' => $provider, 'level' => 'zone', 'provider_location_id' => $zone, 'parent_provider_id' => $city, 'name' => 'Dhanmondi', 'synced_at' => now()]);
        CourierLocationMap::create([
            'provider' => $provider, 'district' => 'Dhaka', 'thana' => 'Dhanmondi',
            'provider_city_id' => $city, 'provider_city_name' => 'Dhaka', 'provider_zone_id' => $zone, 'provider_zone_name' => 'Dhanmondi',
            'status' => CourierLocationMap::APPLIED, 'source' => CourierLocationMap::MANUAL, 'confidence' => CourierLocationMap::HIGH,
        ]);
    }

    // Production's shape: two CarryBee accounts, one per brand, and Pathao for both.
    $this->cbLaladia = cbrAccount('carrybee', 'LaLaDia');
    $this->cbBionic = cbrAccount('carrybee', 'Bionic');
    $this->pathao = cbrAccount('pathao', 'Bor de Guna');
    cbrLink($this->cbLaladia, $this->business, $this->money, '24869', $this->lld->id, true);
    cbrLink($this->cbBionic, $this->business, $this->money, '77001', $this->bnc->id, true);
    cbrLink($this->pathao, $this->business, $this->money, '393838', null, true);
});

afterEach(fn () => Carbon::setTestNow());

function cbrBusiness(string $name, string $slug): Business
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

function cbrMoney(Business $business): MoneyAccount
{
    return MoneyAccount::create([
        'business_id' => $business->id, 'name' => 'Courier '.$business->slug, 'type' => MoneyAccountType::Courier,
        'provider' => 'carrybee', 'account_id' => Account::bySlug('courier_receivable')->id, 'is_active' => true,
    ]);
}

/** An account that passed a test (with two pickup stores) and is switched on. */
function cbrAccount(string $provider, string $label): CourierAccount
{
    $account = app(SaveCourierAccount::class)->handle(null, $provider === 'carrybee' ? [
        'provider' => 'carrybee', 'label' => $label, 'environment' => 'live', 'base_url' => CBR_CB,
        'credentials' => ['client_id' => 'cb-id-'.$label, 'client_secret' => 'cb-SECRET-1234', 'client_context' => 'cb-CONTEXT-5678'],
    ] : [
        'provider' => 'pathao', 'label' => $label, 'environment' => 'live', 'base_url' => CBR_PATHAO,
        'credentials' => ['client_id' => 'p-id', 'client_secret' => 'p-SECRET-1234', 'username' => 'ops@example.test', 'password' => 'p-PASSWORD-99'],
    ]);

    $account->forceFill([
        'last_test_at' => now(), 'last_test_ok' => true, 'tested_fingerprint' => $account->currentFingerprint(),
        'last_test_stores' => [['id' => '24869', 'name' => 'Adabor 2', 'active' => true], ['id' => '77001', 'name' => 'Bionic', 'active' => true], ['id' => '393838', 'name' => 'Main', 'active' => true]],
    ])->save();
    $account->forceFill(['is_active' => true, 'activated_at' => now()])->save();

    return $account->fresh(['provider']);
}

function cbrLink(CourierAccount $account, Business $business, MoneyAccount $money, string $store, ?int $brandId, bool $default = false): CourierAccountBusiness
{
    return CourierAccountBusiness::query()->withoutGlobalScopes()->create([
        'courier_account_id' => $account->id, 'business_id' => $business->id, 'brand_id' => $brandId,
        'provider_store_id' => $store, 'provider_store_name' => 'Store '.$store, 'money_account_id' => $money->id, 'is_default' => $default,
    ]);
}

function cbrChallan(object $t, ?Brand $brand): Delivery
{
    $order = app(CreateSalesOrder::class)->handle(
        customer: $t->customer,
        lines: [['product' => $t->product, 'quantity' => '2']],
        createdBy: $t->owner,
        origin: new SalesOrderOrigin(channel: SalesChannel::Internal),
        brandId: $brand?->id,
    );
    $order->address()->create([
        'business_id' => $order->business_id, 'recipient_name' => 'Karim Uddin', 'recipient_phone' => '01711000000',
        'district' => 'Dhaka', 'thana' => 'Dhanmondi', 'address_line' => 'House 4, Road 2, Jigatola',
    ]);

    return app(RecordDelivery::class)->handle(
        customer: $t->customer,
        warehouse: $t->warehouse,
        lines: [['product' => $t->product, 'quantity' => '2', 'orderLine' => $order->lines->first()]],
        createdBy: $t->owner,
        deliveredAt: '2026-10-11 09:00:00',
    );
}

/** @return array<int, array<string, mixed>> the booking form's accounts by id */
function cbrOptions(object $t, Delivery $delivery): array
{
    $orderId = $delivery->lines()->first()->orderLine->sales_order_id;

    return collect($t->actingAs($t->manager)->get("/sales/orders/{$orderId}")->viewData('page')['props']['courier_accounts'])
        ->keyBy('id')->all();
}

function cbrBook(object $t, Delivery $delivery, CourierAccount $account)
{
    return $t->actingAs($t->manager)->post("/sales/deliveries/{$delivery->id}/courier-booking", [
        'courier_account_id' => $account->id, 'cod_amount' => '1700',
    ]);
}

function cbrCarryBeeOk(Delivery $delivery): void
{
    Http::fake([CBR_CB.'/api/v2/orders' => Http::response(['error' => false, 'message' => 'ok', 'data' => ['order' => [
        'consignment_id' => 'FA1110ABCD', 'merchant_order_id' => $delivery->delivery_number, 'collectable_amount' => 1700, 'delivery_fee' => '90', 'cod_fee' => 8.5,
    ]]], 201)]);
}

it('pre-selects the brand\'s own account, shows the other brand\'s account disabled with the reason, and keeps any-brand Pathao', function () {
    $options = cbrOptions($this, cbrChallan($this, $this->lld));

    expect($options[$this->cbLaladia->id])->toMatchArray(['available' => true, 'preselected' => true, 'serves_brand' => 'LaLaDia', 'store_name' => 'Store 24869'])
        ->and($options[$this->cbBionic->id])->toMatchArray(['available' => false, 'preselected' => false, 'unavailable_reason' => 'Bionic carries Bionic-Garden parcels only, not LaLaDia.'])
        ->and($options[$this->pathao->id])->toMatchArray(['available' => true, 'preselected' => false, 'serves_brand' => null]);

    $options = cbrOptions($this, cbrChallan($this, $this->bnc));

    expect($options[$this->cbBionic->id])->toMatchArray(['available' => true, 'preselected' => true, 'store_name' => 'Store 77001'])
        ->and($options[$this->cbLaladia->id]['available'])->toBeFalse();
});

it('falls back to the any-brand default when the brand has no account of its own', function () {
    CourierAccountBusiness::query()->where('courier_account_id', $this->cbLaladia->id)->delete();

    $options = cbrOptions($this, cbrChallan($this, $this->lld));

    expect($options[$this->pathao->id])->toMatchArray(['available' => true, 'preselected' => true])
        ->and($options[$this->cbBionic->id]['available'])->toBeFalse();
});

it('books a brand\'s parcel from that brand\'s pickup store', function () {
    $delivery = cbrChallan($this, $this->bnc);
    cbrCarryBeeOk($delivery);

    cbrBook($this, $delivery, $this->cbBionic)->assertSessionHasNoErrors()->assertSessionHas('success');

    Http::assertSent(fn (Request $request) => $request->url() === CBR_CB.'/api/v2/orders' && $request->data()['store_id'] === '77001');
    expect(CourierBookingRequest::query()->sole()->courier_account_id)->toBe($this->cbBionic->id);
});

it('refuses on the server an account linked only to the other brand, before anything is written or sent', function () {
    $delivery = cbrChallan($this, $this->lld);
    Http::fake();

    cbrBook($this, $delivery, $this->cbBionic)
        ->assertSessionHasErrors(['courier_account_id' => 'Bionic carries Bionic-Garden parcels only, not LaLaDia.']);

    Http::assertNothingSent();
    expect(CourierBookingRequest::query()->count())->toBe(0);
});

it('uses an account\'s own link for the brand before its any-brand link', function () {
    // Pathao with a LaLaDia store of its own beside the any-brand one.
    cbrLink($this->pathao, $this->business, $this->money, '24869', $this->lld->id);

    expect($this->pathao->linkFor($this->business->id, $this->lld->id)->provider_store_id)->toBe('24869')
        ->and($this->pathao->linkFor($this->business->id, $this->bnc->id)->provider_store_id)->toBe('393838')
        ->and($this->pathao->linkFor($this->business->id)->provider_store_id)->toBe('393838');
});

it('books an order with no brand through an any-brand account or an account with one link, never a guessed one', function () {
    $options = cbrOptions($this, cbrChallan($this, null));

    // Each CarryBee account has one link: an order from before brands keeps working.
    expect($options[$this->cbLaladia->id]['available'])->toBeTrue()
        ->and($options[$this->pathao->id])->toMatchArray(['available' => true, 'preselected' => true]);

    // An account with two brand links and none for any brand cannot choose.
    cbrLink($this->cbLaladia, $this->business, $this->money, '77001', $this->bnc->id);
    $options = cbrOptions($this, cbrChallan($this, null));

    expect($options[$this->cbLaladia->id])->toMatchArray([
        'available' => false,
        'unavailable_reason' => 'LaLaDia carries Bionic-Garden and LaLaDia parcels only, and this order has no brand. Choose another account.',
    ]);
});

it('links an account for one brand from Settings, keeps one default per courier per brand, and stops one link only', function () {
    $owner = fn () => $this->actingAs($this->owner);

    // CarryBee LaLaDia also for any brand, as the default for any brand.
    $owner()->put("/settings/couriers/{$this->cbLaladia->id}/business", [
        'provider_store_id' => '24869', 'money_account_id' => $this->money->id, 'brand_id' => null, 'is_default' => true,
    ])->assertSessionHasNoErrors();

    // A second save for any brand updates that row, never a second any-brand row.
    $owner()->put("/settings/couriers/{$this->cbLaladia->id}/business", [
        'provider_store_id' => '393838', 'money_account_id' => $this->money->id, 'brand_id' => null, 'is_default' => true,
    ])->assertSessionHasNoErrors();

    $links = CourierAccountBusiness::query()->where('courier_account_id', $this->cbLaladia->id)->get()->keyBy(fn ($l) => $l->brand_id ?? 'any');
    expect($links)->toHaveCount(2)
        ->and($links['any']->provider_store_id)->toBe('393838')
        ->and($links['any']->is_default)->toBeTrue()
        ->and($links[$this->lld->id]->is_default)->toBeTrue();

    // The Bionic default is untouched: another brand's bucket. Pathao's is not
    // CarryBee's bucket at all.
    expect(CourierAccountBusiness::query()->where('courier_account_id', $this->cbBionic->id)->sole()->is_default)->toBeTrue()
        ->and(CourierAccountBusiness::query()->where('courier_account_id', $this->pathao->id)->sole()->is_default)->toBeTrue();

    // Making CarryBee Bionic the Bionic default again clears nothing else.
    $owner()->put("/settings/couriers/{$this->cbBionic->id}/business", [
        'provider_store_id' => '77001', 'money_account_id' => $this->money->id, 'brand_id' => $this->bnc->id, 'is_default' => true,
    ])->assertSessionHasNoErrors();
    expect($links[$this->lld->id]->fresh()->is_default)->toBeTrue();

    // A second CarryBee LaLaDia default takes the LaLaDia bucket over.
    $owner()->put("/settings/couriers/{$this->cbBionic->id}/business", [
        'provider_store_id' => '24869', 'money_account_id' => $this->money->id, 'brand_id' => $this->lld->id, 'is_default' => true,
    ])->assertSessionHasNoErrors();
    expect($links[$this->lld->id]->fresh()->is_default)->toBeFalse()
        ->and($links['any']->fresh()->is_default)->toBeTrue();

    $owner()->get('/settings/couriers')->assertInertia(fn (Assert $page) => $page->has('brands', 2)
        ->where('accounts', fn ($accounts) => collect($accounts)->firstWhere('id', $this->cbLaladia->id)['links'][0]['brand_name'] === 'LaLaDia'));

    // Stop the any-brand link only.
    $owner()->delete("/settings/couriers/{$this->cbLaladia->id}/business?brand=any")->assertSessionHasNoErrors();
    expect(CourierAccountBusiness::query()->where('courier_account_id', $this->cbLaladia->id)->pluck('brand_id')->all())->toBe([$this->lld->id]);
});

it('refuses to link an account to a brand of another business', function () {
    $other = cbrBusiness('Other Co', 'other');
    $foreign = Brand::create(['business_id' => $other->id, 'code' => 'XYZ', 'name' => 'Foreign', 'slug' => 'foreign']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    $this->actingAs($this->owner)->put("/settings/couriers/{$this->pathao->id}/business", [
        'provider_store_id' => '393838', 'money_account_id' => $this->money->id, 'brand_id' => $foreign->id,
    ])->assertSessionHasErrors(['brand_id' => 'Choose a brand of this business, or Any brand.']);

    expect(CourierAccountBusiness::query()->where('brand_id', $foreign->id)->count())->toBe(0);
});

/* -------------------------------------------------------------------------
 | The data migration: CarryBee LaLaDia and CarryBee Bionic get their brands
 | ---------------------------------------------------------------------- */

function cbrRunBrandMigration(): void
{
    (require database_path('../app/Modules/Sales/database/migrations/2026_10_11_600001_carrybee_links_serve_their_own_brand.php'))->up();
}

it('gives the two CarryBee links their brands by label and leaves Pathao for any brand', function () {
    CourierAccountBusiness::query()->update(['brand_id' => null]);

    cbrRunBrandMigration();

    expect(CourierAccountBusiness::query()->where('courier_account_id', $this->cbLaladia->id)->sole()->brand_id)->toBe($this->lld->id)
        ->and(CourierAccountBusiness::query()->where('courier_account_id', $this->cbBionic->id)->sole()->brand_id)->toBe($this->bnc->id)
        ->and(CourierAccountBusiness::query()->where('courier_account_id', $this->pathao->id)->sole()->brand_id)->toBeNull();
});

it('matches the brands by name when the codes differ, in that business only', function () {
    CourierAccountBusiness::query()->update(['brand_id' => null]);
    $this->lld->update(['code' => 'L1']);
    $this->bnc->update(['code' => 'B1']);

    // Another business with the same two accounts and brands of its own.
    $other = cbrBusiness('Other Co', 'other');
    $otherLld = Brand::create(['business_id' => $other->id, 'code' => 'LLD', 'name' => 'LaLaDia', 'slug' => 'laladia']);
    $otherBnc = Brand::create(['business_id' => $other->id, 'code' => 'BNC', 'name' => 'Bionic-Garden', 'slug' => 'bionic-garden']);
    $otherMoney = cbrMoney($other);
    $a = cbrLink($this->cbLaladia, $other, $otherMoney, '24869', null);
    $b = cbrLink($this->cbBionic, $other, $otherMoney, '77001', null);
    app(BusinessContext::class)->set($this->business->id);

    cbrRunBrandMigration();

    $brandOf = fn (CourierAccount $account, Business $business) => DB::table('courier_account_business')
        ->where('courier_account_id', $account->id)->where('business_id', $business->id)->value('brand_id');

    expect($brandOf($this->cbLaladia, $this->business))->toBe($this->lld->id)
        ->and($brandOf($this->cbBionic, $this->business))->toBe($this->bnc->id)
        ->and((int) DB::table('courier_account_business')->where('id', $a->id)->value('brand_id'))->toBe($otherLld->id)
        ->and((int) DB::table('courier_account_business')->where('id', $b->id)->value('brand_id'))->toBe($otherBnc->id);
});

it('skips a business whose CarryBee links are not exactly the LaLaDia and Bionic pair', function (string $shape) {
    CourierAccountBusiness::query()->update(['brand_id' => null]);

    match ($shape) {
        'three links' => cbrLink(cbrAccount('carrybee', 'Spare'), $this->business, $this->money, '24869', null),
        'one label unclear' => $this->cbBionic->forceFill(['label' => 'Second account'])->save(),
        'a brand missing' => $this->bnc->update(['code' => 'X1', 'name' => 'Something else']),
        'already set' => CourierAccountBusiness::query()->where('courier_account_id', $this->cbLaladia->id)->update(['brand_id' => $this->lld->id]),
    };

    $before = DB::table('courier_account_business')->orderBy('id')->pluck('brand_id', 'id')->all();

    cbrRunBrandMigration();

    expect(DB::table('courier_account_business')->orderBy('id')->pluck('brand_id', 'id')->all())->toBe($before);
})->with(['three links', 'one label unclear', 'a brand missing', 'already set']);
