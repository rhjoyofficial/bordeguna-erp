<?php

namespace App\Modules\Sales\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountCodes;

/**
 * ⭐ The two heads commission posts to (Joy's Q18, 25 Oct 2026), planted the
 * first time they are needed, never renamed or adopted afterwards:
 *
 *   Sales Commission     expense, slug sales_commission, under 6400 Customer
 *                        Service and Sales Adjustment (the chart has no
 *                        "selling expenses" group; 6400 is where selling
 *                        costs already live), else under 6000
 *   Commission Payable   liability, slug commission_payable, under 2100
 *                        Current Liabilities. ONE head for everybody, with the
 *                        PERSON on every line (employee, customer or supplier),
 *                        so "Commission Payable > Rahim" is Rahim's ledger, as
 *                        "1170 > Musa" is Musa's
 *
 * ⚠ The chart is shared by every business, so a head planted for one is the
 * same head for all, as every seeded head is.
 */
final class CommissionHeads
{
    public static function expense(): Account
    {
        return self::plant('sales_commission', 'Sales Commission', AccountType::Expense, ['6400', '6000']);
    }

    public static function payable(): Account
    {
        return self::plant('commission_payable', 'Commission Payable', AccountType::Liability, ['2100', '2000']);
    }

    /** @param  list<string>  $parents  the parent codes to try, in order */
    private static function plant(string $slug, string $name, AccountType $type, array $parents): Account
    {
        if (($existing = Account::bySlug($slug)) !== null) {
            return $existing;
        }

        $parent = null;
        foreach ($parents as $code) {
            if (($parent = Account::query()->where('code', $code)->first()) !== null) {
                break;
            }
        }

        $account = new Account([
            'code' => AccountCodes::nextUnder($parent, $type),
            'name' => $name,
            'type' => $type,
            'parent_id' => $parent?->id,
            'is_postable' => true,
            'is_active' => true,
            'note' => 'Planted by commission agreements (Joy, Q18).',
        ]);
        $account->forceFill(['system_slug' => $slug]);

        AccountCodes::saveResolvingCode($account);

        return $account->fresh();
    }
}
