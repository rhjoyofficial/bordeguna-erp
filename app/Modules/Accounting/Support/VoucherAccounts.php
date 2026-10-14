<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\VoucherType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Enums\MoneyAccountType;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\Employee;
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
 * AR/AP (plan decision 6). The party-enabled heads (Support\PartyLedgers) are
 * the two advance accounts and, since 26 Sep 2026, the employee ledgers 1170
 * Employee Advances and 2140 Employee Deductions Payable: a line there MUST
 * name its supplier, customer or employee, and a line anywhere else must not -
 * a customer on "Office Rent" means nothing. The employee heads are mapped
 * under `payroll.*` and would count as owned; they are let through BECAUSE the
 * employee on the line is the sub-ledger payroll itself keeps (PartyLedgers).
 */
final class VoucherAccounts
{
    private const RESERVED_SLUGS = ['employee_reimbursements_payable', 'opening_balance_equity', 'retained_earnings', 'due_from_businesses', 'due_to_businesses'];

    private const MONEY_SLUGS = ['cash_in_hand', 'cash_at_bank', 'mobile_financial_services', 'courier_receivable', 'gateway_receivable'];

    /** Where a module's heads ARE posted, keyed by mapping prefix. Shown under a refused row. */
    private const MODULE_INSTEAD = [
        'sales.' => 'Posted by Sales: invoices, receipts and returns.',
        'purchasing.' => 'Posted by Purchases: supplier bills and payments.',
        'inventory.' => 'Posted by Inventory: goods received, stock adjustments and counts.',
        'payroll.' => 'Posted by Payroll: payroll runs, loans and advances.',
        'assets.' => 'Posted by Fixed assets: depreciation and disposals.',
    ];

    /** Heads one particular screen posts, named more exactly than their module. */
    private const KEY_INSTEAD = [
        'sales.accounts_receivable' => 'Posted by sales invoices. Record a customer\'s payment on the invoice.',
        'purchasing.accounts_payable' => 'Posted by supplier bills. Pay a supplier from Purchases > Payments.',
        'sales.output_vat' => 'Posted by sales invoices and cleared by the VAT return.',
        'purchasing.input_vat' => 'Posted by supplier bills and cleared by the VAT return.',
        'sales.cash_over_short' => 'Posted when a cashier shift is closed.',
        'sales.gateway_fees' => 'Posted when an online payment is settled.',
        'payroll.net_payable' => 'Posted by Payroll. Salaries are paid from Payroll > Payments, which clears each employee\'s line.',
    ];

    private const RESERVED_INSTEAD = [
        'employee_reimbursements_payable' => 'Posted by Payroll > Expense claims.',
        'opening_balance_equity' => 'Used only by opening balances.',
        'retained_earnings' => 'Kept by the system: profit carried forward at year end.',
        // Both businesses' books move together, or the group stops cancelling.
        'due_from_businesses' => 'Moved by Finance & Accounts > Inter-business transfers.',
        'due_to_businesses' => 'Moved by Finance & Accounts > Inter-business transfers.',
    ];

    /** slug => the party class a line on that account must carry (PartyLedgers::voucherPickable()). */
    public const PARTY_ACCOUNTS = [
        'supplier_advances' => Supplier::class,
        'customer_advances' => Customer::class,
        'employee_advances' => Employee::class,
        'employee_deductions_payable' => Employee::class,
    ];

    /**
     * EVERY head of the chart, in chart order: the ones a line may use and, so
     * that nothing looks missing, the ones it may not, each saying where that
     * head is posted instead.
     *
     * ⚠⚠ WHY THE REFUSED HEADS ARE LISTED AT ALL (Joy, 21 Sep 2026): a head he
     * could see in the Chart of accounts was simply absent from the picker, with
     * nothing to say why, and read as a limitation of the voucher. A greyed row
     * naming the screen that posts there answers the question an empty search
     * raised. It still cannot be chosen, and SaveVoucherDraft and PostVoucher
     * refuse it whatever a form sends: this list decides what is SHOWN, never
     * what is allowed.
     *
     * @return Collection<int, array{account: Account, heading: bool, usable: bool, hint: ?string}>
     */
    public static function catalogue(Carbon $on, VoucherType $type): Collection
    {
        $refused = self::refusedRows($on, $type);
        // ⭐ Each customer, supplier and employee head, pickable by name where the
        // voucher type allows it (finance decision C3, 29 Sep 2026).
        $people = PartyHeads::map();

        return Account::query()->inChartOrder()->get()->map(function (Account $account) use ($refused, $people, $type) {
            if (isset($people[$account->id])) {
                $hint = PartyHeads::refusal($people[$account->id], $type);

                return ['account' => $account, 'heading' => false, 'usable' => $hint === null, 'hint' => $hint];
            }

            $hint = match (true) {
                ! $account->is_postable => 'Group heading. Choose a head under it.',
                ! $account->is_active => 'Switched off in the Chart of accounts.',
                default => $refused[$account->id]['instead'] ?? null,
            };

            return ['account' => $account, 'heading' => ! $account->is_postable, 'usable' => $hint === null, 'hint' => $hint];
        })->values();
    }

