<?php

namespace App\Modules\Accounting\Corrections\Concerns;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\CommitContext;
use App\Modules\Foundation\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ Corrections wave 2, accounts (B7, 24 Oct 2026): what every accounts
 * corrector does the same way. The maker and last editor (for the second
 * person), the one live journal a document posted, the bank-match count and
 * the period boundary that go into every fingerprint, and the Reversible
 * marking.
 */
trait CorrectsAccountsDocuments
{
    /** @return list<int> */
    public function makerAndLastEditorIds(Model $document): array
    {
        $lastEditor = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'updated')
            ->whereNotNull('causer_id')
            ->latest('id')
            ->value('causer_id');

        $maker = $document->getAttribute('created_by');

        return array_values(array_unique(array_filter([$maker === null ? null : (int) $maker, $lastEditor === null ? null : (int) $lastEditor])));
    }

    /** The one live (not reversed, not a reversal) journal whose source is the document, or null. */
    protected function liveJournalOf(Model $document): ?Journal
    {
        $live = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->get();

        return $live->count() === 1 ? $live->first() : null;
    }

    /** Locks the document's journals too, so a reversal by another screen waits for this one. */
    protected function lockJournalsOf(Model $document): void
    {
        Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * The journals of the document, the bank matches on their lines, and the
     * period boundary: any change to one of them means "look again".
     *
     * @return array<string, mixed>
     */
    protected function ledgerState(Model $document): array
    {
        $journals = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id'])
            ->map(fn (Journal $j) => [(int) $j->id, $j->reverses_journal_id === null ? null : (int) $j->reverses_journal_id])
            ->all();

        $ids = array_map(fn (array $pair) => $pair[0], $journals);
        $matched = $ids === [] ? 0 : DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $ids)->select('id'))
            ->count();
        $lock = AccountingPeriod::current();

        return [
            'journals' => $journals,
            'matched' => $matched,
            'period' => [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked],
        ];
    }

    /** The Reversible columns; money fields are never touched. */
    protected function markReversed(Model $document, ?Journal $reversal, CommitContext $ctx): void
    {
        $document->forceFill([
            'reversed_on' => $ctx->dates->reversalDate,
            'reversed_at' => now(),
            'reversed_by' => $ctx->actor->id,
            'reversal_reason' => $ctx->reason->display(),
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }

    /** The reversal journal undoes exactly the original's journal, and the document is marked. */
    protected function assertReversedBy(Model $original, string $number): void
    {
        if ($original->getAttribute('reversed_at') === null) {
            throw new LogicException("{$number} was not marked.");
        }

        $reversal = $original->getAttribute('reversal_journal_id') === null ? null : Journal::query()->find($original->getAttribute('reversal_journal_id'));

        if ($reversal === null || $reversal->reverses_journal_id === null) {
            throw new LogicException("{$number}'s reversal is not a reversing journal.");
        }
    }
}
