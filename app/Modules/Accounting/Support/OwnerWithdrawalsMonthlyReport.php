<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\OwnerWithdrawalStatus;
use App\Modules\Accounting\Models\OwnerWithdrawal;
use App\Modules\Accounting\Models\OwnerWithdrawalLine;
use Illuminate\Support\Carbon;

/**
 * Owner withdrawals by month (Phase 2, 17 Oct 2026, D2 and D8): what the
 * proprietor took, what it cost the business, what it would have fetched at
 * the reference price, and the difference. Posted documents only: a draft
 * has no cost yet and an authorised one has not left the shelf.
 *
 * The difference is EVIDENCE for the tax adviser, not a figure the books
 * carry (ruling Q2, tax treatment: record the facts, get the treatment
 * confirmed).
 */
final class OwnerWithdrawalsMonthlyReport
{
    /**
     * Every month that has a posted withdrawal, newest first.
     *
     * @return list<array{month: string, label: string, count: int, lines: int, cost: string, reference: ?string, difference: ?string}>
     */
    public static function months(): array
    {
        $byMonth = [];

        OwnerWithdrawal::query()
            ->where('status', OwnerWithdrawalStatus::Posted)
            ->with('lines')
            ->orderByDesc('withdrawn_on')
            ->get()
            ->each(function (OwnerWithdrawal $withdrawal) use (&$byMonth) {
                $month = $withdrawal->withdrawn_on->format('Y-m');
                $row = &$byMonth[$month];

                $row ??= ['month' => $month, 'label' => $withdrawal->withdrawn_on->format('F Y'), 'count' => 0, 'lines' => 0, 'cost_paisa' => 0, 'reference_paisa' => 0, 'referenced_cost_paisa' => 0, 'unreferenced' => 0];
                $row['count']++;
                $row['lines'] += $withdrawal->lines->count();
                $row['cost_paisa'] += (int) $withdrawal->cost_total_paisa;

                foreach ($withdrawal->lines as $line) {
                    if ($line->reference_paisa === null) {
                        $row['unreferenced']++;
                    } else {
                        $row['reference_paisa'] += (int) $line->reference_paisa;
                        $row['referenced_cost_paisa'] += (int) $line->cost_paisa;
                    }
                }
            });

        return array_values(array_map(fn (array $row) => self::present($row), $byMonth));
    }

    /**
     * One month in detail: every posted line with its cost, reference and
     * difference, and the totals.
     *
     * @return array{month: string, label: string, rows: list<array<string, mixed>>, totals: array{count: int, lines: int, cost: string, reference: ?string, difference: ?string, unreferenced: int}}
     */
    public static function month(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $withdrawals = OwnerWithdrawal::query()
            ->where('status', OwnerWithdrawalStatus::Posted)
            ->whereDate('withdrawn_on', '>=', $start->toDateString())
            ->whereDate('withdrawn_on', '<=', $end->toDateString())
            ->with(['lines.product:id,name,sku', 'lines.batch:id,batch_number', 'proprietor:id,name', 'authorisedBy:id,name', 'journal:id,journal_number'])
            ->orderBy('withdrawn_on')->orderBy('id')
            ->get();

        $rows = [];
        $totals = ['count' => $withdrawals->count(), 'lines' => 0, 'cost_paisa' => 0, 'reference_paisa' => 0, 'referenced_cost_paisa' => 0, 'unreferenced' => 0];

        foreach ($withdrawals as $withdrawal) {
            foreach ($withdrawal->lines as $line) {
                /** @var OwnerWithdrawalLine $line */
                $rows[] = [
                    'withdrawal_id' => $withdrawal->id,
                    'number' => $withdrawal->number,
                    'withdrawn_on' => $withdrawal->withdrawn_on->toDateString(),
                    'proprietor' => $withdrawal->proprietor?->name,
                    'authorised_by' => $withdrawal->authorisedBy?->name,
                    'self_authorised' => (bool) $withdrawal->self_authorised,
                    'journal_number' => $withdrawal->journal?->journal_number,
                    'product' => $line->product?->name,
                    'sku' => $line->product?->sku,
                    'batch_number' => $line->batch?->batch_number,
                    'quantity' => (string) $line->quantity,
                    'unit_cost' => $line->unit_cost === null ? null : (string) $line->unit_cost,
                    'cost' => $line->cost()->toTaka(),
                    'reference_source' => $line->reference_source,
                    'reference_price' => $line->reference_price === null ? null : (string) $line->reference_price,
                    'reference' => $line->reference()?->toTaka(),
                    'difference' => $line->difference()?->toTaka(),
                ];

                $totals['lines']++;
                $totals['cost_paisa'] += (int) $line->cost_paisa;

                if ($line->reference_paisa === null) {
                    $totals['unreferenced']++;
                } else {
                    $totals['reference_paisa'] += (int) $line->reference_paisa;
                    $totals['referenced_cost_paisa'] += (int) $line->cost_paisa;
                }
            }
        }

        $presented = self::present($totals + ['month' => $month, 'label' => $start->format('F Y')]);

        return [
            'month' => $month,
            'label' => $start->format('F Y'),
            'rows' => $rows,
            'totals' => [
                'count' => $totals['count'],
                'lines' => $totals['lines'],
                'cost' => $presented['cost'],
                'reference' => $presented['reference'],
                'difference' => $presented['difference'],
                'unreferenced' => $totals['unreferenced'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{month: string, label: string, count: int, lines: int, cost: string, reference: ?string, difference: ?string, unreferenced: int}
     */
    private static function present(array $row): array
    {
        $cost = Money::ofMinor((int) $row['cost_paisa']);
        // A month where no line had a reference has no reference total to show.
        // ⚠ The difference compares referenced lines with THEIR cost only: a
        // line with no reference would otherwise read as a negative benefit.
        $hasReference = $row['lines'] > $row['unreferenced'];
        $reference = $hasReference ? Money::ofMinor((int) $row['reference_paisa']) : null;
        $referencedCost = Money::ofMinor((int) $row['referenced_cost_paisa']);

        return [
            'month' => $row['month'],
            'label' => $row['label'],
            'count' => (int) $row['count'],
            'lines' => (int) $row['lines'],
            'cost' => $cost->toTaka(),
            'reference' => $reference?->toTaka(),
            'difference' => $reference === null ? null : $reference->plus($referencedCost->negated())->toTaka(),
            'unreferenced' => (int) $row['unreferenced'],
        ];
    }
}
