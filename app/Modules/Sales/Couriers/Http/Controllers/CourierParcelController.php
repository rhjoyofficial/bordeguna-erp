<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Couriers\Booking\BookParcelThroughCourier;
use App\Modules\Sales\Couriers\Booking\SettleBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Tracking\CancelParcel;
use App\Modules\Sales\Couriers\Tracking\RefreshParcelStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The order screen's courier buttons (courier hub slice 4): book through the
 * API, settle a booking whose answer was lost, refresh, cancel.
 *
 * ⚠ Technical authority only (ChatGPT risk 11): booking needs
 * `couriers.parcels.book` beside `sales.sell`, and none of this posts money.
 * Confirming what the courier reported stays on the existing outcome route,
 * with its receipt and returns permissions.
 */
class CourierParcelController extends Controller
{
    public function book(Request $request, Delivery $delivery, BookParcelThroughCourier $action): RedirectResponse
    {
        $data = $request->validate([
            'courier_account_id' => ['required', 'integer'],
            'cod_amount' => ['required'],
            'declared_weight_grams' => ['nullable', 'integer', 'min:1'],
            'delivery_type' => ['nullable'],
            'item_type' => ['nullable'],
            'description' => ['nullable', 'string', 'max:250'],
            'special_instruction' => ['nullable', 'string', 'max:250'],
        ]);

        $account = CourierAccount::query()->with('provider')->findOrFail($data['courier_account_id']);
        $intent = $action->handle($delivery, $account, $data, $request->user());
        $name = $account->definition()->name();

        return match ($intent->status) {
            CourierBookingRequest::SUCCEEDED => back()->with('success', "Booked with {$name} as {$intent->provider_consignment_id}."),
            CourierBookingRequest::FAILED => back()->with('error', "{$name} refused the booking: {$intent->error}"),
            default => back()->with('error', "{$name} gave no clear answer ({$intent->error}). The parcel may or may not exist there, so it will NOT be sent again by itself. Settle it below."),
        };
    }

    public function lookUp(Request $request, CourierBookingRequest $bookingRequest, SettleBookingRequest $settle): RedirectResponse
    {
        return back()->with('success', $settle->lookUp($bookingRequest, $request->user()));
    }

    public function markBooked(Request $request, CourierBookingRequest $bookingRequest, SettleBookingRequest $settle): RedirectResponse
    {
        $data = $request->validate(['consignment_number' => ['required', 'string', 'max:100']]);

        return back()->with('success', $settle->markBooked($bookingRequest, $data['consignment_number'], $request->user()));
    }

    public function markNotBooked(Request $request, CourierBookingRequest $bookingRequest, SettleBookingRequest $settle): RedirectResponse
    {
        return back()->with('success', $settle->markNotBooked($bookingRequest, $request->user()));
    }

    public function refresh(Consignment $consignment, RefreshParcelStatus $refresh): RedirectResponse
    {
        [$ok, $message] = $refresh->handle($consignment);

        return back()->with($ok ? 'success' : 'error', $message);
    }

    public function cancel(Request $request, Consignment $consignment, CancelParcel $cancel): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:199']]);

        return back()->with('success', $cancel->handle($consignment, $data['reason']));
    }
}
