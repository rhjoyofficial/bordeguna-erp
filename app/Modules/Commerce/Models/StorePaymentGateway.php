<?php

namespace App\Modules\Commerce\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a shop takes payment online (SSLCommerz slice P2a, Joy 13 September).
 *
 * ⚠⚠ THE MERCHANT SECRET IS ENCRYPTED AT REST, HIDDEN FROM SERIALISATION, AND NO
 * SCREEN EVER RECEIVES IT - only whether one is saved. It is deliberately not in
 * any activity log. A table of its own so the store, which a dozen screens
 * serialise, never carries it.
 *
 * ⚠ A secret that no longer decrypts (a database restored under a different
 * APP_KEY) reads as "not saved", never as an exception at checkout: the shop
 * stops offering online payment and the screen says why.
 */
class StorePaymentGateway extends Model
{
    use BelongsToBusiness;

    public const SSLCOMMERZ = 'sslcommerz';

    protected $fillable = [
        'store_id',
        'provider',
        'is_enabled',
        'merchant_id',
        'merchant_secret',
        'is_live',
        'money_account_id',
    ];

    protected $hidden = ['merchant_secret'];

    protected $attributes = [
        'is_enabled' => false,
        'is_live' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_live' => 'boolean',
            'merchant_secret' => 'encrypted',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    /**
     * The secret, or null when none is saved or it no longer decrypts.
     */
    public function secret(): ?string
    {
        if ($this->getRawOriginal('merchant_secret') === null && ! $this->isDirty('merchant_secret')) {
            return null;
        }

        try {
            $secret = $this->merchant_secret;
        } catch (DecryptException) {
            return null;
        }

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== null;
    }

    /**
     * Everything that stops this gateway taking a payment, as sentences a person
     * can act on. Empty means it can.
     *
     * ⚠ Checked again at checkout, never trusted from when it was saved: an
     * account switched off or a mapping removed next week must stop the shop
     * offering online payment, not fail after the customer has paid.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (trim((string) $this->merchant_id) === '') {
            $problems[] = 'Enter the SSLCommerz store id.';
        }

        if (! $this->hasSecret()) {
            $problems[] = 'Enter the SSLCommerz store password.';
        }

        $account = $this->money_account_id === null ? null : MoneyAccount::query()->find($this->money_account_id);
        $ledger = Account::bySlug('gateway_receivable');

        if ($account === null) {
            $problems[] = 'Choose the payment gateway account these payments land in (Accounting > Bank & cash, type Payment gateway).';
        } elseif (! $account->isGateway() || $account->business_id !== $this->business_id) {
            $problems[] = "{$account->name} is not a payment gateway account of this business.";
        } elseif (! $account->is_active) {
            $problems[] = "{$account->name} is switched off.";
        } elseif ($ledger === null || $account->account_id !== $ledger->id) {
            $problems[] = "{$account->name} must report under 1146 Receivable from Payment Gateways.";
        }

        if (AccountMapping::forKeyOn('sales.gateway_fees', Carbon::today()) === null) {
            $problems[] = 'Gateway fees have no ledger account mapped (sales.gateway_fees). Ask whoever keeps the books.';
        }

        return $problems;
    }

    /**
     * May this shop take a payment online right now?
     */
    public function isUsable(): bool
    {
        return $this->is_enabled && $this->problems() === [];
    }
}
