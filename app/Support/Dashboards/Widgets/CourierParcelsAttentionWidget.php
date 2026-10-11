<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Sales\Couriers\Enums\CourierStatus;
use App\Modules\Sales\Couriers\Models\CourierBookingRequest;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Models\Consignment;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;

/**
 * Parcels somebody should look at: a booking whose answer was lost (we do not
 * know whether the courier has it), a failed delivery, a parcel on hold, lost or
 * damaged, and one stuck more than STUCK_DAYS days in one open status. Only
 * parcels whose outcome is not yet recorded in the ERP.
 */
class CourierParcelsAttentionWidget extends CourierWidget
{
    private const SHOWN = 8;

    public function key(): string
    {
        return 'courier_parcels_attention';
    }

    public function title(): string
    {
        return 'Parcels needing attention';
    }

    public function description(): ?string
    {
        return 'Booking answer lost, delivery failed, on hold, lost or damaged, or more than '.self::STUCK_DAYS.' days in one courier status. As at now.';
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

    public function emptyText(): string
    {
        return 'Every parcel is moving.';
    }

    public function notifications(): array
    {
        return [['watch' => 'attention', 'sound' => 'attention', 'message' => 'A parcel needs attention.']];
    }

    public function data(WidgetContext $context): array
    {
        $rows = [];

        $unknown = CourierBookingRequest::query()
            ->withoutGlobalScopes()
            ->with(['delivery:id,delivery_number', 'account:id,label'])
            ->where('business_id', $context->businessId)
            ->where('status', CourierBookingRequest::UNKNOWN)
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->get();

        foreach ($unknown as $request) {
            $rows[] = [
                'id' => 'booking-'.$request->id,
                'tone' => 'bad',
                'since' => $request->created_at,
                'cells' => [
                    'parcel' => (string) ($request->delivery?->delivery_number ?? 'Challan '.$request->delivery_id),
                    'courier' => (string) ($request->account?->label ?? ''),
                    'problem' => 'Booking answer lost',
                ],
            ];
        }

        $problems = self::values(self::PROBLEM_STATUSES);
        $stuckBefore = Carbon::now()->subDays(self::STUCK_DAYS);

        $parcels = Consignment::query()
            ->with('courier:id,name')
            ->where('business_id', $context->businessId)
            ->where('status', ConsignmentStatus::Booked->value)
            ->whereNotNull('courier_status')
            ->where(fn ($q) => $q
                ->whereIn('courier_status', $problems)
                ->orWhere(fn ($q) => $q
                    ->whereNotIn('courier_status', CourierStatus::finalValues())
                    ->where('courier_status_at', '<', $stuckBefore)))
            ->orderBy('courier_status_at')
            ->get();

        foreach ($parcels as $parcel) {
            $status = $parcel->courier_status;
            $problem = in_array($status, self::PROBLEM_STATUSES, true);

            $rows[] = [
                'id' => 'parcel-'.$parcel->id,
                'tone' => $problem ? 'bad' : 'warn',
                'since' => $parcel->courier_status_at,
                'cells' => [
                    'parcel' => $parcel->consignment_number,
                    'courier' => (string) ($parcel->courier?->name ?? ''),
                    'problem' => $problem
                        ? (string) $status?->label()
                        : 'Stuck: '.mb_strtolower((string) $status?->label()),
                ],
            ];
        }

        $total = count($rows);

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'parcel', 'label' => 'Parcel'],
                ['key' => 'courier', 'label' => 'Courier'],
                ['key' => 'problem', 'label' => 'What'],
                ['key' => 'days', 'label' => 'Days', 'align' => 'right', 'unit' => 'days'],
            ],
            'rows' => array_map(fn (array $row) => [
                'id' => $row['id'],
                'url' => '/sales/consignments',
                'tone' => $row['tone'],
                'cells' => $row['cells'] + ['days' => $row['since'] === null ? null : (int) Carbon::parse($row['since'])->diffInDays(Carbon::now())],
            ], array_slice($rows, 0, self::SHOWN)),
            'total_count' => $total,
            'parts' => [
                ['label' => 'Booking answer lost', 'value' => $unknown->count(), 'unit' => 'count', 'tone' => $unknown->count() > 0 ? 'bad' : null],
                ['label' => 'Courier problem', 'value' => $parcels->filter(fn ($p) => in_array($p->courier_status, self::PROBLEM_STATUSES, true))->count(), 'unit' => 'count'],
                ['label' => 'Stuck over '.self::STUCK_DAYS.' days', 'value' => $parcels->reject(fn ($p) => in_array($p->courier_status, self::PROBLEM_STATUSES, true))->count(), 'unit' => 'count'],
            ],
            'basis' => 'operational',
            'watch' => ['attention' => $total],
        ];
    }
}
