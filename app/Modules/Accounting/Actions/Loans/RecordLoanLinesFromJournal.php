<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanStatusSync;
use App\Modules\Accounting\Support\Loans\RepaymentPlan;

/**
 * A voucher or manual journal whose lines NAME a register loan (the bank took
 * an installment straight from the account; the year-end reclassification)
 * becomes a movement of that loan, linked once to its journal.
 *
 * ⚠⚠ Without this the line would sit on the loan's ledger with nothing in the
 * register, and the two would disagree by exactly that voucher. With it, the
 * principal and accrued interest the lines move are the loan's, and any
 * reduction settles installments in due order.
 *
 * ⚠ Undone with its voucher: reversing the voucher (ReverseVoucher, or a
 * manual journal from the journal screen) calls markReversed, and the entry
 * stops counting either way because its journal is reversed (LoanEntry::live).
 */
class RecordLoanLinesFromJournal
{
    public function handle(Journal $journal, User $by): void
    {
        $groups = $journal->lines()->whereNotNull('loan_contract_id')->get()->groupBy('loan_contract_id');

        foreach ($groups as $loanId => $lines) {
            $loan = LoanContract::query()->lockForUpdate()->find((int) $loanId);

            if ($loan === null || LoanEntry::query()->where('loan_contract_id', $loan->id)->where('journal_id', $journal->id)->exists()) {
                continue;
            }

            $sign = $loan->direction->sign();
            $principalHeads = $loan->principalHeadIds();
            $principal = (int) $lines->whereIn('account_id', $principalHeads)->sum('amount_minor') * $sign;
            $accrued = (int) $lines->where('account_id', (int) $loan->interest_accrual_account_id)->sum('amount_minor') * $sign;

            $before = LoanPosition::of($loan);

            $entry = LoanEntry::query()->create([
                'business_id' => $loan->business_id,
                'loan_contract_id' => $loan->id,
                'kind' => LoanEntryKind::Manual,
                'entry_date' => $journal->journal_date->toDateString(),
                'journal_id' => $journal->id,
                'principal_minor' => $principal,
                'accrued_interest_minor' => $accrued,
                'interest_settled_minor' => max(0, -$accrued),
                'note' => $journal->memo,
                'created_by' => $by->id,
            ]);

            // A reduction settles installments: principal against principal,
            // accrued interest settled against interest.
            if ($principal < 0 || $accrued < 0) {
                RepaymentPlan::allocate($entry, $before, max(0, -$principal), max(0, -$accrued));
            }

            LoanStatusSync::refresh($loan);

            activity()->performedOn($loan)->causedBy($by)
                ->withProperties(['entry_id' => $entry->id, 'journal' => $journal->journal_number])
                ->log("{$journal->journal_number} names {$loan->loan_number}; recorded in the Loan Register");
        }
    }

    /** The voucher or journal was reversed: its loan movements stop counting. */
    public static function markReversed(Journal $original, ?Journal $reversal, User $by, string $reason): void
    {
        $entries = LoanEntry::query()->where('journal_id', $original->id)->whereNull('reversed_at')->get();

        foreach ($entries as $entry) {
            $entry->update([
                'reversal_journal_id' => $reversal?->id,
                'reversal_reason' => $reason,
                'reversed_by' => $by->id,
                'reversed_at' => now(),
                'accrual_key' => null,
            ]);

            $loan = LoanContract::query()->find($entry->loan_contract_id);

            if ($loan !== null) {
                LoanStatusSync::refresh($loan);
            }
        }
    }
}
