<?php

namespace App\Modules\Sales\Couriers\Tracking;

use App\Modules\Sales\Actions\CourierDoorDiscounts;
use App\Modules\Sales\Actions\RecordConsignmentOutcome;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ⭐ Order desk (Joy's decision 2, 31 Oct 2026): when the courier says a
 * parcel was DELIVERED and what it collected is exactly what was due, the
 * outcome is recorded by itself, through the same action a person presses
 * (invoice, receipt into the courier money account, everything as before).
 *
 * Anything else is left for a person, exactly as before ruling 1 was
 * relaxed: no collected amount from the courier, a different amount (a door
 * discount, a short collection), a part delivery, a return, a door discount
 * already waiting for the Owner, or an outcome already recorded. A refusal
 * from the action (a closed period, a missing mapping) is logged and left
 * for the person too; nothing is half-written, the action is one transaction.
 *
 * Recorded as the person who booked the parcel.
 */
final class AutoDeliverFromCourier
{
    public static function attempt(Consignment $parcel): void
    {
        $parcel = $parcel->fresh(['createdBy']);

        if ($parcel === null || $parcel->status !== ConsignmentStatus::Booked || $parcel->createdBy === null) {
            return;
        }

        $collected = $parcel->courier_collected_amount;

        if (! is_numeric($collected) || ! is_numeric($parcel->cod_amount)) {
            return;
        }

        if (bccomp(number_format((float) $collected, 2, '.', ''), number_format((float) $parcel->cod_amount, 2, '.', ''), 2) !== 0) {
            return;
        }

        if (CourierDoorDiscounts::waitingFor($parcel) !== null) {
            return;
        }

        try {
            app(RecordConsignmentOutcome::class)->handle(
                consignment: $parcel,
                outcome: ConsignmentStatus::Delivered,
                on: ($parcel->courier_status_at ?? now())->toDateString(),
                collectedAmount: number_format((float) $collected, 2, '.', ''),
                lines: [],
                recordedBy: $parcel->createdBy,
                note: 'Recorded by itself: the courier said delivered and collected the amount due.',
            );
        } catch (Throwable $e) {
            Log::warning('Courier delivered, left for a person', ['consignment_id' => $parcel->id, 'reason' => $e->getMessage()]);
        }
    }
}
