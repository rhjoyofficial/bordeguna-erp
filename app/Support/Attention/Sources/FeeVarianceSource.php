<?php

namespace App\Support\Attention\Sources;

use App\Models\User;
use App\Modules\Sales\Couriers\Fees\CourierFeeVariance;
use App\Support\Attention\AttentionItem;
use Illuminate\Support\Carbon;

/**
 * Courier fees our estimate missed by more than CourierFeeVariance's outlier
 * line over the last LOOKBACK_DAYS days: a rate card that has drifted, or
 * weights that are wrong. Clean parcels only, as the fee report counts them.
 */
class FeeVarianceSource extends Source
{
    public const LOOKBACK_DAYS = 30;

    public function __construct(private readonly CourierFeeVariance $variance) {}

    public function key(): string
    {
        return 'courier_fee_variance';
    }

    public function area(): string
    {
        return 'couriers';
    }

    protected function permissions(): array
    {
        return ['couriers.ratecards.propose'];
    }

    public function items(User $user, int $businessId): array
    {
        $report = $this->variance->report([
            'from' => Carbon::today()->subDays(self::LOOKBACK_DAYS)->toDateString(),
            'to' => Carbon::today()->toDateString(),
        ]);

        $outliers = (int) ($report['summary']['estimate']['outliers'] ?? 0);

        if ($outliers === 0) {
            return [];
        }

        return [$this->item(
            'estimate',
            AttentionItem::NORMAL,
            'fee_variance',
            '{count} of {clean} parcels in the last {days} days cost more than {percent}% away from our estimate.',
            [
                'count' => $outliers,
                'clean' => (int) ($report['summary']['clean'] ?? 0),
                'days' => self::LOOKBACK_DAYS,
                'percent' => (int) CourierFeeVariance::OUTLIER_PERCENT,
            ],
            '/sales/courier-fees',
            ['ownerRole' => 'Manager', 'count' => $outliers],
        )];
    }
}
