<?php

namespace App\Modules\Sales\Corrections\Checks;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Models\SampleIssue;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Samples (25 Oct 2026): a given sample has the ONE live journal it
 * recorded, or none when it posted none (goods of no cost and no delivery
 * charge, or a business with no Stock on Hand mapping). Anything else is a
 * data problem a person must look at (LedgerEntryCheck's rule).
 */
class SampleLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof SampleIssue || $document->isReversed()) {
            return null;
        }

        $live = SalesLedgerCheck::liveJournals($document);
        $fine = $document->journal_id === null
            ? $live->isEmpty()
            : $live->count() === 1 && (int) $live->first()->id === (int) $document->journal_id;

        return $fine ? null : new Blocker(
            'no_ledger_entry',
            'This sample does not have the one ledger entry it recorded. Ask the administrator to check it.',
            resolvableBy: 'administrator',
        );
    }
}
