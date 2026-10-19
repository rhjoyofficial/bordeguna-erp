<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\ProductionRun;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1, B4: a confirmed run has at most one live journal.
 *
 * ⚠ None is the ordinary case: a run whose waste is absorbed into the output
 * posts nothing (the value only changed shape inside Stock on Hand). Only
 * more than one is a data problem a person must look at.
 */
class ProductionLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof ProductionRun || ! $document->isConfirmed()) {
            return null;
        }

        $live = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->count();

        return $live <= 1 ? null : new Blocker('no_ledger_entry', 'This run has more than one ledger entry. Ask the administrator to check it.', resolvableBy: 'administrator');
    }
}
