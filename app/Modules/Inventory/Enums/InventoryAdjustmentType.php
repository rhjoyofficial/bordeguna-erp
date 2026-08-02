<?php

namespace App\Modules\Inventory\Enums;

/**
 * Why an inventory correction happened.
 *
 * ⚠⚠ THE TYPE MUST NEVER CHANGE THE MECHANISM. There is no `match` on this enum
 * anywhere in the adjustment engine, and a structural test enforces it.
 *
 * The type says *why*; `direction`, `quantity`, `unit_cost` and `effective_date`
 * say *what happens*. Joy's requirement is that movement generation, approval and
 * audit are identical regardless of type, and the only way that stays true as
 * types are added is to make the engine unable to ask.
 *
 * ℹ This project has already been bitten by the opposite: adding a case to a
 * shared enum broke every exhaustive `match` that consumed it. An engine that
 * never matches on type cannot break when a seventh arrives, which is exactly
 * what "future adjustment types we may add later" requires.
 */
enum InventoryAdjustmentType: string
{
    case StockCountVariance = 'stock_count_variance';
    case Expired = 'expired';
    case Damaged = 'damaged';
    case LostOrStolen = 'lost_or_stolen';
    case InternalConsumption = 'internal_consumption';
    case ManualCorrection = 'manual_correction';

    public function label(): string
    {
        // ⚠ Presentation only, and deliberately a lookup rather than a match on
        // $this - a missing key here renders a fallback label, it does not skip
        // a stock movement.
        return [
            'stock_count_variance' => 'Stock count variance',
            'expired' => 'Expired',
            'damaged' => 'Damaged',
            'lost_or_stolen' => 'Lost or stolen',
            'internal_consumption' => 'Internal consumption',
            'manual_correction' => 'Manual correction',
        ][$this->value] ?? $this->value;
    }
}
