<?php

namespace App\Modules\Purchasing\Support;

use DomainException;

/**
 * Ruling P5 (19 Oct 2026): the bill being recorded looks like one already on
 * the books (SupplierBillDuplicates). Refused until a reason is given; the
 * screen shows the bills it resembles and asks for that reason.
 */
final class LikelyDuplicateBill extends DomainException
{
    /**
     * @param  list<array{id: int, bill_number: string, bill_date: ?string, total: string, why: string}>  $matches
     */
    public function __construct(string $message, public readonly array $matches)
    {
        parent::__construct($message);
    }
}
