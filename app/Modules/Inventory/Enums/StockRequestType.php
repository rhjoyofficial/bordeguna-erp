<?php

namespace App\Modules\Inventory\Enums;

/**
 * ⚠ `Adjustment` was removed in slice 3. A stock request is now ONLY a transfer
 * request. Every quantity or value correction goes through
 * `InventoryAdjustment`, which carries a type, an effective date, an approval
 * status, attachments and links to every movement it produced - none of which
 * this table had.
 *
 * Leaving the case in place would have left a second way to adjust stock, which
 * is the thing slice 3 exists to remove.
 */
enum StockRequestType: string
{
    case Transfer = 'transfer';
}
