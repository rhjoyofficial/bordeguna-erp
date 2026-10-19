<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ Corrections wave 1 (20 Oct 2026): a purchase return has at most ONE live
 * journal. A return that moved no stock value (a service line, or a business
 * whose stock posting was not set up yet) has none, and that is not a data
 * problem: its reversal simply posts nothing.
 */
class PurchaseReturnLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof PurchaseReturn || $document->isReversed()) {
            return null;
        }

        $live = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->count();

        return $live <= 1 ? null : new Blocker('no_ledger_entry', 'This record has more than one ledger entry. Ask the administrator to check it.', resolvableBy: 'administrator');
    }
}
