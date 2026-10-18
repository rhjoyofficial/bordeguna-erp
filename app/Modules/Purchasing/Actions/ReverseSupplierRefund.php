<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Purchasing\Models\SupplierRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A supplier refund recorded by mistake, undone (ruling P5): the mirror
 * journal, Dr 2110 (party) / Cr the money account, so the supplier owes us
 * that amount again. Reason required; the document is kept and marked.
 */
class ReverseSupplierRefund extends ReversePurchasingDocument
{
    /**
     * @throws ValidationException
     */
    public function handle(SupplierRefund $refund, User $by, string $reason, bool $onOriginalDate = false): SupplierRefund
    {
        return $this->reverseDocument($refund, $by, $reason, $onOriginalDate);
    }

    protected function label(Model $document): string
    {
        return "Supplier refund {$document->number}";
    }

    protected function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->received_on->toDateString());
    }

    protected function journalOf(Model $document): ?Journal
    {
        return RecordSupplierRefund::journalOf($document);
    }

    protected function restore(Model $document): array
    {
        return [];
    }
}
