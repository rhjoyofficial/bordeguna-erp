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
use App\Modules\Hrm\Models\LoanLedgerEntry;
use Illuminate\Support\Carbon;

/**
 * An employee paying off a loan early, in the books.
 *
 *   Dr  the account the money came into
 *       Cr  Employee Advances and Loans (party = employee)
 *
 * ⚠⚠ Found 3 Oct 2026: early settlement wrote only the HR loan ledger, so the
 * loan read "settled" in HRM while GL 1170 still said the employee owed it. The
 * mirror image of {@see PostLoanDisbursement}; between the two, 1170 per
 * employee and the loan module's outstanding balance agree.
 *
 * ⚠ The source is the settlement's ledger entry, not the loan: the loan is
 * already the source of its disbursement journal, and existingJournal() there
 * reads "any journal sourced from this loan".
 */
class PostLoanSettlement
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(Loan $loan, LoanLedgerEntry $entry, ?MoneyAccount $moneyAccount, ?User $postedBy): ?Journal
    {
        if ($postedBy === null || $this->existingJournal($entry) !== null) {
            return null;
        }

        $on = Carbon::parse($entry->occurred_on)->startOfDay();

        // Same anchor as the disbursement: a business without accounting on
        // settles exactly as it did before.
        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null) {
            return null;
        }

        if ($moneyAccount === null) {
            throw MappingNotFound::for('the account this settlement was received into', $on);
        }

        if ($moneyAccount->account_id === null) {
            throw MappingNotFound::for("money account [{$moneyAccount->name}]", $on);
        }

        $amount = Money::ofTaka((string) abs((float) $entry->amount));

        if ($amount->isZero()) {
            return null;
        }

        $advances = AccountMapping::resolve('payroll.employee_advances', $on);
        $bank = Account::findOrFail($moneyAccount->account_id);

        return $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit($bank->id, $amount, $moneyAccount->label(), null, $moneyAccount),
                JournalLineDraft::credit($advances->id, $amount, $loan->reference, $loan->employee),
            ],
            memo: "{$loan->type->label()} {$loan->reference} settled early",
            source: $entry,
        ), $postedBy);
    }

    public function existingJournal(LoanLedgerEntry $entry): ?Journal
    {
        return Journal::query()
            ->where('source_type', $entry->getMorphClass())
            ->where('source_id', $entry->getKey())
            ->whereNull('reverses_journal_id')
            ->first();
    }
}
