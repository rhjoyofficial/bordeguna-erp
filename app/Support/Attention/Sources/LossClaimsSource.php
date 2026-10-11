<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Models\CourierLossClaim;
use App\Support\Attention\AttentionItem;

/**
 * Loss and damage claims still open with the courier: reported and not
 * answered, or accepted and not yet paid (slice 8).
 */
class LossClaimsSource extends Source
{
    public function key(): string
    {
        return 'courier_loss_claims';
    }

    public function area(): string
    {
        return 'couriers';
    }

    protected function permissions(): array
    {
        return ['sales.reports.view'];
    }

    public function items(User $user, int $businessId): array
    {
        $claims = CourierLossClaim::query()
            ->with(['consignment:id,consignment_number', 'courier:id,name'])
            ->where('business_id', $businessId)
            ->whereIn('status', [CourierLossClaim::OPEN, CourierLossClaim::ACCEPTED])
            ->orderBy('reported_on')
            ->get();

        $brands = self::parcelBrands($claims->pluck('consignment_id')->map(fn ($id) => (int) $id)->all());

        return $claims->map(fn (CourierLossClaim $claim) => $this->item(
            (string) $claim->id,
            AttentionItem::NORMAL,
            $claim->status === CourierLossClaim::OPEN ? 'loss_claim_open' : 'loss_claim_unpaid',
            $claim->status === CourierLossClaim::OPEN
                ? 'Claim on parcel {parcel} ({courier}): Tk {amount} claimed, no answer from the courier yet.'
                : 'Claim on parcel {parcel} ({courier}) was accepted; the compensation has not arrived.',
            [
                'parcel' => (string) ($claim->consignment?->consignment_number ?? $claim->consignment_id),
                'courier' => (string) ($claim->courier?->name ?? ''),
                'amount' => self::money($claim->claimed_amount),
            ],
            '/sales/consignments',
            ['since' => $claim->reported_on ?? $claim->created_at, 'ownerRole' => 'Finance', 'brandId' => $brands[(int) $claim->consignment_id] ?? null],
        ))->values()->all();
    }
}
