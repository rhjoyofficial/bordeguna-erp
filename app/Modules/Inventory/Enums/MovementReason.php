<?php

namespace App\Modules\Inventory\Enums;

enum MovementReason: string
{
    case Receipt = 'receipt';
    case Usage = 'usage';
    // ⚠ A5 of the boundary review predicted this: `Usage` was a catch-all that
    // had to distinguish a sale from an internal issue once Sales existed.
    // Stock leaving because a customer bought it is not stock the business
    // consumed, and only one of the two has a cost of goods sold behind it.
    //
    // ⚠⚠ Re-checked on 2026-08-04, immediately before adding this: nothing in
    // the codebase `match`es on MovementReason, so no exhaustive match breaks.
    // That check is not ceremony - adding a case to a shared enum has already
    // broken every exhaustive match on this project once.
    case Sale = 'sale';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case PurchaseReturn = 'purchase_return';
}
