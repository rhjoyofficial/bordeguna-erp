<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Sales\Actions\Corrections\UndoCustomerAdvanceDocument;
use App\Modules\Sales\Models\CustomerAdvanceRefund;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B11 (24 Oct 2026): an advance refund entered by mistake, undone. The
 * money comes back into its account and the advance is open again.
 */
class CustomerAdvanceRefundCorrector extends CustomerAdvanceDocumentCorrector
{
    public function __construct(CustomerAdvanceRefundPolicy $policy, UndoCustomerAdvanceDocument $undo)
    {
        parent::__construct($policy, $undo);
    }

    protected function modelClass(): string
    {
        return CustomerAdvanceRefund::class;
    }

    protected function noun(): string
    {
        return 'Advance refund';
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CustomerAdvanceRefund $document */
        $document->loadMissing(['moneyAccount:id,name', 'advance:id,number']);
        $money = $document->moneyAccount?->name ?? 'the money account';
        $from = $document->advance?->number ?? 'the on-account balance';

        return [
            ...$this->ledgerLines($document, $dates, 'up', "comes back into {$money}", $money),
            new EffectLine(Tk::of($this->totalMinor($document))." is open again on {$from}.", null, null, 'allocation'),
        ];
    }
}
