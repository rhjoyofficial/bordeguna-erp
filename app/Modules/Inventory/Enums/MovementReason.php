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
    // ⚠⚠ Re-checked on 2026-08-12, immediately before adding this: still
    // nothing in the codebase `match`es on MovementReason. Goods a customer
    // sends back are not a receipt and not an adjustment - they restore the
    // very layers a sale consumed, and the reason says so.
    case SalesReturn = 'sales_return';
    // ⚠⚠ Re-checked on 2026-08-17, immediately before adding this: still
    // nothing in the codebase `match`es on MovementReason (only the model
    // cast references the enum), and no reason-label map exists in the
    // frontend. Stock consumed by a production run and the batch it
    // produces are neither a usage nor a receipt: the value did not leave
    // the business and did not arrive from outside, it changed shape.
    case Production = 'production';
    // ⚠⚠ Re-checked on 2026-10-17, immediately before adding this: still
    // nothing in the codebase `match`es on MovementReason, and no reason-label
    // map exists in the frontend. A landed cost capitalised onto a batch is a
    // value-only movement (quantity 0, value_delta set): not a correction
    // somebody raised, but a cost of the goods that arrived late, and the
    // batch trace should say so.
    case LandedCost = 'landed_cost';
    // ⚠⚠ Re-checked on 2026-10-17 (Phase 2), immediately before adding this:
    // still nothing in the codebase `match`es on MovementReason. A proven
    // defect on a staff purchase is replaced at ZERO charge (office order,
    // ruling D7): the replacement unit leaves stock at its batch cost as a
    // loss, linked to the original request, with no new sale and no quota use.
    case StaffReplacement = 'staff_replacement';
}
