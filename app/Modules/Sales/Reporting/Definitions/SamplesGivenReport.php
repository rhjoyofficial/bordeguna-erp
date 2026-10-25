<?php

namespace App\Modules\Sales\Reporting\Definitions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use App\Modules\Sales\Models\SampleIssue;
use App\Modules\Sales\Models\SampleIssueLine;
use Illuminate\Support\LazyCollection;

/**
 * ⭐ Samples given (Joy, 25 Oct 2026): what the free samples cost in a period,
 * by purpose or by product, with the quantity, the goods at cost and the
 * delivery charge we bore. Downloads to Excel like every report.
 *
 * ⚠ Only samples actually given count: approved and posted, not reversed. A
 * waiting or rejected one moved nothing.
 *
 * ⚠ By product, a hand-out's delivery charge is shared over its lines by
 * their cost (equally when the goods cost nothing), so the rows still add up
 * to the delivery the ledger holds, to the paisa.
 */
class SamplesGivenReport implements ReportDefinition
{
    public function key(): string
    {
        return 'samples_given';
    }

    public function name(): string
    {
        return 'Samples given';
    }

    public function description(): string
    {
        return 'Free samples by purpose or by product: how many, the goods at cost and the delivery charge we paid, for any period.';
    }

    public function group(): string
    {
        return 'Sales';
    }

    public function permission(): string
    {
        return 'sales.reports.costs';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::dateRange('given', 'Given between', required: false),
            ReportParameter::select('by', 'Group by', [
                ['value' => 'purpose', 'label' => 'Purpose'],
                ['value' => 'product', 'label' => 'Product'],
            ], default: 'purpose'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('group', $this->byProduct($parameters) ? 'Product' : 'Purpose', 28),
            ReportColumn::integer('samples', 'Samples'),
            ReportColumn::decimal('quantity', 'Quantity'),
            ReportColumn::money('cost', 'Goods at cost'),
            ReportColumn::money('delivery', 'Delivery we paid'),
            ReportColumn::money('total', 'Total'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['samples', 'quantity', 'cost', 'delivery', 'total'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(fn () => yield from $this->grouped($parameters));
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return count($this->grouped($parameters));
    }

    public function countRows(ReportParameters $parameters): int
    {
        return $this->estimatedRows($parameters);
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $from = $parameters->from('given');
        $to = $parameters->to('given');

        return new ReportMeta(
            title: 'Samples given'.($this->byProduct($parameters) ? ' by product' : ' by purpose'),
            subtitle: $from && $to ? $from->format('d M Y').' to '.$to->format('d M Y') : 'All samples given to date',
            periodLocked: null,
            lockNote: 'Only samples given and not reversed. No money was taken for any of them.',
        );
    }

    private function byProduct(?ReportParameters $parameters): bool
    {
        return $parameters?->string('by') === 'product';
    }

    /** @return list<array<string, mixed>> */
    private function grouped(ReportParameters $parameters): array
    {
        $issues = SampleIssue::query()
            ->where('status', SampleIssue::POSTED)
            ->whereNull('reversed_at')
            ->when($parameters->from('given'), fn ($q, $from) => $q->whereDate('given_on', '>=', $from->toDateString()))
            ->when($parameters->to('given'), fn ($q, $to) => $q->whereDate('given_on', '<=', $to->toDateString()))
            ->with('lines.product:id,name')
            ->get();

        $groups = [];
        $byProduct = $this->byProduct($parameters);

        foreach ($issues as $issue) {
            /** @var SampleIssue $issue */
            if (! $byProduct) {
                $key = SampleIssue::PURPOSES[$issue->purpose] ?? $issue->purpose;
                $groups[$key] ??= ['ids' => [], 'quantity' => 0.0, 'cost' => 0, 'delivery' => 0];
                $groups[$key]['ids'][$issue->id] = true;
                $groups[$key]['quantity'] += (float) $issue->lines->sum(fn (SampleIssueLine $line) => (float) $line->quantity);
                $groups[$key]['cost'] += (int) $issue->cost_total_paisa;
                $groups[$key]['delivery'] += $issue->deliveryMoney()->minor;

                continue;
            }

            $shares = $this->deliveryShares($issue);

            foreach ($issue->lines as $index => $line) {
                $key = $line->product?->name ?? "Product #{$line->product_id}";
                $groups[$key] ??= ['ids' => [], 'quantity' => 0.0, 'cost' => 0, 'delivery' => 0];
                $groups[$key]['ids'][$issue->id] = true;
                $groups[$key]['quantity'] += (float) $line->quantity;
                $groups[$key]['cost'] += (int) $line->cost_paisa;
                $groups[$key]['delivery'] += $shares[$index];
            }
        }

        ksort($groups);

        return array_values(array_map(fn (string $key, array $g) => [
            'group' => $key,
            'samples' => count($g['ids']),
            'quantity' => round($g['quantity'], 4),
            'cost' => (float) Money::ofMinor($g['cost'])->toTaka(),
            'delivery' => (float) Money::ofMinor($g['delivery'])->toTaka(),
            'total' => (float) Money::ofMinor($g['cost'] + $g['delivery'])->toTaka(),
        ], array_keys($groups), $groups));
    }

    /**
     * The delivery charge in paisa per line, by cost, the remainder on the
     * last line so the shares add up exactly.
     *
     * @return list<int>
     */
    private function deliveryShares(SampleIssue $issue): array
    {
        $delivery = $issue->deliveryMoney()->minor;
        $lines = $issue->lines->values();
        $count = $lines->count();
        $cost = (int) $lines->sum('cost_paisa');
        $shares = [];
        $given = 0;

        foreach ($lines as $index => $line) {
            if ($index === $count - 1) {
                $shares[] = $delivery - $given;

                break;
            }

            $share = $cost > 0 ? intdiv($delivery * (int) $line->cost_paisa, $cost) : intdiv($delivery, max(1, $count));
            $shares[] = $share;
            $given += $share;
        }

        return $shares;
    }
}
