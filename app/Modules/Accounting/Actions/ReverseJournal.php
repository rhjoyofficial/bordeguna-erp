<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Exceptions\JournalException;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a posted journal by posting its mirror image.
 *
 * ⚠⚠ THE ONLY CORRECTION MECHANISM THERE IS. A posted journal is never edited
 * and never deleted, so both the mistake and its correction stay visible, and
 * that visibility is the audit trail rather than a by-product of it. It is the
 * same decision `reverses_movement_id` made for stock, and the same one
 * attendance corrections made for punches.
 *
 * ⚠ It goes through PostJournal like everything else. A reversal is not a
 * special kind of write - it is an ordinary journal whose lines happen to be
 * negated, and it faces every guard the original faced, including the period
 * lock.
 */
class ReverseJournal
{
    public function __construct(private readonly PostJournal $postJournal) {}

    /**
     * ⚠ Dated TODAY by default, not on the original's date. Back-dating a
     * reversal quietly changes a month that has already been reported; if the
     * original period is still open and somebody genuinely wants that, they pass
     * the date explicitly and the period lock still has the final say.
     */
    public function handle(Journal $journal, User $reversedBy, ?Carbon $on = null, ?string $memo = null): Journal
    {
        // ⚠⚠ The check and the reversal in ONE transaction, with the original
        // re-read under a lock: two people pressing Reverse together both
        // passed the unlocked check and posted two reversals. The second now
        // waits for the first to commit and then finds it reversed.
        return DB::transaction(function () use ($journal, $reversedBy, $on, $memo) {
            $journal = Journal::query()->whereKey($journal->getKey())->lockForUpdate()->firstOrFail();

            return $this->reverse($journal, $reversedBy, $on, $memo);
        });
    }

    private function reverse(Journal $journal, User $reversedBy, ?Carbon $on, ?string $memo): Journal
    {
        // ⚠ A journal may be reversed ONCE. Two reversals of one entry cancel
        // each other out and leave the original live again, which is a state
        // nobody reading the ledger would expect to find.
        if ($journal->hasBeenReversed()) {
            throw JournalException::alreadyReversed($journal->journal_number);
        }

        // ⚠ And a reversal is a leaf. Letting reversals chain turns "is this
        // entry still live?" into a walk rather than a fact, and the answer to
        // an un-done un-do is a fresh journal that says what is true now.
        if ($journal->isReversal()) {
            throw JournalException::reversalOfReversal($journal->journal_number);
        }

        $draft = new JournalDraft(
            date: $on ?? Carbon::today(),
            lines: $journal->lines->map(fn ($line) => JournalLineDraft::signed(
                accountId: $line->account_id,
                amount: $line->amount()->negated(),
                memo: $line->memo,
                party: $line->party,
                moneyAccount: $line->moneyAccount,
            ))->all(),
            memo: $memo ?? "Reversal of {$journal->journal_number}".($journal->memo ? ": {$journal->memo}" : ''),
            source: $journal->source,
            // ⚠ The reversal inherits the original's kind. Undoing an opening
            // balance is still an opening-balance matter, and filing it as
            // ordinary trading would make the opening photograph and the year's
            // movement disagree by exactly the amount being corrected.
            type: $journal->journal_type,
        );

        // ⚠ The link is passed to PostJournal rather than written afterwards, so
        // it lands inside the same transaction and no code anywhere has to
        // update a posted journal - not even this one, and least of all this
        // one.
        return $this->postJournal->handle($draft, $reversedBy, reverses: $journal);
    }
}
