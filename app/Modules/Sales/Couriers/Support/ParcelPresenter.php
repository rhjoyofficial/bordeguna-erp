<?php

namespace App\Modules\Sales\Couriers\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Couriers\Booking\BookParcelThroughCourier;
use App\Modules\Sales\Couriers\Booking\ParcelDraft;
use App\Modules\Sales\Couriers\Booking\SettleBookingRequest;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Locations\Unresolved;
use App\Modules\Sales\Couriers\Models\CourierAccount;
use App\Modules\Sales\Couriers\Models\CourierAccountBusiness;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Couriers\Models\CourierFeeEstimate;
use App\Modules\Sales\Couriers\Models\CourierShipmentEvent;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\Delivery;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shapes the courier side of a challan for the order screen and the parcels
 * list: which accounts can book it, where each would send it (or why not),
 * the booking whose answer is open, and the courier's timeline. Dates as
 * strings, never a model handed to the page.
 */
class ParcelPresenter
{
    /**
     * Active accounts linked to this business with a store and a usable courier
     * money account: the only ones the booking form offers.
     *
     * @return Collection<int, CourierAccount>
     */
    public static function bookableAccounts(int $businessId): Collection
    {
        $ids = CourierAccountBusiness::query()->where('business_id', $businessId)->whereNotNull('provider_store_id')->pluck('courier_account_id');

        return CourierAccount::query()->with('provider')->whereIn('id', $ids)->where('is_active', true)->orderBy('label')->get()
            ->filter(function (CourierAccount $account) use ($businessId) {
                try {
                    BookParcelThroughCourier::linkFor($account, $businessId);
                } catch (ValidationException) {
                    return false;
                }

                return $account->definition()->capabilities()['book'] === true;
            })->values();
    }

    /**
     * @param  Collection<int, CourierAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public static function accountOptions(Collection $accounts, int $businessId): array
    {
        return $accounts->map(function (CourierAccount $account) use ($businessId) {
            $definition = $account->definition();
            $itemKey = $definition->itemTypeOptionKey();
            $link = $account->linkFor($businessId);

            return [
                'id' => $account->id,
                'label' => $account->label,
                'provider' => $definition->code(),
                'provider_name' => $definition->name(),
                'environment' => $account->environment,
                'store_name' => $link?->provider_store_name,
                'delivery_types' => self::choices($definition->optionChoices('delivery_type')),
                'item_types' => self::choices($definition->optionChoices($itemKey)),
                'default_delivery_type' => (string) ($account->options['delivery_type'] ?? ''),
                'default_item_type' => (string) ($account->options[$itemKey] ?? ''),
                'max_weight_grams' => $definition->maxWeightGrams(),
            ];
        })->values()->all();
    }

    /**
     * The API booking preview for one challan nobody has handed to a courier.
     *
     * @param  Collection<int, CourierAccount>  $accounts
     * @return array<string, mixed>
     */
    public static function draft(Delivery $delivery, Collection $accounts, ResolveSellingPrice $resolver): array
    {
        $draft = ParcelDraft::for($delivery, $resolver);
        $destinations = [];

        foreach ($accounts as $account) {
            $code = $account->definition()->code();
            $resolved = $draft->address === null ? null : $draft->destinationFor($code);

            $destinations[$account->id] = [
                'problems' => array_values($draft->problems($account->definition()->addressMax())),
                'resolved' => $resolved !== null && ! $resolved instanceof Unresolved,
                'message' => $resolved instanceof Unresolved ? $resolved->message : null,
                'where' => $resolved === null || $resolved instanceof Unresolved
                    ? null
                    : implode(' / ', array_filter([$resolved->cityName, $resolved->zoneName, $resolved->areaName])),
            ];
        }

        return [
            'cod_whole' => $draft->codWholeTaka(),
            'due' => $draft->due?->toTaka(),
            'due_has_paisa' => $draft->dueHasPaisa(),
            'description' => $draft->description,
            'destinations' => $destinations,
        ];
    }

    /**
     * The latest booking of this challan that a person still has to settle,
     * or the last refusal, so the form can say why.
     *
     * @return array<string, mixed>|null
     */
    public static function openBooking(Delivery $delivery): ?array
    {
        $intent = CourierBookingRequest::query()->with('account.provider')->where('delivery_id', $delivery->id)
            ->latest('id')->first();

        if ($intent === null || $intent->status === CourierBookingRequest::SUCCEEDED) {
            return null;
        }

        return [
            'id' => $intent->id,
            'status' => $intent->status,
            'needs_decision' => $intent->needsDecision(),
            'account' => $intent->account->label,
            'provider_name' => $intent->account->definition()->name(),
            'merchant_order_id' => $intent->merchant_order_id,
            'provider_consignment_id' => $intent->provider_consignment_id,
            'error' => $intent->error,
            'at' => $intent->created_at?->format('Y-m-d H:i'),
            'can_look_up' => SettleBookingRequest::canLookUp($intent),
        ];
    }

