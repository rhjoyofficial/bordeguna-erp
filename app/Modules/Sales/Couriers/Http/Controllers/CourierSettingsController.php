<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Inventory\Models\Brand;
use App\Modules\Sales\Couriers\Actions\SaveCourierAccount;
use App\Modules\Sales\Couriers\Actions\TestCourierConnection;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Providers\CourierProviderDefinition;
use App\Modules\Sales\Couriers\Providers\CourierProviderRegistry;
use App\Modules\Sales\Support\OrderBrand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > Couriers (courier hub slice 1, 11 Oct 2026). Joy: "all settings
 * must be available on UI so I can change them anytime".
 *
 * ⚠⚠ CREDENTIALS ARE WRITE-ONLY FROM THIS SCREEN. The page is told which fields
 * hold a value, never the value. The webhook secret is shown once, straight
 * after it is made, and never again.
 *
 * ⚠ Owner only by default (`couriers.settings.manage`). Technical authority:
 * nothing here posts a journal or changes an invoice (ChatGPT risk 11).
 */
class CourierSettingsController extends Controller
{
    public function index(Request $request, CourierProviderRegistry $registry): Response
    {
        $businessId = app(BusinessContext::class)->id();

        // ⚠ Filtered by hand as well as by the global scope: this is the one
        // place the current business's links are read for display.
        $links = CourierAccountBusiness::query()->where('business_id', $businessId)
            ->orderByRaw('brand_id IS NULL')->orderBy('id')->get()->groupBy('courier_account_id');
        $brandNames = OrderBrand::names($businessId);

        $otherBusinesses = DB::table('courier_account_business')
            ->where('business_id', '!=', $businessId)
            ->selectRaw('courier_account_id, count(*) as n')
            ->groupBy('courier_account_id')
            ->pluck('n', 'courier_account_id');

        $accounts = CourierAccount::query()->with(['provider', 'creator:id,name'])
            ->join('courier_providers', 'courier_providers.id', '=', 'courier_accounts.courier_provider_id')
            ->orderBy('courier_providers.sort_order')->orderBy('courier_accounts.label')
            ->select('courier_accounts.*')
            ->get()
            ->map(function (CourierAccount $a) use ($links, $otherBusinesses, $brandNames) {
                $accountLinks = $links->get($a->id, collect());

                return [
                    'id' => $a->id,
                    'provider' => $a->provider->code,
                    'provider_name' => $a->provider->name,
                    'label' => $a->label,
                    'environment' => $a->environment,
                    'base_url' => $a->base_url,
                    'options' => $a->options ?? [],
                    // ⚠⚠ Booleans only. Never a value, never a mask.
                    'credentials_set' => $a->credentialsSet(),
                    'is_active' => $a->is_active,
                    'activated_at' => $a->activated_at?->toDateTimeString(),
                    'last_test_at' => $a->last_test_at?->toDateTimeString(),
                    'last_test_ok' => $a->last_test_ok,
                    'last_test_message' => $a->last_test_message,
                    'last_test_city_count' => $a->last_test_city_count,
                    'test_is_current' => $a->testIsCurrent(),
                    'can_activate' => $a->canActivate(),
                    'stores' => $a->testedStores(),
                    'webhook_url' => $a->webhookUrl(),
                    // ⚠ Whether it is set, never the value.
                    'integration_secret_set' => $a->integrationSecret() !== null,
                    'auto_post_outcomes' => (bool) $a->auto_post_outcomes,
                    'created_by' => $a->creator?->name,
                    'other_business_count' => (int) ($otherBusinesses[$a->id] ?? 0),
                    // ⭐ Joy, 11 Oct 2026: a link per brand it serves (null = any brand).
                    'links' => $accountLinks->map(fn (CourierAccountBusiness $link) => [
                        'id' => $link->id,
                        'brand_id' => $link->brand_id === null ? null : (int) $link->brand_id,
                        'brand_name' => $link->brand_id === null ? null : ($brandNames[(int) $link->brand_id] ?? null),
                        'provider_store_id' => $link->provider_store_id,
                        'provider_store_name' => $link->provider_store_name,
                        'money_account_id' => $link->money_account_id,
                        'is_default' => $link->is_default,
                    ])->values(),
                ];
            })->values();

        $revealed = $request->session()->get('courier_webhook_secret');

        return Inertia::render('settings/couriers', [
            'providers' => array_values(array_map(fn (CourierProviderDefinition $p) => $p->toArray(), $registry->all())),
            'accounts' => $accounts,
            'moneyAccounts' => MoneyAccount::query()
                ->where('business_id', $businessId)
                ->where('type', MoneyAccountType::Courier->value)
                ->active()->withLedger()->orderBy('name')->get()
                ->map(fn (MoneyAccount $m) => ['id' => $m->id, 'label' => $m->label()])->values(),
            'brands' => OrderBrand::options($businessId),
            // Flashed by rotateSecret, so it is on this one page load only.
            'revealedSecret' => is_array($revealed) ? $revealed : null,
        ]);
    }

