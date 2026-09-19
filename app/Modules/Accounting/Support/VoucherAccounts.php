<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Which ledger heads a voucher LINE may name, and which of them carry a party.
 *
 * ⚠⚠ THREE KINDS OF ACCOUNT ARE REFUSED, each for its own reason:
 *
 *  1. Accounts a subsystem owns (AR, AP, stock, payroll...). Their balances are
 *     explained document by document - ageing reads invoices, not the ledger -
 *     so a voucher line there makes a balance no document accounts for. The
 *     same rule the manual journal has always had (SubsystemAccounts).
 *  2. The ledger accounts behind money accounts (cash, bank, bKash, courier,
 *     gateway). Money moves through the MONEY ACCOUNT field, which stamps the
 *     journal line with which till or bank it was; a bare line on 1120 would
 *     move "bank" without saying which bank, and the cash book would lose it.
 *  3. A few heads that belong to one process each (RESERVED_SLUGS).
 *
 * ⚠⚠ MONEY WITH A CUSTOMER OR SUPPLIER BUT NO DOCUMENT IS AN ADVANCE, never raw
 * AR/AP (plan decision 6). The two advance accounts are the only party-enabled
 * heads: a line there MUST name its supplier or customer, and a line anywhere
 * else must not - a customer on "Office Rent" means nothing.
 */
final class VoucherAccounts
{
    private const RESERVED_SLUGS = ['employee_reimbursements_payable', 'opening_balance_equity', 'retained_earnings'];

    private const MONEY_SLUGS = ['cash_in_hand', 'cash_at_bank', 'mobile_financial_services', 'courier_receivable', 'gateway_receivable'];

    /** slug => the party class a line on that account must carry */
    public const PARTY_ACCOUNTS = [
        'supplier_advances' => Supplier::class,
        'customer_advances' => Customer::class,
    ];

    /**
     * @return Collection<int, Account>
     */
    public static function selectable(Carbon $on): Collection
    {
        $refused = self::refusedIds($on);

        return Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->inChartOrder()
            ->get()
            ->reject(fn (Account $account) => isset($refused[$account->id]))
            ->values();
    }

    /** Why this account cannot sit on a voucher line, or null when it can. */
    public static function refusal(Account $account, Carbon $on): ?string
    {
        if (! $account->is_postable || ! $account->is_active) {
            return "{$account->label()} cannot be posted to.";
        }

        return self::refusedIds($on)[$account->id] ?? null;
    }

    /** The party class a line on this account must carry, or null for none. */
    public static function partyClassFor(Account $account): ?string
    {
        return self::PARTY_ACCOUNTS[$account->system_slug] ?? null;
    }

    /**
     * @return array<int, string> account id => the reason it is refused
     */
    private static function refusedIds(Carbon $on): array
    {
        $refused = [];

        foreach (SubsystemAccounts::on($on) as $accountId => $key) {
            $module = ucfirst(strtok($key, '.'));
            $refused[$accountId] = "This account belongs to {$module} and is posted only by its own documents.";
        }

        // ⚠ Every business's money accounts, not only this one's: the chart is
        // shared, so 1120 is "a bank ledger" whichever business asks.
        foreach (MoneyAccount::withoutGlobalScopes()->whereNotNull('account_id')->distinct()->pluck('account_id') as $accountId) {
            $refused[$accountId] = 'Cash and bank move through the Paid from / Received into field, not a line.';
        }

        // The chart's own cash and bank heads, even before a money account has
        // been tied to them: a line there is still money with no till named.
        foreach (Account::query()->whereIn('system_slug', self::MONEY_SLUGS)->pluck('id') as $accountId) {
            $refused[$accountId] = 'Cash and bank move through the Paid from / Received into field, not a line.';
        }

        foreach (Account::query()->whereIn('system_slug', self::RESERVED_SLUGS)->pluck('id') as $accountId) {
            $refused[$accountId] = 'This account is kept by the system and cannot be used on a voucher.';
        }

        return $refused;
    }
}
