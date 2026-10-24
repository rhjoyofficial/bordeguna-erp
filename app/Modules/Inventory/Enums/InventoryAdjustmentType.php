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

    // ⚠ The SEVENTH type, added 2026-08-02 for A1. It required no change to the
    // adjustment engine at all - which is the first real test of the claim §3 of
    // the workflow architecture made, rather than a claim made in principle.
    case Revaluation = 'revaluation';

    // ⭐ Initial plan B6 (Joy, 24 Oct 2026; Q14 = A): the two loss reasons the
    // first seven did not cover, each with its own head (StockLossHeads).
    // ⚠⚠ Re-checked on 2026-10-25 before adding them: nothing `match`es on
    // this enum (label() is a lookup), so no exhaustive match breaks.
    case Spoiled = 'spoiled';
    case DamagedReturn = 'damaged_return';

    public function label(): string
    {
        // ⚠ Presentation only, and deliberately a lookup rather than a match on
        // $this - a missing key here renders a fallback label, it does not skip
        // a stock movement.
        return [
            'stock_count_variance' => 'Stock count variance',
            'expired' => 'Expired',
            // Joy's own words for the loss reasons (24 Oct 2026).
            'damaged' => 'Broken/Damaged',
            'lost_or_stolen' => 'Shrinkage/Lost',
            'internal_consumption' => 'Internal consumption',
            'manual_correction' => 'Manual correction',
            'revaluation' => 'Revaluation',
            'spoiled' => 'Spoiled',
            'damaged_return' => 'Damaged returns',
        ][$this->value] ?? $this->value;
    }
}
