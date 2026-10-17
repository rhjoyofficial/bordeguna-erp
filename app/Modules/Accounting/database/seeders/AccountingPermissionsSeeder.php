<?php

namespace App\Modules\Accounting\database\seeders;

use App\Modules\Accounting\Support\ChartPermissions;
use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may read the books, change the chart, and write to the ledger.
 *
 * ⚠⚠ THREE PERMISSIONS, DELIBERATELY SEPARATE, in increasing order of danger:
 *
 * `accounting.view` reads. It is NOT a general-purpose permission - a general
 * ledger eventually carries a line per salary payment, so reading it is close to
 * reading payroll. It follows the C5/ACL-02 principle already established for
 * salary: Owner, Finance and Payroll, nobody else by default.
 *
 * `accounting.chart.manage` changes what an account MEANS. Renaming or
 * re-parenting an account silently changes every report that has ever been run
 * against it, so it sits with the Owner until a real accountant has a role.
 *
 * `accounting.journal.post` writes to the ledger by hand, which is the highest
 * risk permission in the whole system (C-GL-6): it is the one action that can
 * move money between any two accounts with no document behind it. Owner only.
 *
 * `accounting.income.record` records money that came in and was not a sale.
 * ⚠ It exists rather than reusing `accounting.journal.post` because the whole
 * point of the income voucher is that recording other income should NOT require
 * the one permission that can move money anywhere with no document. Owner and
 * Finance; the document itself refuses sales revenue, so it can never become a
 * back door into the sales figures.
 *
 * ℹ `accounting.journal.post` gates nothing until slice 2.3. That is the right
 * way round - a permission that exists before its route is inert, a route that
 * exists before its permission is open.
 *
 * ℹ The accountant, when they arrive, goes on the `Finance` role through the
 * Roles UI. That is one grant, not a code change, which is the point of having
 * kept the roles configurable.
 *
 * 26 Sep 2026: `accounting.chart.manage` is split (Support\ChartPermissions):
 * head create / update / delete, chart structure, money accounts, VAT. Finance
 * gets head create + update so the accountant can add a ledger head from a
 * voucher without being able to re-parent the chart or switch VAT on. The old
 * key stays on Owner and still implies all six through a gate hook.
 */
class AccountingPermissionsSeeder extends Seeder
{
    /**
     * The operating reports: receivables ageing, customer statement, inventory
     * valuation. Anyone with `accounting.view` reads them too (the provider
     * composes the gate), so this key exists for roles that may read those
     * three and not the ledger behind them.
     */
    public const OPERATIONS_VIEW = 'accounting.operations.view';

    /**
     * Accounting > Counterparties (loans plan v2, 14 Oct 2026): reading the
     * lenders and borrowers with their balances, and keeping the list. The
     * Manager reads (like the customer statement); Owner and Finance keep it.
     */
    public const COUNTERPARTIES_VIEW = 'accounting.counterparties.view';

    public const COUNTERPARTIES_MANAGE = 'accounting.counterparties.manage';

    /**
     * Accounting > Loans (loans plan v2, phase 2, 14 Oct 2026): the Loan
     * Register. The Manager reads it like the counterparties; Owner and Finance
     * keep it (draft, money, accruals). Approving a loan, an amendment or a
     * write-off is the Owner's, by the approval workflow, not by a permission.
     */
    public const LOANS_VIEW = 'accounting.loans.view';

    public const LOANS_MANAGE = 'accounting.loans.manage';

    /**
     * Edit, delete and correct, Phase 0 (PHASE-0-CONTRACT v2, 2.2). Reverse and
     * Correct in an open month; be the second person above the limit; correct a
     * closed month (Owner only, and the code also checks the Owner role); read
     * the monthly Corrections report.
     */
    public const CORRECT = 'accounting.correct';

    public const CORRECT_APPROVE = 'accounting.correct.approve';

    public const CORRECT_CLOSED_PERIOD = 'accounting.correct.closed_period';

    public const CORRECTIONS_REPORT = 'corrections.report.view';

    /**
     * Owner withdrawals (Phase 2, 17 Oct 2026, ruling Q2 and D2). Reading the
     * list; writing a draft and posting it; and AUTHORISING, which is the
     * second person's act (Finance, the Manager): the proprietor authorises
     * his own withdrawal only with a note, which is the action's rule, not a
     * permission.
     */
    public const OWNER_WITHDRAWAL_VIEW = 'accounting.owner_withdrawal.view';

    public const OWNER_WITHDRAWAL_MANAGE = 'accounting.owner_withdrawal.manage';

