<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Accounting\Support\PartyLedgers;
use App\Modules\Hrm\Models\PayrollRun;
use Illuminate\Support\Carbon;

/**
 * Turns an approved payroll run into its accounting entry.
 *
 * ⚠⚠ THIS LIVES IN HRM, NOT IN ACCOUNTING, and the direction is the point.
 * Review principle 5.1: the ledger knows nothing about stock or salaries. If
 * Accounting imported PayrollRun, the GL would learn what a payslip is, and
 * every future subsystem would add another thing it has to know - which is
 * exactly how a general ledger stops being general. Payroll describes its own
 * event in ledger vocabulary and hands it over; the ledger only ever sees a
 * dated, balanced set of lines.
 *
 * ⚠ Caught during this slice: it was first written under Accounting\Actions,
 * which would have inverted that on the very first integration.
 *
 * ⚠⚠ AUTOMATIC, FROM A BUSINESS EVENT, ALWAYS. Joy's principle of 2026-08-03:
 * users never create accounting entries for payroll by hand. This runs inside
 * the approval that already owns the event, so there is exactly one origin for
 * every wage figure in the accounts, and a reconciliation can therefore be
 * conclusive.
 *
 * Accrual basis (C-GL-5): the cost belongs to the month the work was done, not
 * the day the bank moved. So the journal is dated the END OF THE PAYROLL PERIOD,
 * not the approval date - approving July's payroll on 3 August must not put
 * July's wages into August.
 *
 *   Dr  Salaries and Wages          gross earnings, by mapped expense account
 *       Cr  Salaries and Wages Payable  net, ONE LINE PER EMPLOYEE
 *       Cr  Employee Deductions Payable tax, provident fund, other
 *       Cr  Employee Advances and Loans loan and advance recovery
 *       Cr  Salaries and Wages          absence, LWP and lateness
 *
 * ⚠⚠ That last line is the one that is easy to get wrong. An absence deduction
 * is not income and not a liability - it is LESS WAGE COST. Crediting it back to
 * the expense makes the P&L salary figure the amount actually earned; filing it
 * as a payable would inflate the cost and the liability by the same amount and
 * still balance perfectly.
 */
