<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Model;

/**
 * End every live reservation a document holds (Phase 2, 17 Oct 2026): the
 * goods left, or the document was cancelled or sent back for re-approval.
 *
 * ⚠ Rows are kept with `released_at`, never deleted: the audit of what was
 * held, for whom and until when stays readable.
 */
class ReleaseReservation
{
    /** @return int how many rows were released */
    public function handle(Model $reference): int
    {
        return StockReservation::query()
            ->for($reference->getMorphClass(), (int) $reference->getKey())
            ->live()
            ->update(['released_at' => now(), 'updated_at' => now()]);
    }
}