    public function store(Request $request, SaveCourierAccount $save): RedirectResponse
    {
        $account = $save->handle(null, $request->only(['provider', 'label', 'environment', 'base_url', 'credentials', 'options', 'integration_secret']), $request->user(), requireCredentials: true);

        return back()->with('success', "{$account->label} saved and left switched off. Test the connection, then activate it.");
    }

    public function update(Request $request, CourierAccount $courierAccount, SaveCourierAccount $save): RedirectResponse
    {
        $wasActive = $courierAccount->is_active;

        $account = $save->handle($courierAccount, $request->only(['label', 'environment', 'base_url', 'credentials', 'options', 'integration_secret']), $request->user());

        return back()->with('success', $wasActive && ! $account->is_active
            ? "{$account->label} saved. The credentials or address changed, so it is switched off until it passes a new test."
            : "{$account->label} saved.");
    }

    public function test(CourierAccount $courierAccount, TestCourierConnection $test): RedirectResponse
    {
        $result = $test->handle($courierAccount);

        return $result->ok
            ? back()->with('success', $result->message)
            : back()->with('error', 'Connection test failed: '.$result->message);
    }

    public function activate(CourierAccount $courierAccount): RedirectResponse
    {
        if (! $courierAccount->canActivate()) {
            throw ValidationException::withMessages([
                'activate' => 'Run Test connection with the current credentials first. It has to pass before the account can be switched on.',
            ]);
        }

        $courierAccount->forceFill(['is_active' => true, 'activated_at' => now()])->save();

        return back()->with('success', "{$courierAccount->label} is active.");
    }

    public function deactivate(CourierAccount $courierAccount): RedirectResponse
    {
        $courierAccount->forceFill(['is_active' => false, 'activated_at' => null])->save();

        return back()->with('success', "{$courierAccount->label} is switched off.");
    }

    /**
     * The automatic money switch (ruling 1). ⚠ It can only be turned OFF, the
     * emergency stop: turning it on waits for the evidence period and its exit
     * criteria (reconciliation accuracy, exception rate), and nothing reads it
     * yet. Every parcel's money is confirmed by a person.
     */
    public function autoPost(Request $request, CourierAccount $courierAccount): RedirectResponse
    {
        $on = $request->boolean('on');

        if ($on) {
            throw ValidationException::withMessages([
                'auto_post_outcomes' => 'Automatic posting is available after the evidence period. Until then a person confirms every parcel.',
            ]);
        }

        $courierAccount->forceFill(['auto_post_outcomes' => false, 'auto_post_switched_off_at' => now()])->save();

        activity('couriers')->performedOn($courierAccount)->causedBy($request->user())->log('automatic outcome posting switched off');

        return back()->with('success', "Automatic posting is off for {$courierAccount->label}. A person confirms every parcel.");
    }

    /**
     * A new webhook secret, shown ONCE on the next page load and never again.
     */
    public function rotateSecret(CourierAccount $courierAccount): RedirectResponse
    {
        $secret = CourierAccount::newWebhookSecret();
        $courierAccount->forceFill(['webhook_secret' => $secret])->save();

        activity('couriers')->performedOn($courierAccount)->causedBy(request()->user())->log('webhook secret rotated');

        return back()
            ->with('courier_webhook_secret', ['account_id' => $courierAccount->id, 'secret' => $secret])
            ->with('success', 'New webhook secret made. Copy it now: it will not be shown again.');
    }

