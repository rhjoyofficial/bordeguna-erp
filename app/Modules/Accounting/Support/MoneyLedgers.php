<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ⚠⚠ EVERY CASH BOX, BANK ACCOUNT AND MOBILE WALLET HAS ITS OWN LEDGER HEAD, and
 * every such head belongs to exactly one of them (21 Sep 2026, Joy: "track the
 * bank related things"; settled with ChatGPT the same day).
 *
 * Before this, BRAC Bank and any second bank both reported under 1120 Cash at
 * Bank, and bKash, Nagad and Rocket all under 1130, so the trial balance, the
 * balance sheet, the general ledger and the cash and bank book could not say
 * how much was in which one. The chart now holds a group per kind and one head
 * per account under it:
 *
 *   1110 Cash in Hand               11101 Cash in Hand (Uttar Adabor Till) - Bor de Guna
 *   1120 Bank Accounts              11201 BRAC Bank - Bor de Guna
 *   1130 Mobile Financial Services  11301 bKash - Bor de Guna, 11302 Nagad - ...
 *
 * ⚠ The head is created BY THE SYSTEM when the money account is saved, never
 * picked. A picker would let a head be named generically, mapped wrongly or
 * reused, which is exactly the sharing this replaces.
 *
 * ⚠ The hierarchy is parent_id, never the digits: the five-digit codes are what
 * a person reads (1120 Bank Accounts holds 11201 to 11299), and nothing here
 * derives a parent from a code except the choice of the next free code.
 *
 * ⚠ Courier and payment-gateway accounts are NOT money heads: they stay pinned
 * to 1145 and 1146, receivables shared by design and explained by their own
 * statements.
 */
final class MoneyLedgers
{
    /**
     * money account type => [group slug, group name, the kind's first head slug, that head's name on a fresh install]
     *
     * ⚠ The first-head slugs are the three the chart has always carried. They
     * stay on the first head of each kind, so every flow and test that asked for
     * "the bank head" by slug still finds a postable account.
     */
    public const KINDS = [
        'cash' => ['cash_accounts', 'Cash in Hand', 'cash_in_hand', 'Main Cash'],
        'bank' => ['bank_accounts', 'Bank Accounts', 'cash_at_bank', 'Main Bank Account'],
        'mfs' => ['mfs_accounts', 'Mobile Financial Services', 'mobile_financial_services', 'Main Mobile Wallet'],
        // ⭐ Initial plan B3 (25 Oct 2026): 1135 Cash with Employees, one head
        // per employee cash account. No placeholder first head: every one is
        // somebody's, so each gets a new head.
        'employee_cash' => ['employee_cash_accounts', 'Cash with Employees', null, null],
    ];

    /** The kinds that get a head of their own. */
    public static function hasOwnLedger(MoneyAccountType $type): bool
    {
        return isset(self::KINDS[$type->value]);
    }

    public static function groupFor(MoneyAccountType $type): ?Account
    {
        $kind = self::KINDS[$type->value] ?? null;

        return $kind === null ? null : Account::bySlug($kind[0]);
    }

    /**
     * @return list<int> the ids of the three group headings that exist
     */
    public static function groupIds(): array
    {
        return Account::query()
            ->whereIn('system_slug', array_column(self::KINDS, 0))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The ledger heads money in transit sits on rather than in hand: 1145
     * Receivable from Couriers and 1146 Receivable from Payment Gateways.
     */
    public const IN_TRANSIT_SLUGS = ['courier_receivable', 'gateway_receivable'];

    /**
     * One business's journal lines on its cash, bank and MFS heads: the money it
     * actually holds and can spend today.
     *
     * ⚠⚠ NOT "every line with a money account". Courier and gateway accounts are
     * money accounts too, and their lines carry a money_account_id, but cash a
     * courier has collected and not paid out (1145), or a gateway has taken and
     * not settled (1146), is a receivable: it cannot pay a supplier this morning.
     * Counting it as cash overstated the cockpit, the forecast's opening balance
     * and the morning briefing (Slice 0, D2).
     *
     * ⚠ Read from the ledger head, so a line on a bank head is cash whether or
     * not it named its money account, which is what the balance sheet says.
     */
    public static function cashLines(int $businessId): QueryBuilder
    {
        return DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.business_id', $businessId)
            ->whereIn('accounts.parent_id', self::groupIds());
    }

    /** One business's lines on 1145 and 1146: money with couriers and gateways, owed to it. */
    public static function inTransitLines(int $businessId): QueryBuilder
    {
        return DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.business_id', $businessId)
            ->whereIn('accounts.system_slug', self::IN_TRANSIT_SLUGS);
    }

    /** What one business holds in cash, bank and MFS right now, in paisa. */
    public static function cashMinor(int $businessId): int
    {
        return (int) self::cashLines($businessId)->sum('journal_lines.amount_minor');
    }

    /** What couriers and gateways hold for one business right now, in paisa. */
    public static function inTransitMinor(int $businessId): int
    {
        return (int) self::inTransitLines($businessId)->sum('journal_lines.amount_minor');
    }

    /** True for a head sitting directly under one of the three money groups. */
    public static function isMoneyHead(Account $account): bool
    {
        return $account->parent_id !== null && in_array((int) $account->parent_id, self::groupIds(), true);
    }

    /**
     * Every money account, in any business, that reports under this head.
     *
     * @return Collection<int, MoneyAccount>
     */
    public static function ownersOf(int $accountId): Collection
    {
        return MoneyAccount::withoutGlobalScopes()->where('account_id', $accountId)->orderBy('id')->get();
    }

    /**
     * How the head reads in the shared chart: the account's own name, then its
     * business, because four businesses see the same chart and two of them may
     * both bank with BRAC.
     */
    public static function headName(MoneyAccount $moneyAccount): string
    {
        $business = Business::withoutGlobalScopes()->find($moneyAccount->business_id);

        return $business === null
            ? $moneyAccount->name
            : "{$moneyAccount->name} - {$business->name}";
    }

    /**
     * Give a cash, bank or MFS money account the head it reports under, and keep
     * that head's name and place matching the account. Returns null for a courier
     * or gateway account, which has no head of its own.
     *
     * - Already on a head of its own under the right group: renamed if the
     *   account was renamed, nothing else.
     * - On a head of the wrong kind (its type was changed before anything was
     *   posted): the head moves to the right group with a new code.
     * - Otherwise: it claims its kind's first head if nothing uses that head yet
     *   (a fresh install), or gets a new head with the next free code.
     *
     * ⚠ The caller refuses a type change once the account has postings; this
     * method assumes that has already been checked.
     */
    public static function ensureOwnLedger(MoneyAccount $moneyAccount): ?Account
    {
        if (! self::hasOwnLedger($moneyAccount->type)) {
            return null;
        }

        $group = self::groupFor($moneyAccount->type);

        if ($group === null) {
            throw new RuntimeException('The chart has no '.self::KINDS[$moneyAccount->type->value][1].' group. Run the chart of accounts seeder.');
        }

        return DB::transaction(function () use ($moneyAccount, $group) {
            $current = $moneyAccount->account_id === null ? null : Account::find($moneyAccount->account_id);
            $ownsCurrent = $current !== null
                && self::ownersOf($current->id)->every(fn (MoneyAccount $owner) => $owner->id === $moneyAccount->id);

            if ($current !== null && $ownsCurrent && self::isMoneyHead($current)) {
                if ((int) $current->parent_id !== $group->id) {
                    $current->code = self::nextCode($group);
                    $current->parent_id = $group->id;
                }

                $current->name = self::headName($moneyAccount);
                $current->save();

                return $current;
            }

            $head = self::claimableFirstHead($moneyAccount->type, $group) ?? new Account([
                'code' => self::nextCode($group),
                'type' => AccountType::Asset,
                'parent_id' => $group->id,
                'is_postable' => true,
            ]);

            $head->name = self::headName($moneyAccount);
            $head->is_active = true;
            $head->save();

            $moneyAccount->account_id = $head->id;
            $moneyAccount->save();

            return $head;
        });
    }

    /**
     * The next free code under a money group: the group's code without its
     * trailing zero, and two digits. Under 1120 that is 11201 to 11299, which
     * sorts straight after 1120 and before 1130, so the chart still reads in
     * code order. ⚠ Codes are unique across the whole chart, so a code somebody
     * typed elsewhere is skipped rather than collided with.
     */
    public static function nextCode(Account $group): string
    {
        $prefix = self::codePrefix($group->code);
        $taken = Account::query()->where('code', 'like', $prefix.'__')->pluck('code')->all();

        for ($n = 1; $n <= 99; $n++) {
            $code = $prefix.str_pad((string) $n, 2, '0', STR_PAD_LEFT);

            if (! in_array($code, $taken, true)) {
                return $code;
            }
        }

        throw new RuntimeException("{$group->label()} is full: it already holds 99 accounts.");
    }

    /** 1120 -> 112: the part a head's code starts with. */
    public static function codePrefix(string $groupCode): string
    {
        return strlen($groupCode) === 4 && str_ends_with($groupCode, '0') ? substr($groupCode, 0, 3) : $groupCode;
    }

    /**
     * The kind's first head (slug cash_at_bank and so on), when nothing reports
     * under it and nothing was ever posted to it: on a fresh install that is the
     * placeholder the seeder planted, and the first bank account takes it over
     * rather than leaving an empty "Main Bank Account" behind.
     */
    private static function claimableFirstHead(MoneyAccountType $type, Account $group): ?Account
    {
        $slug = self::KINDS[$type->value][2];
        $head = $slug === null ? null : Account::bySlug($slug);

        if ($head === null || (int) $head->parent_id !== $group->id) {
            return null;
        }

        if (self::ownersOf($head->id)->isNotEmpty() || DB::table('journal_lines')->where('account_id', $head->id)->exists()) {
            return null;
        }

        return $head;
    }
}
