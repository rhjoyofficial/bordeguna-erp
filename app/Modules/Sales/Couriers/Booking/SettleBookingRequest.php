<?php

namespace App\Modules\Sales\Couriers\Booking;

use App\Models\User;
use App\Modules\Sales\Couriers\Drivers\BooksParcels;
use App\Modules\Sales\Couriers\Drivers\LooksUpMerchantReference;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Settles a booking whose answer was lost (ruling 13). Never by sending it
 * again: by asking, or by a person who looked.
 *
 * - CarryBee: GET details by OUR merchant_order_id. Found = the parcel is
 *   recorded as booked; not found = the intent is failed and booking is safe.
 * - Pathao (no lookup by our reference): a person looks in the Pathao panel and
 *   says either "booked, number ___" (checked against Pathao's own info for that
 *   number) or "not booked" (then booking is allowed again).
 */
class SettleBookingRequest
{
    public const FOUND_BY_LOOKUP = 'found_by_lookup';

    public const NOT_AT_COURIER = 'not_at_courier';

    public const PERSON_SAYS_BOOKED = 'person_says_booked';

    public const PERSON_SAYS_NOT_BOOKED = 'person_says_not_booked';

    public function __construct(private readonly BookParcelThroughCourier $booking) {}

    public static function canLookUp(CourierBookingRequest $intent): bool
    {
        return in_array(LooksUpMerchantReference::class, class_implements((string) $intent->account->definition()->driverClass()) ?: [], true);
    }

    /**
     * @return string what happened, for the person
     *
     * @throws ValidationException
     */
    public function lookUp(CourierBookingRequest $intent, User $by): string
    {
        $this->assertOpen($intent);
        $driver = app((string) $intent->account->definition()->driverClass());

        if (! $driver instanceof LooksUpMerchantReference) {
            throw ValidationException::withMessages(['booking' => "{$intent->account->definition()->name()} cannot be asked about our reference. Check its panel and say what you found."]);
        }

        return $this->locked($intent, function () use ($intent, $driver, $by) {
            $details = $driver->lookupMerchantReference($intent->account, $intent->merchant_order_id);

            if ($details->state === ParcelDetails::ERROR) {
                $intent->forceFill(['error' => Str::limit('Lookup failed: '.$details->message, 490, '')])->save();

                return 'The courier could not be asked just now: '.$details->message.' Nothing was booked again. Try the check later.';
            }

            if ($details->state === ParcelDetails::NOT_FOUND) {
                $intent->forceFill([
                    'status' => CourierBookingRequest::FAILED,
                    'error' => "The courier has no parcel under {$intent->merchant_order_id}: nothing was booked.",
                    'resolution' => self::NOT_AT_COURIER,
                    'resolved_by' => $by->id,
                    'resolved_at' => now(),
                ])->save();

                return "The courier has no parcel under {$intent->merchant_order_id}. It is safe to book it again.";
            }

            $this->booking->settle($intent, new BookingResult(
                BookingResult::SUCCEEDED,
                consignmentId: $details->consignmentId,
                deliveryFee: $details->deliveryFee,
                codFee: $details->codFee,
                rawStatus: $details->rawStatus,
            ), $by, self::FOUND_BY_LOOKUP);

            return $intent->fresh()->status === CourierBookingRequest::SUCCEEDED
                ? "Found at the courier as {$details->consignmentId}: recorded as booked."
                : (string) $intent->fresh()->error;
        });
    }

    /**
     * A person found it in the courier's panel. The number is checked against
     * the courier's own record of it before anything is written.
     *
     * @throws ValidationException
     */
    public function markBooked(CourierBookingRequest $intent, string $consignmentNumber, User $by): string
    {
        $this->assertOpen($intent);
        $number = trim($consignmentNumber);

        if ($number === '') {
            throw ValidationException::withMessages(['consignment_number' => "Type the courier's consignment number from its panel."]);
        }

        $driver = app((string) $intent->account->definition()->driverClass());

        if (! $driver instanceof BooksParcels) {
            throw ValidationException::withMessages(['booking' => 'This courier cannot be asked about a parcel.']);
        }

        return $this->locked($intent, function () use ($intent, $driver, $number, $by) {
            $details = $driver->details($intent->account, $number);

            if (! $details->found()) {
                throw ValidationException::withMessages(['consignment_number' => "The courier could not confirm {$number}: ".$details->message]);
            }

            if ($details->merchantOrderId !== null && $details->merchantOrderId !== $intent->merchant_order_id) {
                throw ValidationException::withMessages([
                    'consignment_number' => "{$number} at the courier belongs to {$details->merchantOrderId}, not {$intent->merchant_order_id}.",
                ]);
            }

            $this->booking->settle($intent, new BookingResult(
                BookingResult::SUCCEEDED,
                consignmentId: $details->consignmentId ?? $number,
                deliveryFee: $details->deliveryFee,
                codFee: $details->codFee,
                rawStatus: $details->rawStatus,
            ), $by, self::PERSON_SAYS_BOOKED);

            return $intent->fresh()->status === CourierBookingRequest::SUCCEEDED
                ? "{$number} recorded as booked."
                : (string) $intent->fresh()->error;
        });
    }

    /**
     * A person looked and nothing was booked: the challan may be booked again.
     *
     * @throws ValidationException
     */
    public function markNotBooked(CourierBookingRequest $intent, User $by): string
    {
        $this->assertOpen($intent);

        if ($intent->provider_consignment_id !== null) {
            throw ValidationException::withMessages([
                'booking' => "The courier gave number {$intent->provider_consignment_id} for this booking. Cancel it in the courier's panel first.",
            ]);
        }

        $intent->forceFill([
            'status' => CourierBookingRequest::FAILED,
            'error' => 'Checked in the courier panel: not booked.',
            'resolution' => self::PERSON_SAYS_NOT_BOOKED,
            'resolved_by' => $by->id,
            'resolved_at' => now(),
        ])->save();

        return 'Marked as not booked. The challan can be booked again.';
    }

    /**
     * @throws ValidationException
     */
    private function assertOpen(CourierBookingRequest $intent): void
    {
        if (! $intent->needsDecision()) {
            throw ValidationException::withMessages([
                'booking' => $intent->status === CourierBookingRequest::REQUESTED
                    ? 'This booking is still on its way. Wait two minutes before settling it.'
                    : 'This booking is already settled.',
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(CourierBookingRequest $intent, callable $work): mixed
    {
        $lock = Cache::lock('couriers:book:delivery:'.$intent->delivery_id, 90);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['booking' => 'Somebody is working on this challan right now. Reload in a moment.']);
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
