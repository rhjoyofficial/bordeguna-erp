<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;

/**
 * ⭐ What is left of the units one incoming movement brought into its batch,
 * and what of them was sold or used since (SoldStock::share, 24 Oct 2026).
 *
 * Quantities in the batch's stock unit, four decimals. `costMinor` is what the
 * used units cost when they came in, in paisa: the figure the Tk 10,000 rule
 * reads. `refusal` is the one sentence why the rest cannot simply be taken
 * back (a reservation, a transfer, a changed cost), or null.
 */
final readonly class SoldStockShare
{
    public function __construct(
        public StockMovement $in,
        public StockBatch $batch,
        public string $remaining,
        public string $used,
        public int $costMinor,
        public ?string $refusal,
    ) {}

    public function isUsed(): bool
    {
        return (int) round((float) $this->used * 10000) > 0;
    }

    public function hasRemainder(): bool
    {
        return (int) round((float) $this->remaining * 10000) > 0;
    }

    public function name(): string
    {
        return $this->batch->batch_number ?? "#{$this->batch->id}";
    }
}