class PostPayrollAccrual
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * ⚠⚠ ONE ANCHOR MAPPING SWITCHES PAYROLL POSTING ON FOR A BUSINESS.
     *
     * Without it every payroll test in the suite failed the moment this
     * integration landed - 52 of them - and that was the code telling me
     * something true: making Accounting a hard dependency of APPROVING A
     * PAYROLL would mean a business that has not set up its ledger yet cannot
     * pay its people. Bor de Guna's payroll is live and in a parallel run; an
     * accounting feature must not be able to stop it.
     *
     * So there are two different situations and they deserve different answers:
     *
     *  - **Accounting is not switched on for this business.** A legitimate
     *    state. Payroll runs exactly as it did before and posts nothing.
     *  - **Accounting IS switched on and one component is unmapped.** A defect.
     *    The approval fails, because a wage bill missing one component is not a
     *    smaller wage bill, it is a wrong one.
     *
     * `payroll.net_payable` is the anchor because every payroll journal needs
     * it: there is no payroll posting that does not credit the payable.
     */
    private function isConfigured(Carbon $on): bool
    {
        return AccountMapping::forKeyOn('payroll.net_payable', $on) !== null;
    }

    public function handle(PayrollRun $run, User $postedBy): ?Journal
    {
        // ⚠ A run posts ONCE. Approving twice, a retried job or a re-run of a
        // backfill must not double the wage bill. The journal's own source is
        // the check, so there is no separate "posted" flag to drift.
        if ($this->existingJournal($run) !== null) {
            return null;
        }

        if (! $this->isConfigured($this->accountingDate($run))) {
            return null;
        }

        $on = $this->accountingDate($run);
        $lines = [];

        // Expense totals are aggregated by account: nobody asks the ledger
        // which employee a wage COST belongs to. A deduction that lands on a
        // PARTY-KEPT head (Employee Deductions Payable, Employee Advances) is
        // kept per employee instead, because that head is that employee's
        // ledger (Support\PartyLedgers, 26 Sep 2026): "what is held from Musa"
        // and "what Musa has repaid" must be answerable from the ledger alone.
        $byAccount = [];
        $byAccountAndEmployee = [];
        $employees = [];
        $netByEmployee = [];

        foreach ($run->payslips()->with(['lines', 'employee'])->get() as $payslip) {
            foreach ($payslip->lines as $line) {
                $amount = Money::ofTaka((string) $line->amount);

                if ($amount->isZero()) {
                    continue;
                }

                $account = $line->isEarning()
                    ? AccountMapping::resolve("payroll.earning.{$line->code}", $on, 'payroll.earning.default')
                    : AccountMapping::resolve("payroll.deduction.{$line->code}", $on, 'payroll.deduction.default');

                // An earning is a debit; a deduction credits whatever it is
                // mapped to - which may be a liability, an asset being repaid,
                // or the expense itself.
                $signed = $line->isEarning() ? $amount : $amount->negated();

                if (PartyLedgers::requiresParty($account) && $payslip->employee !== null) {
                    $key = $account->id.':'.$payslip->employee->id;
                    $employees[$payslip->employee->id] = $payslip->employee;
                    $byAccountAndEmployee[$key] = isset($byAccountAndEmployee[$key])
                        ? $byAccountAndEmployee[$key]->plus($signed)
                        : $signed;

                    continue;
                }

                $byAccount[$account->id] = isset($byAccount[$account->id])
                    ? $byAccount[$account->id]->plus($signed)
                    : $signed;
            }

            $net = Money::ofTaka((string) $payslip->net);

            if (! $net->isZero()) {
                $netByEmployee[] = [$payslip->employee, $net];
            }
        }

        if ($byAccount === [] && $byAccountAndEmployee === [] && $netByEmployee === []) {
            return null;
        }

        foreach ($byAccount as $accountId => $amount) {
            // ⚠ An account whose earnings and deductions cancel exactly
            // contributes nothing and must not become a zero line, which
            // PostJournal refuses outright.
            if ($amount->isZero()) {
                continue;
            }

            $lines[] = JournalLineDraft::signed($accountId, $amount, 'Payroll '.$run->period?->label());
        }

        foreach ($byAccountAndEmployee as $key => $amount) {
            if ($amount->isZero()) {
                continue;
            }

            [$accountId, $employeeId] = array_map('intval', explode(':', $key));
            $employee = $employees[$employeeId];

            $lines[] = JournalLineDraft::signed($accountId, $amount, 'Payroll '.$run->period?->label().' '.$employee->employee_code, $employee);
        }

        // ⚠ One line per employee, because Wages Payable is a SUBSIDIARY LEDGER:
        // "who are we still owing" has to be answerable without reading
        // payslips, and the payment journal clears the same lines it accrued.
        $payable = AccountMapping::resolve('payroll.net_payable', $on);

        foreach ($netByEmployee as [$employee, $net]) {
            $lines[] = JournalLineDraft::credit(
                $payable->id,
                $net,
                $employee?->employee_code,
                $employee,
            );
        }

        $draft = new JournalDraft(
            date: $on,
            lines: $lines,
            memo: 'Payroll '.($run->period?->label() ?? '').' v'.$run->version,
            source: $run,
        );

        return $this->postJournal->handle($draft, $postedBy);
    }

    /**
     * Reverses the run's journal, for a run that is cancelled or reopened.
     *
     * ⚠ Through ReverseJournal like everything else, so the correction faces
     * every guard the original faced - including the period lock. A payroll
     * cancelled after its month is closed cannot silently reopen that month.
     *
     * ⚠⚠ DATED ON THE ENTRY IT REMOVES, NOT TODAY, AND THIS IS THE EXCEPTION
     * ReverseJournal DOCUMENTS RATHER THAN A BREACH OF IT. Everywhere else a
     * reversal is an event of its own and belongs to the day it was decided.
     * A payroll accrual is not undone; it is REPLACED, and the replacement is
     * dated by `accountingDate()` at the end of the period the work was done
     * in. Dating only the removal today splits the pair across two months:
     * reversing v3 on 1 September while v3 and v4 both sit on 23 August leaves
     * AUGUST carrying two full wage accruals and SEPTEMBER a negative one.
     * Everything balances, so nothing complains - the books are simply wrong in
     * both months until the fiscal year closes over them.
     *
     * Found on cutover day, 1 September 2026, before Joy reopened the approved
     * August run to add three advances. Journal 1 was live: "Payroll August
     * 2026 v3", dated 2026-08-23.
     *
     * ⚠ If that month has since been CLOSED the period lock refuses, and the
     * whole transition is refused with it. That is the right answer: reopening
     * a payroll whose month is closed is a decision about the month, and
     * somebody has to make it out loud.
     */
    public function reverse(PayrollRun $run, User $reversedBy): ?Journal
    {
        $journal = $this->existingJournal($run);

        if ($journal === null || $journal->hasBeenReversed()) {
            return null;
        }

        return app(ReverseJournal::class)->handle(
            $journal,
            $reversedBy,
            Carbon::parse($journal->journal_date)->startOfDay(),
        );
    }

    public function existingJournal(PayrollRun $run): ?Journal
    {
        return Journal::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }

    /**
     * ⚠⚠ The END OF THE PERIOD THE WORK WAS DONE IN, not the approval date.
     * Approving July's payroll on 3 August must not put July's wages into
     * August - that is the whole difference between accrual and cash basis, and
     * getting it wrong makes every month's profit wrong by one month's wages
     * until the pattern happens to be stable.
     */
    private function accountingDate(PayrollRun $run): Carbon
    {
        $end = $run->period?->end_date;

        return $end ? Carbon::parse($end)->startOfDay() : Carbon::today();
    }
}
