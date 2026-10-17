<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Lets a hold go (Phase 2, 17 Oct 2026): at handover, on cancellation or
 * rejection, or when the document goes back for re-approval. The row stays
 * with `released_at` set, so the batch the approved price came from is still
 * on record.
 *
 * ⚠ COORDINATED between builders E and F: same path, same two entry points.
 */
class ReleaseReservation
{
    public function handle(StockReservation $reservation): StockReservation
    {
        if ($reservation->released_at === null) {
            $reservation->forceFill(['released_at' => now()])->save();
        }

        return $reservation;
    }

    /** Releases every open hold a document has; returns how many were released. */
    public function forReference(Model $reference): int
    {
        return DB::transaction(fn () => StockReservation::query()
            ->open()
            ->forReference($reference)
            ->update(['released_at' => now(), 'updated_at' => now()]));
    }
}
