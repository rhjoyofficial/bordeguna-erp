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
    // ⚠⚠ Re-checked on 2026-10-17 (evening), immediately before adding this:
    // still nothing in the codebase `match`es on MovementReason, and no
    // reason-label map exists in the frontend. Stock the proprietor takes for
    // personal use is neither a sale (no customer, no revenue: ruling Q2) nor
    // a usage the business consumed: it leaves at the reserved batch's cost
    // against Owner's Drawings, and the batch trace should say so.
    case OwnerWithdrawal = 'owner_withdrawal';
    // ⚠⚠ Re-checked on 2026-10-17 (Phase 2), immediately before adding this:
    // still nothing in the codebase `match`es on MovementReason. A proven
    // defect on a staff purchase is replaced at ZERO charge (office order,
    // ruling D7): the replacement unit leaves stock at its batch cost as a
    // loss, linked to the original request, with no new sale and no quota use.
    case StaffReplacement = 'staff_replacement';
    // ⚠⚠ Re-checked on 2026-10-18, immediately before adding this: still
    // nothing in the codebase `match`es on MovementReason. A supplier's trade
    // or settlement discount takes cost OFF the batches it was bought in
    // (ruling P3): a value-only movement like a landed cost, the other way.
    case PurchaseDiscount = 'purchase_discount';
    // ⚠⚠ Re-checked on 2026-10-19, immediately before adding this: still
    // nothing in the codebase `match`es on MovementReason. A goods receipt
    // reversed or corrected (corrections wave 1) takes its own goods back out
    // of the batch it made, `reverses_movement_id` naming the receipt's
    // movement: neither a return to the supplier (no credit follows) nor an
    // adjustment somebody counted.
    case ReceiptCorrection = 'receipt_correction';
    // ⚠⚠ Re-checked on 2026-10-24, immediately before adding this: still
    // nothing in the codebase `match`es on MovementReason. Paddy harvested
    // from the leased land is not a receipt (no supplier, no GRNI) and not a
    // production run (nothing consumed from stock): the season's cost moves
    // out of the Crops in Progress head into the batch (RecordCropHarvest),
    // and the batch trace should say so. Its reversal uses the same reason,
    // `reverses_movement_id` naming the harvest's movement.
    case Harvest = 'harvest';
    // ⚠⚠ Re-checked on 2026-10-26, immediately before adding this: still
    // nothing in the codebase `match`es on MovementReason. Goods given away
    // free as a sample (Joy, 25 Oct 2026: tasting, a gift, marketing) are not
    // a sale (no customer pays, no revenue) and not a write-off (nothing was
    // lost): they leave at the batch's cost against Samples and Marketing
    // (Sales\Actions\PostSampleIssue), and the batch trace should say so. Its
    // reversal uses the same reason, `reverses_movement_id` naming the issue.
    case Sample = 'sample';
}
