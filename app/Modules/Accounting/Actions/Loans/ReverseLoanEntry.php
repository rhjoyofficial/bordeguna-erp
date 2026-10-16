<?php

namespace App\Modules\Accounting\Actions\Loans;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Models\LoanWriteOff;
use App\Modules\Accounting\Support\Loans\LoanPosition;
use App\Modules\Accounting\Support\Loans\LoanStatusSync;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undoes one register movement the only way the ledger allows: its journal is
 * reversed through ReverseJournal (the existing reversal path), and the entry
 * stops counting, so the installments it paid are open again and the loan's
 * status follows (a settled loan becomes active again).
 *
 * ⚠ A reason is required, as on a voucher.
 *
 * ⚠ Dated the movement's own day when that period is open (the register and
 * the ledger then agree on every day in between), else the first open day.
 *
 * ⚠ A MANUAL entry belongs to its voucher or journal: it is undone by
 * reversing that voucher, which unlinks it (markReversed).
 */
class ReverseLoanEntry
{
    public function __construct(private readonly ReverseJournal $reverseJournal) {}

    public function handle(LoanEntry $entry, User $by, string $reason): LoanEntry
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this is being reversed.']);
        }

        if (! $entry->kind->reversibleHere()) {
            throw ValidationException::withMessages(['entry' => 'This came from a voucher that names the loan. Reverse that voucher, and the loan follows.']);
        }

        try {
            return DB::transaction(function () use ($entry, $by, $reason) {
                $loan = LoanContract::query()->lockForUpdate()->findOrFail($entry->loan_contract_id);
                $entry = LoanEntry::query()->lockForUpdate()->findOrFail($entry->id);

                if (! $entry->isLive()) {
                    throw ValidationException::withMessages(['entry' => 'This is already reversed.']);
                }

                // ⚠ Bug hunt 16 Oct 2026: reversing a disbursement or an accrual
                // that a later repayment drew on left the register below zero
                // (principal or accrued interest negative, on a loan still
                // carrying that repayment). Those are undone first.
                $position = LoanPosition::of($loan);

                if ($position->principal - (int) $entry->principal_minor < 0 || $position->accrued - (int) $entry->accrued_interest_minor < 0) {
                    throw ValidationException::withMessages(['entry' => 'A later repayment depends on this entry. Reverse that repayment first, then this one.']);
                }

                $reversal = null;

                if ($entry->journal_id !== null) {
                    $journal = Journal::query()->findOrFail($entry->journal_id);
                    $on = AccountingPeriod::firstOpenOnOrAfter($entry->entry_date->copy());

                    $reversal = $this->reverseJournal->handle(
                        $journal,
                        $by,
                        $on,
                        "Reversal of {$entry->kind->label()} on {$loan->loan_number}: {$reason}",
                    );
                }

                $entry->update([
                    'reversal_journal_id' => $reversal?->id,
                    'reversal_reason' => $reason,
                    'reversed_by' => $by->id,
                    'reversed_at' => now(),
                    // ⚠ The month can be accrued again once its accrual is undone.
                    'accrual_key' => null,
                ]);

                if ($entry->loan_write_off_id !== null && in_array($entry->kind, [LoanEntryKind::WriteOff, LoanEntryKind::Waiver], true)) {
                    LoanWriteOff::query()->whereKey($entry->loan_write_off_id)->update(['status' => 'reversed']);
                }

                LoanStatusSync::refresh($loan);

                activity()->performedOn($loan)->causedBy($by)
                    ->withProperties(['entry_id' => $entry->id, 'reason' => $reason, 'journal' => $reversal?->journal_number])
                    ->log("Reversed {$entry->kind->label()} on {$loan->loan_number}");

                return $entry->fresh();
            });
        } catch (JournalException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['entry' => $e->getMessage()]);
        }
    }
}
