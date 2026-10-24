<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Sales\Actions\Corrections\UndoCustomerAdvanceDocument;
use App\Modules\Sales\Models\CustomerAdvance;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B11 (24 Oct 2026): an advance received (the money goes back out of its
 * account) or moved from a cancelled order (the money goes back to the order,
 * which holds it again and offers Refund or Move to advance once more).
 */
class CustomerAdvanceCorrector extends CustomerAdvanceDocumentCorrector
{
    public function __construct(CustomerAdvanceActionPolicy $policy, UndoCustomerAdvanceDocument $undo)
    {
        parent::__construct($policy, $undo);
    }

    protected function modelClass(): string
    {
        return CustomerAdvance::class;
    }

    protected function noun(): string
    {
        return 'Advance';
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CustomerAdvance $document */
        $document->loadMissing(['moneyAccount:id,name', 'salesOrder:id,order_number']);

        if ($document->isFromOrder()) {
            $order = $document->salesOrder?->order_number ?? 'the order';

            return [
                ...$this->ledgerLines($document, $dates, 'down', "goes back to order {$order}, held for the customer", 'Accounts Receivable'),
                new EffectLine("Order {$order} can then be refunded or moved to the advance again.", null, null, 'allocation'),
            ];
        }

        $money = $document->moneyAccount?->name ?? 'the money account';

        return $this->ledgerLines($document, $dates, 'down', "comes out of {$money}", $money);
    }
}
