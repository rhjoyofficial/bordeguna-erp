<?php

namespace App\Modules\Sales\Couriers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Couriers\Booking\BookParcelThroughCourier;
use App\Modules\Sales\Couriers\Booking\ParcelDraft;
use App\Modules\Sales\Couriers\Booking\SettleBookingRequest;
use App\Modules\Sales\Couriers\Fees\CourierFeeEstimator;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Tracking\CancelParcel;
use App\Modules\Sales\Couriers\Tracking\RefreshParcelStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Support\ParcelWeight;
use Illuminate\Http\JsonResponse;
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

    /**
     * ⭐ Slice 5: what this challan would cost at the courier with the form's
     * current account, weight, COD and delivery type. Read-only: a Pathao
     * quote is the only call that may leave, and it books nothing.
     */
    public function estimate(Request $request, Delivery $delivery, CourierFeeEstimator $estimator, ResolveSellingPrice $resolver): JsonResponse
    {
        $data = $request->validate([
            'courier_account_id' => ['required', 'integer'],
            'weight_grams' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'cod_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'delivery_type' => ['nullable'],
        ]);

        $account = CourierAccount::query()->with('provider')->findOrFail($data['courier_account_id']);

        // Only an account that serves this challan's business.
        abort_if($account->linkFor((int) $delivery->business_id) === null, 404);

        $draft = ParcelDraft::for($delivery, $resolver);
        $weight = ParcelWeight::forDelivery($delivery);
        $grams = isset($data['weight_grams']) ? (int) $data['weight_grams'] : $draft->weightSuggested;
        $typed = isset($data['weight_grams']) && (int) $data['weight_grams'] !== $draft->weightSuggested;
        $deliveryType = $data['delivery_type'] ?? ($account->options['delivery_type'] ?? null);

        $estimate = $estimator->estimate(
            account: $account,
            businessId: (int) $delivery->business_id,
            destination: ['district' => $draft->address?->district, 'thana' => $draft->address?->thana, 'area' => $draft->address?->area],
            weightGrams: $grams,
            codAmount: $data['cod_amount'] ?? $draft->codWholeTaka() ?? 0,
            service: CourierFeeEstimator::serviceFor($account->definition()->code(), $deliveryType),
            weightInfo: $typed ? ['typed' => true] : ['items_weighed' => $weight->lines - count($weight->missing), 'items_total' => $weight->lines],
        );

        return response()->json($estimate->toArray());
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
