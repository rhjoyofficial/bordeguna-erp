<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Dashboards\Widgets\CourierWidget;
use Illuminate\Support\Collection;

/**
 * "Courier says": the courier's own last word on an order's parcel, read off
 * the consignment that is already stored on the order's challan (order ->
 * lines -> challan lines -> challan -> consignment), so the order book
 * answers "where is it" without a second screen (Sales redesign, 15 Oct 2026).
 *
 * ⚠ Reads what is stored, computes nothing new: `courier_status` is what the
 * webhook or the last look-up wrote, and a parcel typed by hand has none.
 * Once the outcome is recorded in the ERP, that outcome is the last word.
 *
 * ⚠ The caller eager-loads `lines.deliveryLines.delivery.consignment.courier`;
 * nothing here queries, so a page of fifteen orders costs no extra query.
 */
final class CourierSays
{
    /**
     * @return array{label: string, tone: string, note: string|null, consignment_id: int, delivery_id: int}|null
     */
    public static function forOrder(SalesOrder $order): ?array
    {
        $parcel = self::latestParcel($order);

        return $parcel === null ? null : self::forConsignment($parcel);
    }

    /**
     * @return array{label: string, tone: string, note: string|null, consignment_id: int, delivery_id: int}
     */
    public static function forConsignment(Consignment $parcel): array
    {
        $courier = $parcel->courier?->name;
        $status = $parcel->courier_status;

        if ($parcel->status !== ConsignmentStatus::Booked) {
            // The ERP recorded the outcome: that is the last word, whatever the courier said after.
            return [
                'label' => $parcel->status->label(),
                'tone' => match ($parcel->status) {
                    ConsignmentStatus::Delivered, ConsignmentStatus::PartiallyDelivered => 'ok',
                    ConsignmentStatus::Lost, ConsignmentStatus::Damaged => 'danger',
                    default => 'warning',
                },
                'note' => implode(' · ', array_filter([$courier, $parcel->outcome_on === null ? null : 'recorded '.$parcel->outcome_on->format('j M')])),
                'consignment_id' => (int) $parcel->id,
                'delivery_id' => (int) $parcel->delivery_id,
            ];
        }

        if ($status === null) {
            return [
                'label' => 'Booked by hand',
                'tone' => 'muted',
                'note' => $courier,
                'consignment_id' => (int) $parcel->id,
                'delivery_id' => (int) $parcel->delivery_id,
            ];
        }

        return [
            'label' => $status->label(),
            'tone' => match (true) {
                in_array($status, CourierWidget::COLLECTED_STATUSES, true) => 'ok',
                in_array($status, CourierWidget::PROBLEM_STATUSES, true) => 'danger',
                $status === CourierStatus::Returning || $status === CourierStatus::ReturnedToMerchant => 'warning',
                default => 'info',
            },
            'note' => implode(' · ', array_filter([$courier, $parcel->courier_status_at === null ? null : 'heard '.$parcel->courier_status_at->diffForHumans()])),
            'consignment_id' => (int) $parcel->id,
            'delivery_id' => (int) $parcel->delivery_id,
        ];
    }

    /** The order's parcels, through its challans, newest word first. */
    public static function parcels(SalesOrder $order): Collection
    {
        return $order->lines
            ->flatMap(fn ($line) => $line->deliveryLines)
            ->map(fn ($deliveryLine) => $deliveryLine->delivery?->consignment)
            ->filter()
            ->unique('id')
            ->sortByDesc(fn (Consignment $parcel) => [$parcel->courier_status_at?->getTimestamp() ?? 0, (int) $parcel->id])
            ->values();
    }

    private static function latestParcel(SalesOrder $order): ?Consignment
    {
        return self::parcels($order)->first();
    }
}
