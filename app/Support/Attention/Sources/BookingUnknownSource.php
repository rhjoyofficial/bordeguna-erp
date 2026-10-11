<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Support\Attention\AttentionItem;

/**
 * Bookings whose answer was lost: we do not know whether the courier has the
 * parcel, and nobody may book that challan again until a person settles it
 * (ruling 13: never retry blindly). Addressed to whoever asked for the booking.
 */
class BookingUnknownSource extends Source
{
    public function key(): string
    {
        return 'courier_booking_unknown';
    }

    public function area(): string
    {
        return 'couriers';
    }

    protected function permissions(): array
    {
        return ['sales.parcels.see'];
    }

    public function items(User $user, int $businessId): array
    {
        return CourierBookingRequest::query()
            ->withoutGlobalScopes()
            ->with(['delivery:id,delivery_number', 'account:id,label'])
            ->where('business_id', $businessId)
            ->whereIn('status', [CourierBookingRequest::UNKNOWN, CourierBookingRequest::REQUESTED])
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (CourierBookingRequest $request) => $request->needsDecision())
            ->map(fn (CourierBookingRequest $request) => $this->item(
                (string) $request->id,
                AttentionItem::CRITICAL,
                'booking_unknown',
                'Booking for {challan} on {account}: the answer from the courier was lost. Look it up or settle it by hand.',
                [
                    'challan' => (string) ($request->delivery?->delivery_number ?? $request->delivery_id),
                    'account' => (string) ($request->account?->label ?? ''),
                ],
                '/sales/consignments',
                ['since' => $request->created_at, 'ownerRole' => 'Manager', 'ownerUserId' => $request->created_by === null ? null : (int) $request->created_by],
            ))
            ->values()
            ->all();
    }
}
