<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * The control heads that keep ONE LEDGER PER PARTY, and which party each keeps.
 *
 * Joy's accountant, 26 Sep 2026: "every accounting thing must have a ledger
 * head - every bank account, every employee." Banks got a real head each on
 * 21 Sep (MoneyLedgers). For people and firms the chart does NOT grow a row per
 * name (it is shared by four businesses and staff come and go); instead the
 * control head is the ledger and the PARTY on every line is the sub-ledger.
 * "1170 Employee Advances > Musa Islam" is Musa's ledger: it can be picked on a
 * voucher, opened as a statement, printed and totalled back to 1170 exactly.
 *
 * ⚠⚠ THE CONTRACT THAT MAKES THAT TRUE (ledger-heads plan D3, ChatGPT review
 * point 3): a line on one of these heads ALWAYS names its party, whatever
 * screen or module posts it. PostJournal enforces it for every writer, so the
 * sum of the parties can never drift from the control balance, and the
 * reconciliation screen proves it (SubledgerReconciliation R7).
 *
 * ⚠ 2130 Salaries Payable is party-kept but NOT voucher-pickable: salaries are
 * paid from Payroll > Payments, which clears the very lines the accrual made,
 * and a second door would be a second engine. The statement still shows it.
 * 2145 Employee Reimbursements Payable stays with expense claims for the same
 * reason and is not in the contract at all (its lines already carry the
 * employee; making it a hard rule is a claims-module decision).
 */
final class PartyLedgers
{
    /** system_slug => the party class every line must carry. */
    private const LEDGERS = [
        'supplier_advances' => Supplier::class,
        'customer_advances' => Customer::class,
        'employee_advances' => Employee::class,
        'employee_deductions_payable' => Employee::class,
        'salaries_payable' => Employee::class,
    ];

    /** The subset a voucher or journal voucher line may name. */
    private const VOUCHER_PICKABLE = ['supplier_advances', 'customer_advances', 'employee_advances', 'employee_deductions_payable'];

    /** The employee's ledgers, in the order the statement shows them. */
    public const EMPLOYEE_SLUGS = ['employee_advances', 'salaries_payable', 'employee_deductions_payable', 'employee_reimbursements_payable'];

    /** @return array<string, class-string<Model>> */
    public static function all(): array
    {
        return self::LEDGERS;
    }

    /** @return array<string, class-string<Model>> slug => party class, vouchers only */
    public static function voucherPickable(): array
    {
        return array_intersect_key(self::LEDGERS, array_flip(self::VOUCHER_PICKABLE));
    }

    /** @return class-string<Model>|null */
    public static function partyClassFor(Account $account): ?string
    {
        return $account->system_slug === null ? null : (self::LEDGERS[$account->system_slug] ?? null);
    }

    public static function requiresParty(Account $account): bool
    {
        return self::partyClassFor($account) !== null;
    }

    /** "supplier", "customer" or "employee": the word a message uses. */
    public static function noun(string $partyClass): string
    {
        return strtolower(class_basename($partyClass));
    }

    /**
     * How a party is named on a ledger line, a picker or a statement.
     *
     * ⚠ Employees have no `name` column (first and last, plus a code), which
     * is why the general ledger used to print "Employee #12" for them.
     */
    public static function label(?Model $party, ?string $partyType = null, ?int $partyId = null): ?string
    {
        if ($party === null) {
            return $partyType === null ? null : class_basename($partyType)." #{$partyId}";
        }

        if ($party instanceof Employee) {
            return trim($party->fullName()) !== '' ? $party->fullName() : $party->employee_code;
        }

        return $party->getAttribute('name') ?? class_basename($party)." #{$party->getKey()}";
    }
}
