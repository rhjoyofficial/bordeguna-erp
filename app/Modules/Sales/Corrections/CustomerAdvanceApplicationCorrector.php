<?php

namespace App\Modules\Sales\Corrections;

use App\Modules\Foundation\Corrections\CorrectionDates;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\EffectLine;
use App\Modules\Foundation\Corrections\ReplacementData;
use App\Modules\Foundation\Corrections\Tk;
use App\Modules\Sales\Actions\Corrections\UndoCustomerAdvanceDocument;
use App\Modules\Sales\Models\CustomerAdvanceApplication;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B11 (24 Oct 2026): an advance used on the wrong invoice, or for the wrong
 * amount, undone. The invoice owes again by exactly what the use took; the
 * advance is open again by the same amount (derived from live rows).
 */
class CustomerAdvanceApplicationCorrector extends CustomerAdvanceDocumentCorrector
{
    public function __construct(CustomerAdvanceApplicationPolicy $policy, UndoCustomerAdvanceDocument $undo)
    {
        parent::__construct($policy, $undo);
    }

    protected function modelClass(): string
    {
        return CustomerAdvanceApplication::class;
    }

    protected function noun(): string
    {
        return 'Advance use';
    }

    public function describeEffects(Model $document, DocumentAction $action, ?ReplacementData $replacement, CorrectionDates $dates): array
    {
        /** @var CustomerAdvanceApplication $document */
        $document->loadMissing(['invoice:id,invoice_number', 'advance:id,number']);
        $total = $this->totalMinor($document);
        $invoice = $document->invoice?->invoice_number ?? 'the invoice';
        $from = $document->advance?->number ?? 'the on-account balance';

        return [
            ...$this->ledgerLines($document, $dates, 'up', 'goes back on what the customer owes', 'Accounts Receivable'),
            new EffectLine("Invoice {$invoice} is unpaid again by ".Tk::of($total).'.', null, $total, 'allocation'),
            new EffectLine(Tk::of($total)." is open again on {$from}.", null, null, 'allocation'),
        ];
    }
}