    /**
     * Link the account to the CURRENT business with a pickup store and the
     * business's courier money account.
     */
    public function linkBusiness(Request $request, CourierAccount $courierAccount): RedirectResponse
    {
        $businessId = app(BusinessContext::class)->id();
        $storeIds = array_column($courierAccount->testedStores(), 'id');

        if ($storeIds === []) {
            throw ValidationException::withMessages([
                'provider_store_id' => 'Run Test connection first: the pickup stores come from the courier.',
            ]);
        }

        $data = $request->validate([
            'provider_store_id' => ['required', 'string', Rule::in($storeIds)],
            'money_account_id' => ['required', 'integer'],
            'is_default' => ['boolean'],
            // Null = any brand. Checked below: a brand of THIS business.
            'brand_id' => ['nullable', 'integer'],
        ], [
            'provider_store_id.in' => 'Choose one of the stores the courier returned in the last test.',
        ]);

        // ⚠ A courier money account of THIS business: never another business's
        // 1145, never a bank or a till.
        $money = MoneyAccount::query()->where('business_id', $businessId)->whereKey($data['money_account_id'])->first();

        if ($money === null || ! $money->isCourier()) {
            throw ValidationException::withMessages([
                'money_account_id' => 'Choose a courier money account of this business (Accounting > Bank & cash, type Courier).',
            ]);
        }

        if (! $money->is_active || $money->ledgerAccountId() === null) {
            throw ValidationException::withMessages([
                'money_account_id' => "{$money->name} is switched off or has no ledger head.",
            ]);
        }

        $brandId = isset($data['brand_id']) ? (int) $data['brand_id'] : null;

        if ($brandId !== null && ! Brand::query()->where('business_id', $businessId)->whereKey($brandId)->exists()) {
            throw ValidationException::withMessages(['brand_id' => 'Choose a brand of this business, or Any brand.']);
        }

        $store = collect($courierAccount->testedStores())->firstWhere('id', $data['provider_store_id']);
        $isDefault = (bool) ($data['is_default'] ?? false);

        DB::transaction(function () use ($courierAccount, $businessId, $brandId, $data, $store, $money, $isDefault, $request) {
            if ($isDefault) {
                // ⭐ One default per courier, per business, PER BRAND (any brand
                // being its own bucket): the CarryBee LaLaDia and CarryBee Bionic
                // accounts are both defaults, each for its own brand.
                $sameProvider = CourierAccount::query()->where('courier_provider_id', $courierAccount->courier_provider_id)->pluck('id');

                CourierAccountBusiness::query()->where('business_id', $businessId)
                    ->whereIn('courier_account_id', $sameProvider)
                    ->when($brandId === null, fn ($q) => $q->whereNull('brand_id'), fn ($q) => $q->where('brand_id', $brandId))
                    ->where('courier_account_id', '!=', $courierAccount->id)
                    ->update(['is_default' => false]);
            }

            // ⚠ Keyed on the brand too, null included: updateOrCreate asks
            // `brand_id IS NULL` for any brand, so there is never a second
            // any-brand link for one account here (MySQL would allow one).
            CourierAccountBusiness::query()->updateOrCreate(
                ['courier_account_id' => $courierAccount->id, 'business_id' => $businessId, 'brand_id' => $brandId],
                [
                    'provider_store_id' => $data['provider_store_id'],
                    'provider_store_name' => mb_substr((string) ($store['name'] ?? ''), 0, 150),
                    'money_account_id' => $money->id,
                    'is_default' => $isDefault,
                    'created_by' => $request->user()->id,
                ],
            );
        });

        $for = $brandId === null ? 'every brand' : (string) Brand::query()->whereKey($brandId)->value('name');

        return back()->with('success', "{$courierAccount->label} now serves this business from {$store['name']}, for {$for}.");
    }

    /**
     * Stop one link: the one for `brand` (a brand id, or "any"). Without it,
     * every link of the account in this business, as before brands.
     */
    public function unlinkBusiness(Request $request, CourierAccount $courierAccount): RedirectResponse
    {
        $brand = $request->input('brand');

        CourierAccountBusiness::query()
            ->where('business_id', app(BusinessContext::class)->id())
            ->where('courier_account_id', $courierAccount->id)
            ->when($brand === 'any', fn ($q) => $q->whereNull('brand_id'))
            ->when(is_scalar($brand) && ctype_digit((string) $brand), fn ($q) => $q->where('brand_id', (int) $brand))
            ->delete();

        return back()->with('success', "{$courierAccount->label} no longer serves this business".($brand === null ? '.' : ' for that brand.'));
    }
}
