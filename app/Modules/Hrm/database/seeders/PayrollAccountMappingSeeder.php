<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Where payroll lands in the chart, by default.
 *
 * ⚠ Every one of these is a DEFAULT Joy or his accountant can change in one row,
 * from a date, without touching code and without altering what earlier months
 * meant. Three of them are recorded as Corrections in
 * ACCOUNTING-PHASE3-INTEGRATION.md §6 because they are the ones most likely to
 * be re-filed.
 *
 * ⚠⚠ Dated from far enough back to cover any payroll already in the system.
 * A mapping that starts today cannot post last month's run, and the first thing
 * anybody will do is post a run that already exists.
 */
class PayrollAccountMappingSeeder extends Seeder
{
    /**
     * key => system slug of the account it points at.
     *
     * @var array<string, string>
     */
    private const MAPPINGS = [
        // ─── Earnings: what the wage bill costs ──────────────────────────────
        'payroll.earning.default' => 'salaries_expense',
        'payroll.earning.basic' => 'salaries_expense',
        'payroll.earning.house_rent' => 'salaries_expense',
        'payroll.earning.medical' => 'salaries_expense',
        'payroll.earning.conveyance' => 'salaries_expense',
        // C-INT-1. Deliberately excluded from gross and behaving like a travel
        // cost rather than pay, so it is filed with conveyance. One row moves it
        // back to the wage bill if the accountant prefers.
        'payroll.earning.ta_da' => 'fuel_and_conveyance',
        // ⚠⚠ A REIMBURSEMENT IS NOT PAY, AND WITHOUT THIS ROW IT WAS FILED AS
        // PAY. Money the employee spent on the company's behalf is the
        // company's cost of whatever they bought; it reaches the payslip only
        // because the salary is the convenient way to hand it back. With no
        // mapping of its own it fell through to `payroll.earning.default`, so
        // every reimbursement inflated the wage bill and understated the real
        // expense by the same amount - and balanced perfectly while doing it,
        // which is why nothing ever noticed. Found 1 September 2026 on
        // Shoykod's 2,005 TA/DA conveyance bill for the Barishal trip, which is
        // what a reimbursement here has always been in practice; it sits beside
        // `ta_da` for that reason.
        //
        // ⚠ If reimbursements ever cover something that is NOT travel, this one
        // row stops being right, and the expense head already chosen when a
        // claim is settled has to be carried through to the posting instead.
        'payroll.earning.reimbursement' => 'fuel_and_conveyance',

        /*
         * ⛔ A CLAIM SETTLEMENT CLEARS A PAYABLE, IT DOES NOT EXPENSE.
         *
         * An expense claim is expensed the moment it is APPROVED (Dr the
         * category's account, Cr Employee Reimbursements Payable). Paying it
         * through salary must therefore debit that payable, not an expense
         * account, or the same cost lands in the books twice.
         *
         * This is why it is a separate earning code from `reimbursement`, which
         * stays pointed at an expense account for the hand-typed case where no
         * claim exists and nothing has credited the payable.
         */
        'payroll.earning.expense_claim_settlement' => 'employee_reimbursements_payable',

        // ─── Deductions that create an obligation to somebody else ───────────
        // ⚠⚠ The default is right ONLY for money held for a third party (tax,
        // PF). A code that falls through to it is a guess, so every code payroll
        // can emit is mapped below on purpose, and PayrollDeductionMappingTest
        // fails CI for a new one that is not. Three are knowingly left on it,
        // each an open decision named in that test: `deduction` (the generic
        // adjustment), `staff_purchase` and `balance_recovery`.
        'payroll.deduction.default' => 'employee_deductions_payable',
        // C-INT-2. Two different obligations to two different bodies, sharing
        // one account until somebody asks to separate them.
        'payroll.deduction.income_tax' => 'employee_deductions_payable',
        'payroll.deduction.provident_fund' => 'employee_deductions_payable',
        'payroll.deduction.other' => 'employee_deductions_payable',

        // ─── Deductions that repay the business ──────────────────────────────
        // C-INT-3. Recovering a loan REDUCES AN ASSET; it is not income. The
        // mapping most likely to look wrong at a glance and the one I am most
        // confident about.
        'payroll.deduction.loan_repayment' => 'employee_advances',
        'payroll.deduction.advance_recovery' => 'employee_advances',
        'payroll.deduction.festival_loan' => 'employee_advances',

        // ─── Deductions that are simply less wage ────────────────────────────
        // ⚠⚠ THE ONE THAT IS EASY TO GET WRONG. An absence deduction is not
        // income and not a liability - it is less wage cost, so it credits the
        // expense back. Filing it as a payable would inflate the cost and the
        // liability by the same amount and still balance perfectly.
        'payroll.deduction.late_early' => 'salaries_expense',
        'payroll.deduction.lwp' => 'salaries_expense',
        'payroll.deduction.absent' => 'salaries_expense',
        // ⚠⚠ FOUND ON THE SEPTEMBER 2026 PAYROLL (15 Oct 2026), and it is the
        // same trap as the three above. ApplyProration writes a joiner's or
        // leaver's days off the books as a deduction (so the payslip still
        // shows the contractual gross), but those days were simply never wage.
        // With no row of its own it fell through to the default: Munna's 10,000
        // part-month cut was credited to 2140 as if the company held it for
        // somebody, while the full 12,000 stayed in Salaries. Correct:
        // Dr 6131 2,000 / Cr 2130 2,000, and nothing on 2140.
        'payroll.deduction.proration' => 'salaries_expense',
        // The Recovery adjustment. The only place the system writes one is
        // ApplySalaryRevision, for a DOWNWARD revision applied to months already
        // paid: salary that was overpaid and expensed then is taken back now,
        // so it reverses the wage cost exactly like an absence.
        //
        // ⚠ It can also be hand-typed ("money owed to the company being taken
        // back"). If it is ever used for a loan or advance paid out outside the
        // loan module, that one is an asset being repaid (1170) and this row is
        // wrong for it: a real loan belongs in the loan module, which posts
        // `loan_repayment`.
        'payroll.deduction.recovery' => 'salaries_expense',

        // ─── Accountant meeting 19 Sep 2026, D1 and D2 ───────────────────────
        // Overtime and bonus were falling through to Salaries and Wages, so the
        // P&L could not show either. They have had accounts of their own since
        // the master expense chart; nothing pointed at them.
        'payroll.earning.overtime' => 'overtime_expense',
        'payroll.earning.bonus' => 'bonus_expense',
        // ⚠⚠ A FINE IS HELD (Finance policy B6, 29 Sep 2026, superseding the
        // 19 Sep D2 mapping to Salaries and Wages). The Labour Act (s.25) does
        // not let the employer keep a fine as its own money, so it is neither
        // income nor a credit to the wage cost: it sits in its own liability,
        // 2147 Staff Fines Held. Re-pointed on live data by the migration
        // 2026_10_06_110000_staff_fines_are_held_not_wage_credit.
        'payroll.deduction.penalty' => 'staff_fines_held',

        // ─── The two sides of the settlement ─────────────────────────────────
        'payroll.net_payable' => 'salaries_payable',

        // ⚠ The DEBIT side of handing a loan over, and the mirror of the
        // `loan_repayment` deduction above. Between the two, "what do the staff
        // owe us" becomes a ledger balance rather than a number somebody adds up
        // from the loan module.
        'payroll.employee_advances' => 'employee_advances',

        // ─── Leave paid out in cash ─────────────────────────────────────
        // ⚠ 6130 is where an ACTUAL encashment payment lands if one is ever
        // made by hand. It is NOT a provision: Joy removed provisioning from
        // the ERP on 29 Aug 2026, so nothing accrues a liability for unused
        // leave and no 2135 credit exists any more. Changeable in one row.
        'payroll.leave_encashment' => 'leave_encashment',
    ];

    public function run(): void
    {
        // Far enough back to cover any run already in the system. A mapping that
        // starts today cannot post last month's payroll.
        $from = Carbon::parse('2000-01-01');

        $previous = app(BusinessContext::class)->id();

        foreach (Business::withoutGlobalScopes()->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            foreach (self::MAPPINGS as $key => $slug) {
                $account = Account::bySlug($slug);

                if ($account === null) {
                    continue;
                }

                AccountMapping::assign($key, $account, $from, 'Seeded default');
            }
        }

        // ⚠ Put the caller back where they were. A seeder that walks every
        // tenant and finishes outside all of them evicts whatever called it -
        // the defect found in LedgerIntegrity on 2026-08-03.
        if ($previous === null) {
            TenantScope::leave();
        } else {
            TenantScope::enter($previous);
        }
    }
}
