<?php

namespace App\Modules\Purchasing\Corrections\Concerns;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Exceptions\CorrectionBlockedException;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Support\PurchasingApprovalGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): what the supplier money correctors share.
 *
 * Fingerprint parts (the journals a document has, how many of their lines a
 * bank statement claimed, the period boundary), the maker and last editor,
 * and the purchasing approval (ruling P7) on a corrected bill or payment.
 */
trait CorrectsPurchasingDocuments
{
    /** @return list<array{0: int, 1: ?int}> every journal whose source is the document, oldest first */
    protected function journalTrail(Model $document): array
    {
        return Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->orderBy('id')
            ->get(['id', 'reverses_journal_id'])
            ->map(fn (Journal $j) => [(int) $j->id, $j->reverses_journal_id === null ? null : (int) $j->reverses_journal_id])
            ->all();
    }

    /** How many lines of the document's journals a bank statement claimed. */
    protected function bankMatches(Model $document): int
    {
        $ids = array_map(fn (array $pair) => $pair[0], $this->journalTrail($document));

        return $ids === [] ? 0 : DB::table('bank_statement_lines')
            ->whereIn('matched_journal_line_id', DB::table('journal_lines')->whereIn('journal_id', $ids)->select('id'))
            ->count();
    }

    /** @return array{0: ?string, 1: bool} */
    protected function periodBoundary(): array
    {
        $lock = AccountingPeriod::current();

        return [$lock?->closed_through?->toDateString(), (bool) $lock?->is_unlocked];
    }

    protected function lockJournals(Model $document): void
    {
        Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->lockForUpdate()
            ->get(['id']);
    }

    /** @return list<int> the maker and the last person who changed it */
    protected function makerAndLastEditor(Model $document, string $makerColumn = 'created_by'): array
    {
        $lastEditor = Activity::query()
            ->where('subject_type', $document->getMorphClass())
            ->where('subject_id', $document->getKey())
            ->where('event', 'updated')
            ->whereNotNull('causer_id')
            ->latest('id')
            ->value('causer_id');

        return array_values(array_unique(array_filter([(int) $document->getAttribute($makerColumn), $lastEditor === null ? null : (int) $lastEditor])));
    }

    /**
     * ⭐ Purchasing approvals (ruling P7) on the corrected document. The
     * correction's own second person stands in for the purchasing second
     * person ONLY when that person is a purchasing approver (Owner or Manager
     * by Finance policy) and not the one who asked; otherwise the corrected
     * document must pass the gate like any new one, and a refusal is a plain
     * sentence that rolls the whole correction back.
     *
     * @template T
     *
     * @param  callable(): T  $record
     * @return T
     */
    protected function underPurchasingApproval(callable $record, User $actor, ?User $approver, string $what): mixed
    {
        $gate = app(PurchasingApprovalGate::class);
        $businessId = (int) app(BusinessContext::class)->id();

        if ($approver !== null && $approver->id !== $actor->id && $gate->isApprover($approver, $businessId)) {
            return PurchasingApprovalGate::releasedForCorrection($record);
        }

        try {
            return $record();
        } catch (PurchaseApprovalRequired $e) {
            $labels = implode('; ', array_column($e->reasons, 'label'));

            throw ValidationException::withMessages([
                'replacement' => "The corrected {$what} needs a purchasing approval ({$labels}). Reverse it and record the right one under Purchases, so it goes for approval.",
            ]);
        }
    }

    /**
     * A reversed or corrected record is refused with the policy's own sentence
     * BEFORE its form is checked, so a second Correct says "already corrected",
     * never a form error caused by its own successor.
     *
     * @throws CorrectionBlockedException
     */
    protected function refuseWhenDone(Model $document): void
    {
        if ($document->isReversed()) {
            throw new CorrectionBlockedException([new Blocker('already_reversed', "{$this->policy()->numberOf($document)} was already reversed or corrected.")]);
        }
    }
}
