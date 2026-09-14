<?php

use App\Models\User;
use App\Modules\Accounting\database\seeders\ChartOfAccountsSeeder;
use App\Modules\Accounting\Models\Account;
use App\Modules\Commerce\database\seeders\CommercePermissionsSeeder;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreDomain;
use App\Modules\Commerce\Models\StorePaymentGateway;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Sales\database\seeders\SalesAccountMappingSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * How a shop takes payment online: the SSLCommerz settings (slice P2a).
 *
 * ⛔ The store password is encrypted at rest and never reaches a page. Writing
 * the settings is the Owner's permission.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Bor de Guna', 'slug' => 'bor-de-guna']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    foreach (['Owner', 'Manager', 'Staff'] as $name) {
        Role::firstOrCreate(['name' => $name, 'business_id' => $this->business->id, 'guard_name' => 'web']);
    }

    (new CommercePermissionsSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new SalesAccountMappingSeeder)->run();
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->asRole = function (string $role) {
        $user = User::factory()->create();
        $user->businesses()->attach($this->business->id);
        $user->assignRole($role);

        return $this->actingAs($user)->withSession(['current_business_id' => $this->business->id]);
    };

    $this->store = Store::create(['key' => 'bionic', 'name' => 'Bionic Garden', 'order_prefix' => 'BNC']);
    StoreDomain::create(['store_id' => $this->store->id, 'hostname' => 'bionic.garden', 'is_primary' => true]);

    $this->gatewayAccount = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'SSLCommerz', 'type' => MoneyAccountType::Gateway,
        'provider' => 'SSLCommerz', 'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true,
    ]);

    $this->props = fn () => ($this->asRole)('Manager')->get("/commerce/stores/{$this->store->id}/payments")
        ->assertOk()->original->getData()['page']['props'];

    $this->save = fn (array $data, string $role = 'Owner') => ($this->asRole)($role)
        ->put("/commerce/stores/{$this->store->id}/payments", array_merge([
            'is_enabled' => false,
            'merchant_id' => 'bionic0test',
            'merchant_secret' => '',
            'is_live' => false,
            'money_account_id' => $this->gatewayAccount->id,
        ], $data));
});

it('saves the store password encrypted and never sends it to a page', function () {
    ($this->save)(['merchant_secret' => 'sandbox-secret@ssl', 'is_enabled' => true])->assertSessionHasNoErrors();

    $gateway = StorePaymentGateway::query()->sole();
    $raw = DB::table('store_payment_gateways')->value('merchant_secret');

    expect($gateway->secret())->toBe('sandbox-secret@ssl')
        ->and($raw)->not->toContain('sandbox-secret')
        ->and($gateway->toArray())->not->toHaveKey('merchant_secret')
        ->and($gateway->isUsable())->toBeTrue()
        ->and($this->store->fresh()->takesOnlinePayment())->toBeTrue();

    $props = ($this->props)();

    expect(json_encode($props))->not->toContain('sandbox-secret')
        ->and($props['gateway']['has_secret'])->toBeTrue()
        ->and($props['problems'])->toBe([])
        ->and($props['ipn_url'])->toBe('https://bionic.garden/payments/sslcommerz/ipn')
        ->and($props['can_manage'])->toBeFalse();
});

it('keeps the saved password when the form is saved blank, and removes it only when asked', function () {
    ($this->save)(['merchant_secret' => 'sandbox-secret@ssl']);
    ($this->save)(['merchant_id' => 'bionic1test', 'merchant_secret' => ''])->assertSessionHasNoErrors();

    expect(StorePaymentGateway::query()->sole()->secret())->toBe('sandbox-secret@ssl')
        ->and(StorePaymentGateway::query()->sole()->merchant_id)->toBe('bionic1test');

    ($this->save)(['clear_secret' => true])->assertSessionHasNoErrors();

    expect(StorePaymentGateway::query()->sole()->hasSecret())->toBeFalse();
});

it('refuses to switch on while anything would stop a payment being recorded, naming each', function () {
    // One message with every reason: a page only ever sees the first per field.
    ($this->save)(['is_enabled' => true, 'merchant_id' => '', 'money_account_id' => null])
        ->assertSessionHasErrors(['is_enabled' => 'Online payment cannot be switched on yet. Enter the SSLCommerz store id. Enter the SSLCommerz store password. '
            .'Choose the payment gateway account these payments land in (Accounting > Bank & cash, type Payment gateway).']);

    expect(StorePaymentGateway::query()->count())->toBe(0);

    // Saved but off is fine: somebody can fill it in over two sittings.
    ($this->save)(['is_enabled' => false, 'merchant_id' => '', 'money_account_id' => null])->assertSessionHasNoErrors();
});

it('stops offering online payment when the account behind it is switched off later', function () {
    ($this->save)(['merchant_secret' => 'sandbox-secret@ssl', 'is_enabled' => true])->assertSessionHasNoErrors();

    $this->gatewayAccount->update(['is_active' => false]);

    expect($this->store->fresh()->takesOnlinePayment())->toBeFalse()
        ->and(($this->props)()['problems'])->toBe(['SSLCommerz is switched off.']);
});

it('accepts only a payment gateway account of this business', function () {
    $cash = MoneyAccount::create([
        'business_id' => $this->business->id, 'name' => 'Cash box', 'type' => MoneyAccountType::Cash,
        'account_id' => Account::bySlug('cash_in_hand')->id, 'is_active' => true,
    ]);

    ($this->save)(['money_account_id' => $cash->id])
        ->assertSessionHasErrors(['money_account_id' => 'Choose a payment gateway account of this business.']);

    $other = Business::create(['name' => 'Other Co', 'slug' => 'other-co']);
    $foreign = MoneyAccount::withoutGlobalScopes()->create([
        'business_id' => $other->id, 'name' => 'Their SSLCommerz', 'type' => MoneyAccountType::Gateway,
        'account_id' => Account::bySlug('gateway_receivable')->id, 'is_active' => true,
    ]);

    ($this->save)(['money_account_id' => $foreign->id])
        ->assertSessionHasErrors(['money_account_id' => 'Choose a payment gateway account of this business.']);
});

it('lets a Manager read the settings and only the Owner change them', function () {
    ($this->save)(['merchant_secret' => 'x'], 'Manager')->assertForbidden();
    ($this->save)(['merchant_secret' => 'x'], 'Staff')->assertForbidden();

    expect(StorePaymentGateway::query()->count())->toBe(0);
});

it('reads a password that no longer decrypts as not saved, never as an error at checkout', function () {
    ($this->save)(['merchant_secret' => 'sandbox-secret@ssl', 'is_enabled' => true]);

    // A restore under a different APP_KEY.
    DB::table('store_payment_gateways')->update(['merchant_secret' => 'not-a-valid-payload']);

    $gateway = StorePaymentGateway::query()->sole();

    expect($gateway->hasSecret())->toBeFalse()
        ->and($this->store->fresh()->takesOnlinePayment())->toBeFalse()
        ->and(($this->props)()['problems'])->toBe(['Enter the SSLCommerz store password.']);
});
