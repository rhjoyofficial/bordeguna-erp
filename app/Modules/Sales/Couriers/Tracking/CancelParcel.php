<?php

namespace App\Modules\Sales\Couriers\Tracking;

use App\Modules\Sales\Couriers\Drivers\BooksParcels;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Events\CourierParcelChanged;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Sales\Models\Consignment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asks the courier to cancel a parcel that has not been picked up.
 *
 * ⚠ Only the COURIER status changes. The goods are still on a challan in the
 * books, so the order screen then asks a person to record the parcel as
 * returned through the existing outcome (goods back to stock, no invoice).
 */
class CancelParcel
{
    public function __construct(private readonly RecordShipmentEvent $events) {}

    /**
     * @throws ValidationException
     */
    public function handle(Consignment $consignment, string $reason): string
    {
        $account = $consignment->courierAccount;
        $reason = trim($reason);

        if ($account === null) {
            throw ValidationException::withMessages(['cancel' => 'This parcel was typed in by hand. Cancel it in the courier panel.']);
        }

        if (! $consignment->isBooked() || ($consignment->courier_status !== null && ! $consignment->courier_status->cancellable())) {
            throw ValidationException::withMessages(['cancel' => 'Only a parcel the courier has not picked up yet can be cancelled.']);
        }

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Say why it is cancelled.']);
        }

        $driver = app((string) $account->definition()->driverClass());

        if (! $driver instanceof BooksParcels) {
            throw ValidationException::withMessages(['cancel' => "{$account->definition()->name()} cannot cancel through the ERP. Cancel it in its panel."]);
        }

        $result = $driver->cancel($account, (string) ($consignment->provider_consignment_id ?? $consignment->consignment_number), Str::limit($reason, 199, ''));

        if (! $result->ok) {
            throw ValidationException::withMessages(['cancel' => $result->message]);
        }

        $this->events->handle($consignment, CourierShipmentEvent::BOOKING, 'cancelled', 'cancelled', now(), ['reason' => $reason]);
        CourierParcelChanged::dispatch(CourierParcelChanged::CANCELLED, (int) $consignment->business_id, (int) $consignment->delivery_id, (int) $consignment->id, CourierStatus::Cancelled->value);

        return $result->message.' Now record the parcel as returned, so the goods go back to stock.';
    }
}
