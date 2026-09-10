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
 * ⚠⚠ ONE JOURNAL FOR THE WHOLE CLAIM, ONE DEBIT PER ACCOUNT (10 Sep 2026, when
 * a claim became a header with lines). A market trip with four spends across
 * three heads posts ONE journal: three debits, one credit for the total. Lines
 * sharing a head are ADDED TOGETHER rather than posted twice, because the ledger
 * is a record of what was spent on what, not a transcript of the claim form.
 *
 * ⚠⚠ A DECLINED LINE DOES NOT POST. The approver refusing the taxi fare means
 * the business never owes it, so it must not reach an expense account and must
 * not swell the payable. It stays on the claim, with the reason, for the
 * employee to read.
 *
 * ⚠ The credit is the SUM OF THE DEBITS in minor units, never a separately
 * rounded total: adding paisa and then converting cannot disagree with itself,
 * and a journal that does not balance is refused by PostJournal anyway.
 *
 * ⚠ Posts only for lines that carry a category (legacy claims keep the
 * old settle-time voucher), only once (approval_journal_id is the guard),
 * and only when accounting is on - the same anchor every payroll posting
 * checks.
 */
class PostExpenseClaimApproval
{
    public function __construct(private readonly PostJournal $postJournal) {}

    public function handle(ExpenseClaim $claim, User $approvedBy): ?Journal
    {
        if ($claim->approval_journal_id !== null) {
            return null;
        }

        $on = Carbon::now()->startOfDay();

        if (AccountMapping::forKeyOn('payroll.net_payable', $on) === null) {
            return null;
        }

        $payable = Account::bySlug('employee_reimbursements_payable');

        if ($payable === null) {
            return null;
        }

        $claim->loadMissing('lines');
        $employee = $claim->employee;

        /** @var array<int, array{amount: Money, names: list<string>, purposes: list<string>}> $byAccount */
        $byAccount = [];

        foreach ($claim->payableLines() as $line) {
            $accountId = $line->postingAccountId();

            if ($accountId === null) {
                continue;
            }

            $category = DB::table('expense_categories')->where('id', $line->expense_category_id)->first();

            $byAccount[$accountId] ??= ['amount' => Money::zero(), 'names' => [], 'purposes' => []];
            $byAccount[$accountId]['amount'] = $byAccount[$accountId]['amount']->plus(Money::ofTaka((string) $line->amount));
            $byAccount[$accountId]['names'][] = $category->name ?? 'Expense';
            $byAccount[$accountId]['purposes'][] = $line->description ?: $claim->displayName();
        }

        if ($byAccount === []) {
            return null;
        }

        $lines = [];
        $total = Money::zero();

        foreach ($byAccount as $accountId => $bucket) {
            $lines[] = JournalLineDraft::debit(
                $accountId,
                $bucket['amount'],
                // The line's own words when there is one spend on this head,
                // and the category when several were merged into it.
                count($bucket['purposes']) === 1 ? $bucket['purposes'][0] : implode(', ', array_unique($bucket['names'])),
                $employee,
            );
            $total = $total->plus($bucket['amount']);
        }

        $lines[] = JournalLineDraft::credit($payable->id, $total, 'Owed to '.$employee?->fullName(), $employee);

        $heads = array_unique(array_merge(...array_column($byAccount, 'names')));

        $journal = $this->postJournal->handle(new JournalDraft(
            date: $on,
            lines: $lines,
            memo: "Expense claim {$claim->claim_number} approved - ".implode(', ', $heads),
            source: $claim,
        ), $approvedBy);

        // ⚠ Each line remembers the account it actually posted to. Remapping a
        // category next month must not rewrite where last month's money went,
        // and the printed voucher reads these rather than the category table.
        foreach ($claim->payableLines() as $line) {
            if ($line->expense_account_id === null && ($accountId = $line->postingAccountId()) !== null) {
                $line->forceFill(['expense_account_id' => $accountId])->save();
            }
        }

        $claim->forceFill(['approval_journal_id' => $journal->id])->save();
        $claim->load('lines');
        $claim->syncFromLines();

        return $journal;
    }
}
