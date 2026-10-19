<?php

namespace App\Modules\Inventory\Corrections;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4: an approved adjustment has the ledger entry it
 * should have, and only one (data check D1, stock's version).
 *
 * ⚠ Unlike a voucher, NO journal can be right: a business that has not mapped
 * Stock on Hand adjusts stock without posting (PostInventoryAdjustmentJournal's
 * anchor), and a zero-value correction posts nothing. Only "none where one was
 * due" or "more than one" is a data problem a person must look at.
 */
class AdjustmentLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof InventoryAdjustment || $document->isReversed()) {
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
            $on = Carbon::parse($document->effective_date)->startOfDay();
            $due = AccountMapping::forKeyOn('inventory.stock_on_hand', $on) !== null
                && ! StockValue::sum($document->movements()->whereNull('reverses_movement_id')->get())->isZero();

            if (! $due) {
                return null;
            }
        }

        return new Blocker(
            'no_ledger_entry',
            $live === 0
                ? 'This correction moved stock but has no ledger entry. Ask the administrator to check it.'
                : 'This correction has more than one ledger entry. Ask the administrator to check it.',
            resolvableBy: 'administrator',
        );
    }
}
