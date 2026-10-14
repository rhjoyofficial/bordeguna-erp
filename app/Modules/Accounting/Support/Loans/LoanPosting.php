<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Models\User;
use App\Modules\Accounting\Actions\PostJournal;
use App\Modules\Accounting\Enums\LoanEntryKind;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Foundation\Exceptions\PeriodClosedException;
use App\Modules\Foundation\Models\MoneyAccount;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⚠⚠ THE ONE WAY A LOAN MOVEMENT REACHES THE LEDGER: an entry, then its journal
 * through PostJournal (the only writer), in one transaction with the loan row
 * locked. The register never posts by itself; every caller is a person's
 * action (disburse, repay, accrue, an approved write-off).
 *
 *  - IDEMPOTENT: an entry carries the form's submission key, unique, so the
 *    same form sent twice is refused instead of booked twice; an accrual
 *    carries its accrual key, unique while live.
 *  - PERIOD LOCK: PostJournal checks it; a refusal rolls the entry back too.
 *  - The journal's SOURCE is the entry, so the journal screen never reverses
 *    it behind the register's back (JournalController::reverse refuses a
 *    sourced journal); ReverseLoanEntry does.
 */
final class LoanPosting
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * @param  list<JournalLineDraft>  $lines
     * @param  array<string, mixed>  $attributes  the entry's own amounts and keys
     * @param  (callable(LoanEntry, LoanPosition): void)|null  $allocate  settles installments, given the position BEFORE this entry
     */
    public function post(LoanContract $loan, LoanEntryKind $kind, Carbon $date, array $lines, array $attributes, string $memo, User $by, ?callable $allocate = null): LoanEntry
    {
        try {
            return DB::transaction(function () use ($loan, $kind, $date, $lines, $attributes, $memo, $by, $allocate) {
                // ⚠ Locked and re-read: two people pressing Repay on one loan
                // are served one after the other, each seeing the other's entry.
                $loan = LoanContract::query()->lockForUpdate()->findOrFail($loan->id);
                $before = LoanPosition::of($loan);

                $entry = LoanEntry::query()->create($attributes + [
                    'business_id' => $loan->business_id,
                    'loan_contract_id' => $loan->id,
                    'kind' => $kind,
                    'entry_date' => $date->toDateString(),
                    'created_by' => $by->id,
                ]);

                $journal = $this->postJournal->handle(new JournalDraft(
                    date: $date->copy()->startOfDay(),
                    lines: $lines,
                    memo: mb_strimwidth($memo, 0, 250, '…'),
                    source: $entry,
                ), $by);

                $entry->update(['journal_id' => $journal->id]);

                if ($allocate !== null) {
                    $allocate($entry, $before);
                }

                LoanStatusSync::refresh($loan);

                activity()
                    ->performedOn($loan)
                    ->causedBy($by)
                    ->withProperties(['entry_id' => $entry->id, 'kind' => $kind->value, 'journal' => $journal->journal_number])
                    ->log("{$kind->label()} recorded on {$loan->loan_number} ({$journal->journal_number})");

                return $entry->fresh(['journal']);
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'submission_key')) {
                throw ValidationException::withMessages(['loan' => 'This was already recorded: the same form was sent twice. Nothing new was booked.']);
            }

            if (str_contains($e->getMessage(), 'accrual_key')) {
                throw ValidationException::withMessages(['period' => "{$loan->loan_number}: this month's interest is already accrued under these terms. Nothing new was booked."]);
            }

            throw $e;
        } catch (JournalException|PeriodClosedException $e) {
            throw ValidationException::withMessages(['loan' => $e->getMessage()]);
        }
    }

    /**
     * A cash, bank or mobile account of this business that money may move
     * through by hand.
     */
    public static function moneyAccount(mixed $id, string $field = 'money_account_id'): MoneyAccount
    {
        $account = ($id === null || $id === '') ? null : MoneyAccount::query()->handled()->find((int) $id);

        if ($account === null || $account->business_id !== app(BusinessContext::class)->id()) {
            throw ValidationException::withMessages([$field => 'Choose one of this business\'s cash, bank or mobile accounts.']);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([$field => "{$account->name} is no longer active."]);
        }

        if (($refusal = $account->handRefusal()) !== null) {
            throw ValidationException::withMessages([$field => $refusal]);
        }

        if ($account->account_id === null) {
            throw ValidationException::withMessages([$field => "{$account->name} has no ledger head, so nothing can be booked through it."]);
        }

        return $account;
    }

    /** The day a movement is booked: a real day, not in the future, not before the loan starts. */
    public static function date(mixed $value, LoanContract $loan, string $field = 'date'): Carbon
    {
        try {
            $date = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Enter the date.']);
        }

        if ($date->isFuture()) {
            throw ValidationException::withMessages([$field => 'A loan movement cannot be dated in the future.']);
        }

        if ($date->lessThan($loan->start_date)) {
            throw ValidationException::withMessages([$field => "{$loan->loan_number} starts on {$loan->start_date->format('j M Y')}; nothing moves on it before then."]);
        }

        return $date;
    }

    /** A submission key from the form (a UUID the page makes), or null. */
    public static function submissionKey(mixed $value): ?string
    {
        $key = is_string($value) ? trim($value) : '';

        return $key === '' ? null : mb_substr($key, 0, 64);
    }
}