    /** Why this account cannot sit on a voucher line, or null when it can. */
    public static function refusal(Account $account, Carbon $on, ?VoucherType $type = null): ?string
    {
        // A person's own head: never posted to, but pickable, and PostVoucher
        // turns it into the document and control account (PartyHeads).
        if (! $account->is_postable && $type !== null && ($person = PartyHeads::entityFor($account)) !== null) {
            return $account->is_active ? PartyHeads::refusal($person::class, $type) : "{$account->label()} is switched off.";
        }

        if (! $account->is_postable || ! $account->is_active) {
            return "{$account->label()} cannot be posted to.";
        }

        return self::refusedRows($on)[$account->id]['reason'] ?? null;
    }

    /**
     * Every account id a voucher line may not name on this date.
     *
     * PUR-002 (audit 14 Oct 2026): the Purchasing expense voucher, its importer
     * and its recurring schedules filter their pickers by this, the same rule
     * the Accounting voucher and the manual journal refuse by.
     *
     * @return list<int>
     */
    public static function refusedIds(Carbon $on): array
    {
        return array_keys(self::refusedRows($on));
    }

    /** The party class a line on this account must carry, or null for none. */
    public static function partyClassFor(Account $account): ?string
    {
        return self::PARTY_ACCOUNTS[$account->system_slug] ?? null;
    }

    /**
     * @return array<int, array{reason: string, instead: string}> account id => why it is refused, and where it is posted instead
     */
    private static function refusedRows(Carbon $on, ?VoucherType $type = null): array
    {
        $refused = [];

        $partyHeads = Account::query()->whereIn('system_slug', array_keys(PartyLedgers::voucherPickable()))->pluck('id')->flip();

        foreach (SubsystemAccounts::on($on) as $accountId => $key) {
            // An employee ledger is posted WITH its employee: that is the
            // sub-ledger, so a voucher line there explains itself.
            if ($partyHeads->has($accountId)) {
                continue;
            }

            $prefix = strtok($key, '.').'.';
            $refused[$accountId] = [
                'reason' => 'This account belongs to '.ucfirst(rtrim($prefix, '.')).' and is posted only by its own documents.',
                'instead' => self::KEY_INSTEAD[$key] ?? self::MODULE_INSTEAD[$prefix] ?? 'Posted only by its own documents.',
            ];
        }

        $money = 'Cash and bank move through the Paid from / Received into field, not a line.';
        $moneyInstead = match ($type) {
            VoucherType::Payment => 'Cash or bank: choose it in Paid from above.',
            VoucherType::Receipt => 'Cash or bank: choose it in Received into above.',
            default => 'Cash or bank moves by a Payment, Receive or Contra voucher.',
        };
        $courierInstead = 'Courier money: settled from Sales > Courier payouts.';
        $gatewayInstead = 'Online-sale money: moved to the bank by a Contra voucher.';

        // ⚠ Every business's money accounts, not only this one's: the chart is
        // shared, so 1120 is "a bank ledger" whichever business asks.
        foreach (MoneyAccount::withoutGlobalScopes()->whereNotNull('account_id')->get(['account_id', 'type']) as $tied) {
            $instead = match ($tied->type) {
                MoneyAccountType::Courier => $courierInstead,
                MoneyAccountType::Gateway => $gatewayInstead,
                default => $moneyInstead,
            };

            // A ledger a till or bank also sits on reads as cash, whatever else shares it.
            $before = $refused[$tied->account_id]['instead'] ?? null;
            $refused[$tied->account_id] = ['reason' => $money, 'instead' => $before === $moneyInstead ? $moneyInstead : $instead];
        }

        // The chart's own cash and bank heads, even before a money account has
        // been tied to them: a line there is still money with no till named.
        foreach (Account::query()->whereIn('system_slug', self::MONEY_SLUGS)->get(['id', 'system_slug']) as $account) {
            $refused[$account->id] = ['reason' => $money, 'instead' => match ($account->system_slug) {
                'courier_receivable' => $courierInstead,
                'gateway_receivable' => $gatewayInstead,
                default => $moneyInstead,
            }];
        }

        // Every head under the cash, bank and wallet groups, tied or not: each is
        // one money account's own ledger, and a line there names no till or bank.
        foreach (Account::query()->whereIn('parent_id', MoneyLedgers::groupIds())->pluck('id') as $accountId) {
            $refused[$accountId] ??= ['reason' => $money, 'instead' => $moneyInstead];
        }

        foreach (Account::query()->whereIn('system_slug', self::RESERVED_SLUGS)->get(['id', 'system_slug']) as $account) {
            $refused[$account->id] = [
                'reason' => 'This account is kept by the system and cannot be used on a voucher.',
                'instead' => self::RESERVED_INSTEAD[$account->system_slug],
            ];
        }

        return $refused;
    }
}
