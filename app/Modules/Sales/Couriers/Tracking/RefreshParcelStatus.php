<?php

namespace App\Modules\Sales\Couriers\Tracking;

use App\Modules\Sales\Couriers\Booking\ParcelDetails;
use App\Modules\Sales\Couriers\Drivers\BooksParcels;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Sales\Models\Consignment;

/**
 * Asks the courier where one parcel is (CarryBee GET details, Pathao GET info)
 * and records the answer on its timeline. Read-only at the courier; nothing
 * about money changes here either.
 */
class RefreshParcelStatus
{
    public function __construct(private readonly RecordShipmentEvent $events) {}

    /**
     * @return array{0: bool, 1: string} whether the courier answered, and what to say
     */
    public function handle(Consignment $consignment): array
    {
        $account = $consignment->courierAccount;

        if ($account === null) {
            return [false, 'This parcel was typed in by hand, so the ERP cannot ask the courier about it.'];
        }

        $driver = app((string) $account->definition()->driverClass());

        if (! $driver instanceof BooksParcels) {
            return [false, "{$account->definition()->name()} cannot be asked about a parcel."];
        }

        $details = $driver->details($account, (string) ($consignment->provider_consignment_id ?? $consignment->consignment_number));

        if (! $details->found()) {
            return [false, (string) $details->message];
        }

        $this->apply($consignment, $details);

        $fresh = $consignment->fresh();

        return [true, 'The courier says: '.($fresh->courier_status?->label() ?? ($details->rawStatus ?? 'no status')).'.'];
    }

    public function apply(Consignment $consignment, ParcelDetails $details): void
    {
        $raw = $details->rawStatus;

        // ⚠ The same word with no time from the courier is not a new event:
        // only the "last asked" time moves.
        if ($raw === null || ($details->updatedAt === null && $raw === $consignment->courier_status_raw)) {
            $consignment->forceFill(array_filter([
                'status_synced_at' => now(),
                'courier_collected_amount' => $details->collectedAmount,
                'hub_weight_grams' => $details->hubWeightGrams,
                'courier_invoice_ref' => $details->invoiceRef,
            ], fn ($v) => $v !== null))->save();

            return;
        }

        $this->events->handle(
            $consignment,
            CourierShipmentEvent::POLL,
            $raw,
            $raw,
            $details->updatedAt,
            $details->details(),
            hash('sha256', (string) json_encode([$raw, $details->updatedAt, $details->details()])),
        );
    }
}
