<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\OpeningStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4: an opening stock has the one Opening Balance
 * journal it should have (data check D1, stock's version). None is right only
 * when it was worth nothing or the ledger was not set up on that date.
 */
class OpeningStockLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof OpeningStock || $document->isReversed()) {
            return null;
        }

        $live = Journal::query()
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->whereNull('reverses_journal_id')
            ->whereDoesntHave('reversal')
            ->count();

        if ($live === 1) {
            return null;
        }

        if ($live === 0) {
            $document->loadMissing('lines.stockMovement');
            $due = AccountMapping::forKeyOn('inventory.stock_on_hand', Carbon::parse($document->as_at)->startOfDay()) !== null
                && ! $document->totalValue()->isZero();

            if (! $due) {
                return null;
            }
        }

        return new Blocker(
            'no_ledger_entry',
            $live === 0
                ? 'This opening stock has no ledger entry. Ask the administrator to check it.'
                : 'This opening stock has more than one ledger entry. Ask the administrator to check it.',
            resolvableBy: 'administrator',
        );
    }
}
