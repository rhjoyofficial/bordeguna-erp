<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AgeingBucket;
use Illuminate\Support\Collection;

/**
 * One party's line on an ageing report.
 *
 * ⚠⚠ `onAccount` is not decoration. It is money that genuinely changes what the
 * party owes and belongs to no document, so it can sit in no age bucket:
 * unallocated receipts, unapplied sales-return credit, unallocated supplier
 * payments, and customer refunds in the other direction. Leaving it out is the
 * standard ageing defect and shows up as a report whose total does not equal
 * the balance sheet. See C-GLA-11.
 */
final class AgeingRow
{
    /**
     * @param  Collection<int, AgeingDocument>  $documents
     */
    public function __construct(
        public readonly int $partyId,
        public readonly string $partyName,
        public readonly Collection $documents,
        public readonly Money $onAccount,
    ) {}

    public function bucket(AgeingBucket $bucket): Money
    {
        return Money::sum(
            $this->documents
                ->filter(fn (AgeingDocument $document) => $document->bucket() === $bucket)
                ->map(fn (AgeingDocument $document) => $document->outstanding)
        );
    }

    public function aged(): Money
    {
        return Money::sum($this->documents->map(fn (AgeingDocument $document) => $document->outstanding));
    }

    public function total(): Money
    {
        return $this->aged()->plus($this->onAccount);
    }

    /**
     * ⚠ A party whose every column is zero is not shown (C-GLA-14), the same
     * reasoning as `TrialBalanceRow::isWorthShowing()`. A list of every customer
     * who ever existed, all at zero, is a report nobody reads.
     *
     * ⚠ The test is on the DOCUMENTS and the on-account figure separately, not
     * on the total: a party with a 5,000 overdue invoice and 5,000 sitting
     * unallocated nets to zero and is precisely the row somebody needs to see,
     * because the fix is an allocation nobody has made.
     */
    public function isWorthShowing(): bool
    {
        return $this->documents->isNotEmpty() || ! $this->onAccount->isZero();
    }
}
