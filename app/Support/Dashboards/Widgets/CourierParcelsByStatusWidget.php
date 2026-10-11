<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Modules\Sales\Support\OrderBrand;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetContext;

/**
 * Parcels by where the COURIER says they are (consignments.courier_status).
 * Open statuses are a position as at now; final ones (delivered, returned,
 * cancelled, lost) count parcels that reached them inside the range. Parcels
 * booked by hand have no courier status and are shown as their own bar.
 */
class CourierParcelsByStatusWidget extends CourierWidget
{
    public function key(): string
    {
        return 'courier_parcels_by_status';
    }

    public function title(): string
    {
        return 'Parcels by courier status';
    }

    public function description(): ?string
    {
        return 'Open parcels as at now; delivered, returned and other final words by the date the courier said them, in the range. Operational, from the courier\'s own updates.';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Manager, DashboardKey::Sales];
    }

    public function permission(): ?string
    {
        return 'sales.parcels.see';
    }

    public function size(): string
    {
        return 'md';
    }

    public function dateBasis(): ?string
    {
        return 'courier status date';
    }

    /** ⭐ Follows the dashboard's Brand switcher (Joy, 11 Oct 2026). */
    public function brandAware(): bool
    {
        return true;
    }

    public function data(WidgetContext $context): array
    {
        $final = CourierStatus::finalValues();
        $base = fn () => OrderBrand::onConsignments(Consignment::query()->where('business_id', $context->businessId), $context->brandId);

        $open = $base()
            ->whereNotNull('courier_status')
            ->whereNotIn('courier_status', $final)
            ->groupBy('courier_status')
            ->selectRaw('courier_status, COUNT(*) as parcels')
            ->pluck('parcels', 'courier_status');

        $closed = $base()
            ->whereIn('courier_status', $final)
            ->whereBetween('courier_status_at', [$context->range->fromDate().' 00:00:00', $context->range->toDate().' 23:59:59'])
            ->groupBy('courier_status')
            ->selectRaw('courier_status, COUNT(*) as parcels')
            ->pluck('parcels', 'courier_status');

        $byHand = $base()->whereNull('courier_status')->where('status', ConsignmentStatus::Booked->value)->count();

        $items = [];

        foreach (CourierStatus::cases() as $status) {
            $count = (int) ($open[$status->value] ?? $closed[$status->value] ?? 0);

            if ($count === 0) {
                continue;
            }

            $items[] = [
                'label' => $status->isFinal() ? $status->label().' (range)' : $status->label(),
                'value' => $count,
                'tone' => match (true) {
                    in_array($status, self::COLLECTED_STATUSES, true) => 'good',
                    in_array($status, self::PROBLEM_STATUSES, true) => 'bad',
                    $status === CourierStatus::Returning || $status === CourierStatus::ReturnedToMerchant => 'warn',
                    default => null,
                },
                'url' => '/sales/consignments',
            ];
        }

        if ($byHand > 0) {
            $items[] = ['label' => 'Booked by hand (no courier status)', 'value' => $byHand, 'url' => '/sales/consignments'];
        }

        if ($items === []) {
            return ['kind' => 'empty', 'message' => 'No parcels with a courier yet.'];
        }

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'count',
            'items' => $items,
            'total' => array_sum(array_column($items, 'value')),
            'basis' => 'operational',
            'watch' => ['delivered' => (int) ($closed[CourierStatus::Delivered->value] ?? 0)],
        ];
    }
}
