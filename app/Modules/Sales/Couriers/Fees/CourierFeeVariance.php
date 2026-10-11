<?php

namespace App\Modules\Sales\Couriers\Fees;

use App\Modules\Sales\Models\CourierStatement;
use App\Modules\Sales\Models\CourierStatementLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Estimate vs the courier's quote vs what the statement charged, per parcel
 * and in aggregate (courier hub slice 5, plan E.4).
 *
 * ⚠ Aggregates read CLEAN parcels only (CourierFeeSamples). The excluded ones
 * stay in the table with their reason, and their count is shown, so a small
 * sample is visible as small.
 *
 * Variance = fact - actual, in taka and as a % of actual. A parcel more than
 * OUTLIER_PERCENT off is flagged, and still counted.
 */
class CourierFeeVariance
{
    public const OUTLIER_PERCENT = 25.0;

    public function __construct(private readonly CourierFeeSamples $samples) {}

    /**
     * @param  array{provider?: ?string, band?: ?string, bracket?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $rows = $this->samples->rows(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null])
            ->filter(fn (array $row) => blank($filters['provider'] ?? null) || $row['provider'] === (string) $filters['provider'])
            ->filter(fn (array $row) => blank($filters['band'] ?? null) || $row['to_band'] === (string) $filters['band'])
            ->filter(fn (array $row) => blank($filters['bracket'] ?? null) || $row['bracket'] === (string) $filters['bracket'])
            ->map(fn (array $row) => $this->withVariance($row))
            ->values();

        $clean = $rows->filter(fn (array $row) => $row['excluded'] === null)->values();

        return [
            'rows' => $rows->sortByDesc('consignment_id')->values()->all(),
            'summary' => [
                'parcels' => $rows->count(),
                'clean' => $clean->count(),
                'estimate' => self::aggregate($clean, 'estimate'),
                'quote' => self::aggregate($clean, 'quote'),
                'excluded' => $rows->whereNotNull('excluded')->groupBy('excluded')
                    ->map(fn (Collection $group, string $reason) => ['reason' => $reason, 'label' => CourierFeeSamples::REASONS[$reason] ?? $reason, 'count' => $group->count()])
                    ->values()->all(),
                'unmatched_lines' => $this->unmatchedLines($filters),
                'min_samples' => (int) config('couriers.min_samples', 10),
            ],
            'by_band' => $clean->groupBy(fn (array $row) => $row['provider'].'|'.$row['to_band'])
                ->map(function (Collection $group) {
                    $first = $group->first();

                    return [
                        'provider' => $first['provider'],
                        'band' => $first['to_band'],
                        'label' => ($first['to_band_name'] ?? $first['to_band']).' ('.$first['provider'].')',
                        'count' => $group->count(),
                        'estimate_median_pct' => self::aggregate($group, 'estimate')['median_pct'],
                        'quote_median_pct' => self::aggregate($group, 'quote')['median_pct'],
                        'actual_median' => FeeBrackets::median($group->map(fn (array $r) => (float) $r['actual']['total'])->values()->all()),
                    ];
                })->sortBy('label')->values()->all(),
            'trend' => $clean->groupBy('week')
                ->map(fn (Collection $group, string $week) => [
                    'week' => $week,
                    'count' => $group->count(),
                    'estimate_median_pct' => self::aggregate($group, 'estimate')['median_pct'],
                    'quote_median_pct' => self::aggregate($group, 'quote')['median_pct'],
                ])->sortBy('week')->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withVariance(array $row): array
    {
        foreach (['estimate', 'quote'] as $fact) {
            $value = $row[$fact]['total'] ?? null;
            $actual = $row['actual']['total'] ?? null;

            if ($value === null || $actual === null) {
                $row[$fact.'_diff'] = null;
                $row[$fact.'_pct'] = null;
                $row[$fact.'_outlier'] = false;

                continue;
            }

            $diff = round((float) $value - (float) $actual, 2);
            $pct = (float) $actual > 0 ? round($diff / (float) $actual * 100, 1) : null;

            $row[$fact.'_diff'] = $diff;
            $row[$fact.'_pct'] = $pct;
            $row[$fact.'_outlier'] = $pct !== null && abs($pct) > self::OUTLIER_PERCENT;
        }

        return $row;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  clean rows with their variance
     * @return array{count: int, mean_abs: ?float, mean_abs_pct: ?float, median_diff: ?float, median_pct: ?float, outliers: int}
     */
    public static function aggregate(Collection $rows, string $fact): array
    {
        $compared = $rows->filter(fn (array $row) => ($row[$fact.'_diff'] ?? null) !== null);
        $diffs = $compared->map(fn (array $row) => (float) $row[$fact.'_diff'])->values()->all();
        $pcts = $compared->filter(fn (array $row) => $row[$fact.'_pct'] !== null)->map(fn (array $row) => (float) $row[$fact.'_pct'])->values()->all();
        $count = count($diffs);

        return [
            'count' => $count,
            'mean_abs' => $count === 0 ? null : round(array_sum(array_map('abs', $diffs)) / $count, 2),
            'mean_abs_pct' => $pcts === [] ? null : round(array_sum(array_map('abs', $pcts)) / count($pcts), 1),
            'median_diff' => FeeBrackets::median($diffs),
            'median_pct' => $pcts === [] ? null : round((float) FeeBrackets::median($pcts), 1),
            'outliers' => $compared->filter(fn (array $row) => $row[$fact.'_outlier'] === true)->count(),
        ];
    }

    /**
     * Lines on posted statements that matched no parcel of ours: left out of
     * everything, counted here.
     *
     * @param  array{provider?: ?string, from?: ?string, to?: ?string}  $filters
     */
    private function unmatchedLines(array $filters): int
    {
        $query = CourierStatementLine::query()->whereNull('consignment_id')
            ->where(fn ($q) => $q->whereNull('resolution')->orWhere('resolution', '!=', CourierStatementLine::NOT_MINE))
            ->whereHas('statement', function ($q) use ($filters) {
                $q->where('status', CourierStatement::POSTED);

                if (filled($filters['provider'] ?? null)) {
                    $q->where('provider_code', (string) $filters['provider']);
                }

                if (filled($filters['from'] ?? null)) {
                    $q->whereDate('statement_date', '>=', Carbon::parse((string) $filters['from'])->toDateString());
                }

                if (filled($filters['to'] ?? null)) {
                    $q->whereDate('statement_date', '<=', Carbon::parse((string) $filters['to'])->toDateString());
                }
            });

        return $query->count();
    }
}
