<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An advance applied to the wrong bill, or for the wrong amount, undone.
 *
 *   the journal   Dr 1160 / Cr 2110 (both with the supplier)
 *   the bill      open again by exactly what the application took: its
 *                 `advance_applied` adjustment is removed, as a set-off's is
 *   the advance   open again by the same amount (derived from live rows)
 *
 * Nothing depends on an application, so it is never refused for that reason.
 */
class ReverseSupplierAdvanceApplication extends ReversePurchasingDocument
{
    public function __construct(ReverseJournal $reverseJournal, private readonly PostSupplierAdvanceJournals $posting)
    {
        parent::__construct($reverseJournal);
    }

    /**
     * @throws ValidationException
     */
    public function handle(SupplierAdvanceApplication $application, User $by, string $reason, bool $onOriginalDate = false): SupplierAdvanceApplication
    {
        return $this->reverseDocument($application, $by, $reason, $onOriginalDate);
    }

    protected function label(Model $document): string
    {
        return "Advance application {$document->number}";
    }

    protected function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->applied_on->toDateString());
    }

    protected function journalOf(Model $document): ?Journal
    {
        return $this->posting->existingJournal($document);
    }

    protected function restore(Model $document): array
    {
        // ⚠ Its allocation cascades, so the bill's outstanding, the ageing and
        // the statements see the bill open again from now on.
        if ($document->party_adjustment_id !== null) {
            PartyAdjustment::query()->whereKey($document->party_adjustment_id)->delete();
        }

        return ['party_adjustment_id' => null];
    }
}
