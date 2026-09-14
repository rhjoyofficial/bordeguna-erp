<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StorePaymentGateway;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How a shop takes payment online: the SSLCommerz settings (slice P2a).
 *
 * ⚠⚠ WRITES NEED `commerce.stores.publish`, THE OWNER'S PERMISSION. Switching a
 * shop onto taking card and wallet money is the same class of decision as putting
 * the shop live, and the merchant password is the most sensitive thing a store
 * holds. Managers can read the screen; they cannot change it.
 *
 * ⛔ The secret goes one way. The page is told only whether one is saved; a blank
 * field on save keeps it; removing it is an explicit, separate choice - the
 * MessageProvider contract, so a form re-saved without retyping cannot wipe it.
 *
 * ⚠ Switching on is refused while anything would stop a payment being recorded,
 * each reason named. A shop must never offer online payment and then fail after
 * the customer's money has been taken.
 */
class StorePaymentController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $this->authorize('commerce.stores.view');

        $gateway = $store->paymentGateway ?? new StorePaymentGateway([
            'store_id' => $store->id,
            'provider' => StorePaymentGateway::SSLCOMMERZ,
        ]);

        $gateway->business_id ??= $store->business_id;

        $domain = $store->primaryDomain()->value('hostname');

        return Inertia::render('commerce/stores/payments', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'order_prefix' => $store->order_prefix],
            'gateway' => [
                'is_enabled' => (bool) $gateway->is_enabled,
                'merchant_id' => $gateway->merchant_id,
                'has_secret' => $gateway->hasSecret(),
                'is_live' => (bool) $gateway->is_live,
                'money_account_id' => $gateway->money_account_id,
            ],
            'problems' => $gateway->problems(),
            'gateway_accounts' => MoneyAccount::query()
                ->where('type', MoneyAccountType::Gateway->value)
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (MoneyAccount $account) => ['id' => $account->id, 'name' => $account->name, 'is_active' => (bool) $account->is_active])
                ->values(),
            // What to paste into the SSLCommerz merchant panel as the IPN listener.
            'ipn_url' => $domain === null ? null : "https://{$domain}/payments/sslcommerz/ipn",
            'can_manage' => $request->user()->can('commerce.stores.publish'),
        ]);
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->authorize('commerce.stores.publish');

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'merchant_id' => ['nullable', 'string', 'max:100'],
            'merchant_secret' => ['nullable', 'string', 'max:255'],
            'clear_secret' => ['sometimes', 'boolean'],
            'is_live' => ['required', 'boolean'],
            'money_account_id' => ['nullable', 'integer'],
        ]);

        $gateway = $store->paymentGateway ?? new StorePaymentGateway([
            'store_id' => $store->id,
            'provider' => StorePaymentGateway::SSLCOMMERZ,
        ]);

        $gateway->business_id ??= $store->business_id;

        // ⚠ Only a payment gateway account of this business. The business scope
        // makes another business's account a "not found", never a silent link.
        if (($data['money_account_id'] ?? null) !== null) {
            $account = MoneyAccount::query()->find($data['money_account_id']);

            if ($account === null || ! $account->isGateway()) {
                throw ValidationException::withMessages([
                    'money_account_id' => 'Choose a payment gateway account of this business.',
                ]);
            }
        }

        $gateway->fill([
            'is_enabled' => (bool) $data['is_enabled'],
            'merchant_id' => ($data['merchant_id'] ?? null) === null ? null : trim($data['merchant_id']),
            'is_live' => (bool) $data['is_live'],
            'money_account_id' => $data['money_account_id'] ?? null,
        ]);

        // ⛔ Blank keeps what is saved. Removing it has to be asked for.
        if ($request->boolean('clear_secret')) {
            $gateway->merchant_secret = null;
        } elseif (($data['merchant_secret'] ?? null) !== null && $data['merchant_secret'] !== '') {
            $gateway->merchant_secret = $data['merchant_secret'];
        }

        // ⚠ ONE message carrying every reason: Inertia hands a page only the first
        // message per field, so a list would show the first missing thing and hide
        // the rest.
        if ($gateway->is_enabled && ($problems = $gateway->problems()) !== []) {
            throw ValidationException::withMessages(['is_enabled' => 'Online payment cannot be switched on yet. '.implode(' ', $problems)]);
        }

        $gateway->save();

        return back()->with('success', $gateway->is_enabled
            ? ($gateway->is_live ? 'Online payment is ON and LIVE: real cards and wallets are charged.' : 'Online payment is on, in sandbox: nothing real is charged.')
            : 'Online payment settings saved. Checkout does not offer it while it is off.');
    }
}
