<?php

namespace App\Modules\Purchasing\Models\Concerns;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PUR-003 (owner decision 14 Oct 2026): a Purchasing money document that may be
 * reversed - a supplier payment, a supplier credit note, an expense voucher.
 *
 * The document is KEPT and marked, never edited and never deleted: the paper
 * really existed, the journal it posted stands, and a mirror journal undoes it.
 * Every screen and every sum asks `isReversed()` / `scopeLive()` rather than
 * checking for absence. ReversePurchasingDocument writes the columns.
 */
trait Reversible
{
    public function initializeReversible(): void
    {
        $this->mergeFillable(['reversed_on', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_id']);
        $this->mergeCasts(['reversed_on' => 'date:Y-m-d', 'reversed_at' => 'datetime']);
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** Posted, or reversed (the document's status; see the class note). */
    public function status(): string
    {
        return $this->isReversed() ? 'reversed' : 'posted';
    }

    /** Documents not reversed. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->qualifyColumn('reversed_at'));
    }

    /**
     * Documents that stood on `$on`: dated by then and not yet reversed then. A
     * reversal is dated `reversed_on`, so a report as at an earlier day still
     * shows the document, exactly as the ledger did on that day.
     */
    public function scopeStandingOn(Builder $query, string $on): Builder
    {
        $column = $query->getModel()->qualifyColumn('reversed_on');

        return $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereDate($column, '>', $on));
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    /**
     * What the screens show about a reversal, or null for a live document.
     *
     * @return array{on: string|null, at: string|null, by: string|null, reason: string|null, journal: string|null}|null
     */
    public function reversalSummary(): ?array
    {
        if (! $this->isReversed()) {
            return null;
        }

        return [
            'on' => $this->reversed_on?->toDateString(),
            'at' => $this->reversed_at?->toDateTimeString(),
            'by' => $this->reversedBy?->name,
            'reason' => $this->reversal_reason,
            'journal' => $this->reversalJournal?->journal_number,
        ];
    }
}
