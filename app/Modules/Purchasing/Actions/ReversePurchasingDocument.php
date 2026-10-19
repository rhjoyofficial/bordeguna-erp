<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PUR-003 (owner decision 14 Oct 2026): undoing a Purchasing money document - a
 * supplier payment, a supplier credit note, an expense voucher - the only way
 * the ledger allows, a mirror journal through ReverseJournal.
 *
 * Before this, a typo on any of the three was permanent: no reverse route, the
 * ledger screen refuses a journal with a source document, and a manual journal
 * cannot touch Accounts Payable or GRNI.
 *
 * The owner's rules, the same for all three:
 *   - a REASON is required;
 *   - the reversal is dated TODAY, or the document's own date when the user
 *     chooses it and that period is still open (the period lock decides, here
 *     and again inside PostJournal);
 *   - the document is KEPT and marked reversed (on, at, by, reason, journal);
 *   - what it settled is restored (each subclass says what that is);
 *   - a money line already matched on a bank statement is refused until it is
 *     unmatched, or the bank reconciliation would keep a reversed line;
 *   - the activity log records it.
 *
 * Once only: the document is re-read under a lock and a second reversal is
 * refused, as ReverseJournal refuses a second reversal of one journal.
 */
abstract class ReversePurchasingDocument
{
    public function __construct(protected readonly ReverseJournal $reverseJournal) {}

    /** "Supplier payment #12", as the refusals and the journal memo name it. */
    abstract protected function label(Model $document): string;

    abstract protected function originalDate(Model $document): Carbon;

    abstract protected function journalOf(Model $document): ?Journal;

    /**
     * Refusals of this kind of document, under the lock, before anything moves.
     *
     * @throws ValidationException
     */
    protected function guard(Model $document): void {}

    /**
     * Restores what the document settled, inside the transaction. Returns any
     * extra columns to write on the document with the reversal.
     *
     * @return array<string, mixed>
     */
    abstract protected function restore(Model $document): array;

    /**
     * @template T of Model
     *
     * @param  T  $document
     * @return T
     *
     * @throws ValidationException
     */
    protected function reverseDocument(Model $document, User $by, string $reason, bool $onOriginalDate, ?Carbon $on = null, ?ClosedPeriodPass $pass = null): Model
    {
        $reason = trim($reason);
        $label = $this->label($document);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => "Say why {$label} is being reversed."]);
        }

        // ⭐ Corrections wave 1 (20 Oct 2026): the corrections process hands
        // the date its date policy resolved, and the Owner's one-document pass
        // when that date is in a closed month. Otherwise the old choice.
        $on = $on?->copy()->startOfDay() ?? ($onOriginalDate ? $this->originalDate($document)->copy()->startOfDay() : Carbon::today());

        // ⚠ Asked before anything is locked, so a closed period is a sentence
        // and not a rolled-back transaction. PostJournal asks again.
        AccountingPeriod::assertOpenFor($on, $pass);

        return DB::transaction(function () use ($document, $by, $reason, $on, $label, $pass) {
            $document = $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($document->isReversed()) {
                throw ValidationException::withMessages(['reason' => "{$label} is already reversed."]);
            }

            $this->guard($document);

            // ⭐ 20 Oct 2026 (party ledgers): a refund a Receipt voucher made
            // posted its line on the VOUCHER's journal, so the voucher is what
            // is reversed, and the refund goes with it.
            if ($document->getAttribute('voucher_id') !== null) {
                $number = DB::table('vouchers')->where('id', $document->getAttribute('voucher_id'))->value('voucher_number');

                throw ValidationException::withMessages([
                    'reason' => "{$label} was made by voucher {$number}. Reverse the voucher under Accounting, Vouchers; this goes with it.",
                ]);
            }

            $journal = $this->journalOf($document);
            $reversal = null;

            if ($journal !== null) {
                $this->assertNotMatchedOnBankStatement($journal, $label);

                $reversal = $this->reverseJournal->handle($journal->load('lines'), $by, $on, "Reversal of {$label}: {$reason}", $pass);
            }

            $extra = $this->restore($document);

            $document->forceFill([
                'reversed_on' => $on,
                'reversed_at' => now(),
                'reversed_by' => $by->id,
                'reversal_reason' => $reason,
                'reversal_journal_id' => $reversal?->id,
                ...$extra,
            ])->save();

            activity()
                ->performedOn($document)
                ->causedBy($by)
                ->withProperties(array_filter([
                    'reason' => $reason,
                    'reversed_on' => $on->toDateString(),
                    'journal' => $journal?->journal_number,
                    'reversal_journal' => $reversal?->journal_number,
                    'released_allocations' => $extra['reversed_allocations'] ?? null,
                ], fn ($value) => $value !== null))
                ->log('reversed');

            return $document->fresh();
        });
    }

    /**
     * ⚠ A money line the bank statement has claimed is real money the bank saw
     * move. Reversing it would leave the statement matched to a reversed line
     * and the reconciliation agreeing falsely, so it is unmatched first, on
     * purpose, by a person.
     *
     * @throws ValidationException
     */
    private function assertNotMatchedOnBankStatement(Journal $journal, string $label): void
    {
        $matched = DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', $journal->lines()->select('id'))
            ->exists();

        if ($matched) {
            throw ValidationException::withMessages([
                'reason' => "{$label} is matched to a bank statement line. Unmatch it under Accounting, Bank statements first, then reverse it.",
            ]);
        }
    }
}
