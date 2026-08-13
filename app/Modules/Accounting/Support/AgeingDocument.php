<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AgeingBucket;
use Illuminate\Support\Carbon;

/**
 * One unsettled invoice or bill, aged as at a date.
 *
 * ⚠ `outstanding` is the AS-AT figure computed by `Ageing`, never the model's
 * own `outstanding()`. See C-GLA-8: the model sums every allocation ever made,
 * which answers a different question - what is outstanding TODAY - and would
 * silently subtract next month's payment from last month's ageing.
 */
final class AgeingDocument
{
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly Carbon $documentDate,
        public readonly ?Carbon $dueDate,
        public readonly Money $total,
        public readonly Money $outstanding,
        public readonly int $daysOverdue,
    ) {}

    /**
     * ⚠⚠ C-GLA-10. Both `due_date` columns are NULLABLE, and Bor De Guna has no
     * agreed credit terms yet, so most early documents will carry none. A null
     * due date is read as DUE ON THE DOCUMENT DATE - cash on delivery unless
     * terms were agreed, which is the conservative reading and the one that
     * cannot understate what is late. The screen states the rule, because a
     * reader who assumed 30 days would misread every bucket.
     */
    public function basisDate(): Carbon
    {
        return $this->dueDate ?? $this->documentDate;
    }

    public function usesFallbackDate(): bool
    {
        return $this->dueDate === null;
    }

    public function bucket(): AgeingBucket
    {
        return AgeingBucket::forDaysOverdue($this->daysOverdue);
    }
}
