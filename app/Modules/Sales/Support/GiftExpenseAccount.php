<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Support\Carbon;

/**
 * ⭐ B18 (Joy's initial plan R91, 25 Oct 2026): "gifts can be add with it". A
 * gift line is sold at zero, and the goods it gives away are a promotion cost,
 * not a cost of goods SOLD: left in COGS they would read as a margin loss on
 * the product.
 *
 * Where the cost goes: the mapping `sales.gift_expense` when somebody set one,
 * else 6331 Customer Gift (the master chart's head under 6330 Gifts and
 * Promotional), created under 6330, or 6000, when the chart lacks it.
 *
 * ⚠ Created, never re-pointed: a 6331 somebody renamed is still the head used.
 */
final class GiftExpenseAccount
{
    public const MAPPING = 'sales.gift_expense';

    public const CODE = '6331';

    public static function resolve(Carbon $on): Account
    {
        $mapped = AccountMapping::forKeyOn(self::MAPPING, $on)?->account;

        if ($mapped !== null) {
            return $mapped;
        }

        $existing = Account::query()->where('code', self::CODE)->first();

        if ($existing !== null) {
            return $existing;
        }

        $parent = Account::query()->where('code', '6330')->first() ?? Account::query()->where('code', '6000')->first();

        $account = new Account([
            'code' => self::CODE,
            'name' => 'Customer Gift',
            'type' => AccountType::Expense,
            'parent_id' => $parent?->id,
            'is_postable' => true,
            'note' => 'The cost of goods given free on a sales order (gift lines).',
        ]);
        $account->is_active = true;
        $account->save();

        return $account;
    }
}
