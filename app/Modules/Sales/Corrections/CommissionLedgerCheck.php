<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Checks\LedgerEntryCheck;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Sales\Models\CommissionSettlement;
use Illuminate\Database\Eloquent\Model;

/**
 * A commission settlement's ledger side (25 Oct 2026): one that went to
 * payroll posted nothing itself, so it has no journal to find; every other one
 * has exactly one (LedgerEntryCheck). An owed settlement that was partly or
 * fully paid is reversed only after its payments are.
 */
class CommissionLedgerCheck implements DependencyCheck
{
    public function __construct(private readonly LedgerEntryCheck $ledger) {}

    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        /** @var CommissionSettlement $document */
        if (! $action->isCorrection() || $document->method === 'payroll') {
            return null;
        }

        $paid = $document->payments()->live()->pluck('number');

        if ($paid->isNotEmpty()) {
            return new Blocker('commission_paid', "{$document->number} was paid by {$paid->implode(', ')}, so reverse the payment first.");
        }

        return $this->ledger->blocker($document, $action);
    }
}
