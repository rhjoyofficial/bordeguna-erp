<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Exceptions\MappingNotFound;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Hrm\Models\DisbursementBatch;
use Illuminate\Support\Carbon;

/**
 * Records the moment salary money actually leaves an account.
 *
 *   Dr  Salaries and Wages Payable   ONE LINE PER EMPLOYEE, clearing the accrual
 *       Cr  Cash at Bank / Cash in Hand   the money account's own ledger account
 *
 * ⚠ The credit account comes from `money_accounts.account_id`, not from a
 * mapping key. The mapping answers "what kind of thing is this"; a bank account
 * already knows what it is, and routing it through a key would let two places
 * disagree about where one account reports.
 *
 * ⚠ Lives in HRM for the same reason as PostPayrollAccrual: the ledger never
 * learns what a disbursement batch is.
 */
class PostPayrollPayment
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(DisbursementBatch $batch, MoneyAccount $moneyAccount, User $postedBy, ?Carbon $paidOn = null): ?Journal
    {
        if ($this->existingJournal($batch) !== null) {
            return null;
        }

        $on = $paidOn ?? Carbon::today();

        // ⚠ The same anchor as the accrual: a business that has not switched
        // accounting on pays its people exactly as it did before and posts
        // nothing. See PostPayrollAccrual::isConfigured for why this distinction
        // exists at all.
        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null) {
            return null;
        }

        // ⚠⚠ A money account with no ledger account cannot be posted from, and
        // that has to STOP the confirmation rather than post the credit
        // somewhere plausible. The screen that creates the account offers the
        // field; leaving it blank is a setup step nobody has finished, and the
        // loud failure is what makes somebody finish it.
        if ($moneyAccount->account_id === null) {
            throw MappingNotFound::for("money account [{$moneyAccount->name}]", $on);
        }

        $payable = AccountMapping::resolve('payroll.net_payable', $on);
        $bank = Account::findOrFail($moneyAccount->account_id);

        $lines = [];
        $total = Money::zero();

        foreach ($batch->payslips()->with('employee')->get() as $payslip) {
            $net = Money::ofTaka((string) $payslip->net);

            if ($net->isZero()) {
                continue;
            }

            // ⚠ Per employee, clearing exactly the lines the accrual created.
            // A single aggregated debit would balance and would make "who are we
            // still owing" unanswerable from the ledger.
            $lines[] = JournalLineDraft::debit($payable->id, $net, $payslip->employee?->employee_code, $payslip->employee);
            $total = $total->plus($net);
        }

        if ($lines === [] || $total->isZero()) {
            return null;
        }

        $lines[] = JournalLineDraft::credit(
            $bank->id,
            $total,
            $moneyAccount->label(),
            null,
            $moneyAccount,
        );

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: 'Salary payment from '.$moneyAccount->label(),
            source: $batch,
        ), $postedBy);
    }

    public function existingJournal(DisbursementBatch $batch): ?Journal
    {
        return Journal::query()
            ->where('source_type', $batch->getMorphClass())
            ->where('source_id', $batch->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
