<?php

namespace App\Modules\Accounting\Corrections;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): a manual journal IS its ledger
 * entry. Only a hand-made journal (no source document) that is not itself a
 * reversal is put right here; one another screen made is put right there.
 */
class ManualJournalLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof Journal) {
            return null;
        }

        if ($document->source_type !== null) {
            return new Blocker('not_manual', 'Another part of the system made this entry. Correct it there.');
        }

        if ($document->isReversal()) {
            return new Blocker('is_reversal', 'This entry is itself a reversal. Post a new journal if it is wrong.');
        }

        return null;
    }
}
