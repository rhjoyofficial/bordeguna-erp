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
use App\Modules\Hrm\Models\Loan;
use Illuminate\Support\Carbon;

/**
 * Handing a staff loan or advance over.
 *
 *   Dr  Employee Advances and Loans (party = employee)
 *       Cr  the account the money came from
 *
 * ⚠ The mirror image of the recovery, which already posts: a payslip's
 * `loan_repayment` deduction credits the same account and reduces the asset.
 * Between the two, "what do the staff owe us" is a ledger balance rather than a
 * number somebody adds up from the loan module.
 *
 * ⚠ Lives in HRM. The ledger never learns what a loan is.
 */
class PostLoanDisbursement
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(Loan $loan, ?MoneyAccount $moneyAccount, ?User $postedBy): ?Journal
    {
        if ($postedBy === null || $this->existingJournal($loan) !== null) {
            return null;
        }

        $on = Carbon::parse($loan->disbursed_on ?? now())->startOfDay();

        // The same anchor as every other payroll posting: a business that has
        // not switched accounting on lends money exactly as it did before.
        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null) {
            return null;
        }

        // ⚠⚠ Once accounting IS on, a loan that cannot say where the cash came
        // from stops. This is the one question the disbursement screen has to
        // ask, and it is a fact rather than a default - the person handing the
        // money over knows which account it left.
        if ($moneyAccount === null) {
            throw MappingNotFound::for('the account this loan was paid from', $on);
        }

        if ($moneyAccount->account_id === null) {
            throw MappingNotFound::for("money account [{$moneyAccount->name}]", $on);
        }

        $advances = AccountMapping::resolve('payroll.employee_advances', $on);
        $bank = Account::findOrFail($moneyAccount->account_id);
        $principal = Money::ofTaka((string) $loan->principal);

        if ($principal->isZero()) {
            return null;
        }

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($advances->id, $principal, $loan->reference, $loan->employee),
                JournalLineDraft::credit($bank->id, $principal, $moneyAccount->label(), null, $moneyAccount),
            ],
            memo: "{$loan->type->label()} {$loan->reference} disbursed",
            source: $loan,
        ), $postedBy);
    }

    public function existingJournal(Loan $loan): ?Journal
    {
        return Journal::query()
            ->where('source_type', $loan->getMorphClass())
            ->where('source_id', $loan->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
