<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Sales\Models\CourierStatement;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetContext;

/**
 * Courier statements imported and not yet posted: the payouts the books do not
 * show yet. One row per statement, each naming its courier.
 */
class CourierStatementsPendingWidget extends CourierWidget
{
    private const SHOWN = 6;

    public function key(): string
    {
        return 'courier_statements_pending';
    }

    public function title(): string
    {
        return 'Courier statements to post';
    }

    public function description(): ?string
    {
        return 'Statements imported from a courier and still in draft, so their payout is not in the books yet. As at now.';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Finance];
    }

    public function permission(): ?string
    {
        return 'sales.reports.view';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/sales/courier-statements';
    }

    public function emptyText(): string
    {
        return 'Every imported statement is posted.';
    }

    /** Four columns of a table: one column cut the title and the parcels (QA walk, 11 Oct 2026). */
    public function size(): string
    {
        return 'md';
    }

    public function data(WidgetContext $context): array
    {
        $drafts = CourierStatement::query()
            ->with('courier:id,name')
            ->withCount('lines')
            ->where('business_id', $context->businessId)
            ->where('status', CourierStatement::DRAFT)
            ->orderBy('statement_date')
            ->get();

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'reference', 'label' => 'Statement'],
                ['key' => 'courier', 'label' => 'Courier'],
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'lines', 'label' => 'Parcels', 'align' => 'right', 'unit' => 'count'],
            ],
            'rows' => $drafts->take(self::SHOWN)->map(fn (CourierStatement $s) => [
                'id' => $s->id,
                'url' => "/sales/courier-statements/{$s->id}",
                'cells' => [
                    'reference' => $s->statement_reference,
                    'courier' => (string) ($s->courier?->name ?? ''),
                    'date' => $s->statement_date?->format('j M Y'),
                    'lines' => (int) $s->lines_count,
                ],
            ])->values()->all(),
            'total_count' => $drafts->count(),
            'basis' => 'operational',
            'watch' => ['drafts' => $drafts->count()],
        ];
    }
}