    public const OWNER_WITHDRAWAL_AUTHORISE = 'owner_withdrawal.authorise';

    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'accounting.view',
            self::OPERATIONS_VIEW,
            'accounting.chart.manage',
            'accounting.journal.post',
            'accounting.income.record',
            'accounting.voucher.prepare',
            'accounting.voucher.post',
            ChartPermissions::HEAD_CREATE,
            ChartPermissions::HEAD_UPDATE,
            ChartPermissions::HEAD_DELETE,
            ChartPermissions::STRUCTURE,
            ChartPermissions::MONEY_ACCOUNTS,
            ChartPermissions::VAT,
            ChartPermissions::POLICY,
            self::COUNTERPARTIES_VIEW,
            self::COUNTERPARTIES_MANAGE,
            self::LOANS_VIEW,
            self::LOANS_MANAGE,
            // Edit, delete and correct, Phase 0 (17 Oct 2026). The closed
            // month is the Owner's; the code ALSO checks the Owner role.
            self::CORRECT,
            self::CORRECT_APPROVE,
            self::CORRECT_CLOSED_PERIOD,
            self::CORRECTIONS_REPORT,
            // The proprietor writes and posts his own withdrawals; authorising
            // is the second person's (or his own, with a note).
            self::OWNER_WITHDRAWAL_VIEW,
            self::OWNER_WITHDRAWAL_MANAGE,
        ],
        'Finance' => [
            'accounting.view',
            self::OPERATIONS_VIEW,
            // ⚠ Receiving money is a different act from reading the books, and
            // a smaller one than writing a journal by hand. Finance can record
            // scrap, interest or a refund without being handed the ledger key.
            'accounting.income.record',
            // ⚠ Finance PREPARES vouchers; posting one writes to the ledger and
            // starts with the Owner. Handing it to the accountant is one tick
            // in Settings > Roles, not a code change (voucher plan, decision 9).
            'accounting.voucher.prepare',
            // ⚠ A head or a sub-heading under an existing heading, and a rename:
            // the accountant's daily need (26 Sep 2026). Not delete, not the
            // structure, not Bank & cash, not VAT.
            ChartPermissions::HEAD_CREATE,
            ChartPermissions::HEAD_UPDATE,
            // The lender a loan line names is added from the voucher the
            // accountant is preparing (14 Oct 2026).
            self::COUNTERPARTIES_VIEW,
            self::COUNTERPARTIES_MANAGE,
            // The Loan Register is kept by the accountant; approval stays the Owner's.
            self::LOANS_VIEW,
            self::LOANS_MANAGE,
            // ⭐ Owner's answer 3 (17 Oct 2026): Finance corrects posted
            // vouchers in an OPEN month, with the Tk 20,000 second person, and
            // may be that second person for somebody else's correction.
            self::CORRECT,
            self::CORRECT_APPROVE,
            self::CORRECTIONS_REPORT,
            self::OWNER_WITHDRAWAL_VIEW,
            self::OWNER_WITHDRAWAL_MANAGE,
            self::OWNER_WITHDRAWAL_AUTHORISE,
        ],
        // ⭐ 26 Sep 2026 (Joy, Q19): the Manager reads the receivables ageing,
        // a customer's statement and the stock value, and nothing else of the
        // ledger. These three answer "who owes us, what does this customer owe,
        // what is on the shelf worth", which is the Manager's daily work; the
        // trial balance, P&L and vouchers stay with Owner, Finance and Payroll.
        'Manager' => [
            self::OPERATIONS_VIEW,
            self::COUNTERPARTIES_VIEW,
            self::LOANS_VIEW,
            // The Manager is the other second person who may authorise an
            // owner withdrawal, and reads the list to do it.
            self::OWNER_WITHDRAWAL_VIEW,
            self::OWNER_WITHDRAWAL_AUTHORISE,
        ],
        // Musa's role. He prepares and pays; reading a trial balance is part of
        // that, writing to it is not - the same separation that already keeps
        // him from approving his own payroll run.
        'Payroll' => [
            'accounting.view',
        ],
    ];

    public function run(): void
    {
        $permissionNames = collect(self::ROLE_PERMISSIONS)->flatten()->unique();

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Business::all() as $business) {
            foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
                $role = Role::where('business_id', $business->id)->where('name', $roleName)->first();

                // givePermissionTo is additive - syncPermissions would wipe out
                // whatever other modules' seeders already granted this role.
                // ⚠ The role may not exist in every business: `Payroll` is part
                // of Bor de Guna's real structure, not the seeded default set.
                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
