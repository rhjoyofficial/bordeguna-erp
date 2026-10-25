<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;

/**
 * ⭐ Joy's answer 9 (25 Oct 2026): the business's SSL Commerz Clearing money
 * account, created the first time it is needed when the migration did not
 * (a business that started selling later, a chart that gained 1146 later).
 *
 * A gateway account under 1146 Receivable from Payment Gateways, marked
 * system_key = 'ssl_clearing'. Null when the chart has no 1146 yet: then
 * there is nowhere to hold the money and the screens say so.
 */
final class SslClearing
{
    public const KEY = 'ssl_clearing';

    public const NAME = 'SSL Commerz Clearing';

    public static function for(int $businessId): ?MoneyAccount
    {
        $existing = MoneyAccount::withoutGlobalScopes()->where('business_id', $businessId)->where('system_key', self::KEY)->first();

        if ($existing !== null) {
            return $existing;
        }

        $head = Account::bySlug('gateway_receivable') ?? Account::query()->where('code', '1146')->first();

        if ($head === null) {
            return null;
        }

        return MoneyAccount::withoutGlobalScopes()->create([
            'business_id' => $businessId,
            'name' => self::NAME,
            'type' => MoneyAccountType::Gateway->value,
            'system_key' => self::KEY,
            'account_id' => $head->id,
            'is_active' => true,
        ]);
    }
}
