<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Models\CourierDoorDiscountRequest;
use App\Support\Attention\AttentionItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cash collected at the door that differs from what was due, waiting for the
 * Owner's decision (ruling 3). Nothing is posted while it waits, so the parcel
 * stays open in the books until somebody decides.
 */
class DoorDecisionsSource extends Source
{
    public function key(): string
    {
        return 'courier_door_decisions';
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
        $waiting = CourierDoorDiscountRequest::query()
            ->where('business_id', $businessId)
            ->whereNull('applied_at')
            ->whereHas('approvalRequest', fn (Builder $q) => $q->where('status', ApprovalStatus::Pending->value))
            ->with('consignment:id,consignment_number')
            ->orderBy('created_at')
            ->get();

        $brands = self::parcelBrands($waiting->pluck('consignment_id')->map(fn ($id) => (int) $id)->all());

        return $waiting->map(fn (CourierDoorDiscountRequest $request) => $this->item(
            (string) $request->id,
            AttentionItem::HIGH,
            'door_difference',
            'Parcel {parcel}: Tk {collected} collected at the door against Tk {due} due. Decide the difference.',
            [
                'parcel' => (string) ($request->consignment?->consignment_number ?? $request->consignment_id),
                'collected' => self::money($request->collected_amount),
                'due' => self::money($request->amount_due),
            ],
            '/approvals',
            ['since' => $request->created_at, 'ownerRole' => 'Owner', 'brandId' => $brands[(int) $request->consignment_id] ?? null],
        ))->values()->all();
    }
}
