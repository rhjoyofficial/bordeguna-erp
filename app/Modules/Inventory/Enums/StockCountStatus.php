<?php

namespace App\Modules\Inventory\Enums;

/**
 * A count's lifecycle (C-CNT-9): draft while the floor is being walked, then
 * completed (its variances now live their own lives as adjustments) or
 * cancelled (kept as a record, moved nothing).
 */
enum StockCountStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        // Presentation only - a lookup, never a match that can break when a
        // case is added (the shared-enum lesson).
        return [
            'draft' => 'Draft',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ][$this->value] ?? $this->value;
    }
}
