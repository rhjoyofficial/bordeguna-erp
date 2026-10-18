<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A refund recorded by mistake, undone: Dr 1160 / Cr the money account, and
 * the advance is open again by the refunded amount.
 */
class ReverseSupplierAdvanceRefund extends ReversePurchasingDocument
{
    public function __construct(ReverseJournal $reverseJournal, private readonly PostSupplierAdvanceJournals $posting)
    {
        parent::__construct($reverseJournal);
    }

    /**
     * @throws ValidationException
     */
    public function handle(SupplierAdvanceRefund $refund, User $by, string $reason, bool $onOriginalDate = false): SupplierAdvanceRefund
    {
        return $this->reverseDocument($refund, $by, $reason, $onOriginalDate);
    }

    protected function label(Model $document): string
    {
        return "Advance refund {$document->number}";
    }

    protected function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->refunded_on->toDateString());
    }

    protected function journalOf(Model $document): ?Journal
    {
        return $this->posting->existingJournal($document);
    }

    protected function restore(Model $document): array
    {
        return [];
    }
}
