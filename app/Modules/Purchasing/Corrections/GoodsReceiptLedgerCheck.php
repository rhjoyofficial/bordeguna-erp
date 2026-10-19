<?php

namespace App\Modules\Purchasing\Corrections;

use App\Modules\Foundation\Corrections\Blocker;
use App\Modules\Foundation\Corrections\Contracts\DependencyCheck;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectKind;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Support\ReceiptJournals;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ A goods receipt stands on at most ONE live journal (corrections wave 1,
 * 19 Oct 2026; data check D1's rule).
 *
 * ⚠ Unlike Foundation's LedgerEntryCheck, a receipt with NO journal is fine:
 * a service-only delivery, or a business with inventory posting off, posts
 * nothing (PostPurchaseJournals::forReceipt), and its correction posts nothing
 * either. Several live journals are a data problem a person must look at.
 */
class GoodsReceiptLedgerCheck implements DependencyCheck
{
    public function covers(): EffectKind
    {
        return EffectKind::Ledger;
    }

    public function blocker(Model $document, DocumentAction $action): ?Blocker
    {
        if (! $action->isCorrection() || ! $document instanceof GoodsReceipt || $document->isReversed()) {
            return null;
        }

        if (ReceiptJournals::liveCount($document) <= 1) {
            return null;
        }

        return new Blocker(
            'no_ledger_entry',
            'This receipt has more than one ledger entry. Ask the administrator to check it.',
            resolvableBy: 'administrator',
        );
    }
}