    /**
     * The courier half of a consignment: its status, fees, and timeline.
     *
     * @return array<string, mixed>
     */
    public static function courier(Consignment $consignment, bool $withEvents = true): array
    {
        $status = $consignment->courier_status;
        $collected = $consignment->courier_collected_amount === null ? null : Money::ofTaka((string) $consignment->courier_collected_amount)->toTaka();

        return [
            'booked_via' => $consignment->booked_via ?? Consignment::BOOKED_VIA_MANUAL,
            'courier_account' => $consignment->courierAccount?->label,
            'courier_status' => $status?->value,
            'courier_status_label' => $status?->label(),
            'courier_status_raw' => $consignment->courier_status_raw,
            'status_synced_at' => $consignment->status_synced_at?->format('Y-m-d H:i'),
            'provider_fee_quoted' => $consignment->provider_fee_quoted === null ? null : Money::ofTaka((string) $consignment->provider_fee_quoted)->toTaka(),
            'provider_cod_fee_quoted' => $consignment->provider_cod_fee_quoted === null ? null : Money::ofTaka((string) $consignment->provider_cod_fee_quoted)->toTaka(),
            'hub_weight_grams' => $consignment->hub_weight_grams,
            'courier_collected_amount' => $collected,
            'courier_invoice_ref' => $consignment->courier_invoice_ref,
            'can_cancel' => $consignment->isBooked() && $consignment->courier_account_id !== null && ($status === null || $status->cancellable()),
            'can_refresh' => $consignment->courier_account_id !== null,
            // ⭐ Slice 5: what the ERP expected, kept at booking (one of three
            // facts beside the quote above and the statement's charge).
            'fee_estimate' => self::feeEstimate($consignment),
            'prompt' => self::prompt($consignment, $status, $collected),
            'events' => $withEvents ? $consignment->shipmentEvents->take(20)->map(fn (CourierShipmentEvent $e) => [
                'id' => $e->id,
                'source' => $e->source,
                'event' => $e->event,
                'raw_status' => $e->raw_status,
                'status_label' => $e->canonical_status?->label(),
                'applied' => $e->applied,
                'occurred_at' => $e->occurred_at?->format('Y-m-d H:i'),
            ])->values()->all() : [],
        ];
    }

    /**
     * @return array{total: string|null, basis: string, confidence: string}|null
     */
    private static function feeEstimate(Consignment $consignment): ?array
    {
        $estimate = CourierFeeEstimate::query()->where('consignment_id', $consignment->id)->latest('id')->first();

        return $estimate === null ? null : [
            'total' => $estimate->total === null ? null : (string) $estimate->total,
            'basis' => $estimate->basis,
            'confidence' => $estimate->confidence,
        ];
    }

    /**
     * ⭐ Ruling 1: what the courier reported, offered to a person to confirm
     * through the existing outcome form. Only while the parcel's money is open.
     *
     * @return array{outcome: string, collected: string|null, message: string}|null
     */
    private static function prompt(Consignment $consignment, ?CourierStatus $status, ?string $collected): ?array
    {
        if ($status === null || ! $consignment->isBooked()) {
            return null;
        }

        return match ($status->outcomeToConfirm()) {
            'delivered' => ['outcome' => 'delivered', 'collected' => $collected ?? $consignment->codAmount()->toTaka(), 'message' => 'Courier reports delivered: confirm.'],
            'partially_delivered' => ['outcome' => 'partially_delivered', 'collected' => $collected, 'message' => 'Courier reports a part delivery: say what the customer took, then confirm.'],
            'returned' => ['outcome' => 'returned', 'collected' => '0.00', 'message' => $status === CourierStatus::Cancelled
                ? 'Cancelled at the courier: record it as returned so the goods go back to stock.'
                : 'Courier reports it returned to us: confirm when the goods are back.'],
            'paid_return' => ['outcome' => 'returned', 'collected' => '0.00', 'message' => 'Courier reports a paid return'.($collected === null ? '' : " (collected {$collected})").'. A paid return cannot be recorded yet: record the goods as returned with 0 and tell whoever keeps the books about the money.'],
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $choices
     * @return list<array{value: string, label: string}>
     */
    private static function choices(array $choices): array
    {
        return array_values(array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($choices), $choices));
    }
}
