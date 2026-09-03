<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Models\User;
use App\Modules\Hrm\Models\ExpenseClaim;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The accountant's rule (3 Sep 2026), posted at the moment it becomes true:
 *
 *   At FINAL approval:  Dr  the category's expense account
 *                           Cr  Employee Reimbursements Payable (party = employee)
 *
 * The expense exists the day the office said yes - the cash has not moved,
 * and the books say exactly that. Settlement later clears the payable.
 *
 * ⚠ Posts only for claims that carry a category (legacy claims keep the
 * old settle-time voucher), only once (approval_journal_id is the guard),
 * and only when accounting is on - the same anchor every payroll posting
 * checks.
 */
class PostExpenseClaimApproval
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(ExpenseClaim $claim, User $approvedBy): ?Journal
    {
        if ($claim->expense_category_id === null || $claim->approval_journal_id !== null) {
            return null;
        }

        $on = Carbon::now()->startOfDay();

        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null) {
            return null;
        }

        $category = DB::table('expense_categories')->where('id', $claim->expense_category_id)->first();
        $payable = Account::bySlug('employee_reimbursements_payable');

        if ($category === null || $payable === null) {
            return null;
        }

        $amount = Money::ofTaka((string) $claim->amount);
        $employee = $claim->employee;

        $journal = $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: [
                JournalLineDraft::debit((int) $category->account_id, $amount, $claim->purpose, $employee),
                JournalLineDraft::credit($payable->id, $amount, 'Owed to '.$employee?->fullName(), $employee),
            ],
            memo: "Expense claim {$claim->claim_number} approved - {$category->name}",
            source: $claim,
        ), $approvedBy);

        $claim->forceFill([
            'approval_journal_id' => $journal->id,
            'expense_account_id' => $category->account_id,
        ])->save();

        return $journal;
    }
}
