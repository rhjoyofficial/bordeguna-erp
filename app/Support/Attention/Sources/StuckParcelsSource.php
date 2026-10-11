<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Support\Attention\AttentionItem;
use App\Support\Dashboards\Widgets\CourierWidget;
use Illuminate\Support\Carbon;

/**
 * Parcels the courier reports a problem with (failed delivery, on hold, lost,
 * damaged), and parcels more than CourierWidget::STUCK_DAYS days in one open
 * status. The same rule as the dashboard's parcels widget.
 */
class StuckParcelsSource extends Source
{
    public function key(): string
    {
        return 'courier_parcels_stuck';
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
        $problems = array_map(fn (CourierStatus $s) => $s->value, CourierWidget::PROBLEM_STATUSES);

        $parcels = Consignment::query()
            ->with('courier:id,name')
            ->where('business_id', $businessId)
            ->where('status', ConsignmentStatus::Booked->value)
            ->whereNotNull('courier_status')
            ->where(fn ($q) => $q
                ->whereIn('courier_status', $problems)
                ->orWhere(fn ($q) => $q
                    ->whereNotIn('courier_status', CourierStatus::finalValues())
                    ->where('courier_status_at', '<', Carbon::now()->subDays(CourierWidget::STUCK_DAYS))))
            ->orderBy('courier_status_at')
            ->get();

        $brands = self::parcelBrands($parcels->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $parcels->map(function (Consignment $parcel) use ($brands) {
            $status = $parcel->courier_status;
            $problem = in_array($status, CourierWidget::PROBLEM_STATUSES, true);
            $gone = in_array($status, [CourierStatus::Lost, CourierStatus::Damaged], true);

            return $this->item(
                (string) $parcel->id,
                $gone ? AttentionItem::HIGH : AttentionItem::NORMAL,
                $problem ? 'parcel_problem' : 'parcel_stuck',
                $problem ? 'Parcel {parcel} ({courier}): the courier says {status}.' : 'Parcel {parcel} ({courier}) has been {status} for more than {days} days.',
                [
                    'parcel' => (string) $parcel->consignment_number,
                    'courier' => (string) ($parcel->courier?->name ?? ''),
                    'status' => (string) $status?->label(),
                    // The browser says the status in Bangla from this.
                    'status_value' => (string) $status?->value,
                    'days' => CourierWidget::STUCK_DAYS,
                ],
                '/sales/consignments',
                [
                    'since' => $parcel->courier_status_at,
                    'ownerRole' => 'Manager',
                    'brandId' => $brands[(int) $parcel->id] ?? null,
                ],
            );
        })->values()->all();
    }
}
