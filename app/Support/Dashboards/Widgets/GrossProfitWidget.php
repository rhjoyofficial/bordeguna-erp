<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Accounting\Support\IncomeStatement;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;

/**
 * Gross profit for the range, straight from IncomeStatement (the P&L screen's
 * own engine), compared with the previous period.
 */
class GrossProfitWidget extends Widget
{
    public function key(): string
    {
        return 'gross_profit';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'Gross profit';
    }

    public function description(): ?string
    {
        return 'Income less cost of goods sold for the range, from the income statement. Cost is released at invoice.';
    }

    public function category(): string
    {
        return 'Money';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Finance];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function dateBasis(): ?string
    {
        return 'journal date';
    }

    public function cacheSeconds(): int
    {
        return 60;
    }

    public function refreshSeconds(): int
    {
        return 120;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/income-statement?'.http_build_query(['from' => $context->range->fromDate(), 'to' => $context->range->toDate()]);
    }

    public function data(WidgetContext $context): array
    {
        $statement = fn ($range) => IncomeStatement::between(
            Carbon::parse($range->fromDate()),
            Carbon::parse($range->toDate()),
        );

        $now = $statement($context->range);
        $before = $statement($context->range->previous());

        return [
            'kind' => 'stat',
            'unit' => 'money',
            'value' => self::taka($now->grossProfit()->minor),
            'previous' => self::taka($before->grossProfit()->minor),
            'parts' => [
                ['label' => 'Income', 'value' => self::taka($now->totalIncome()->minor), 'unit' => 'money'],
                ['label' => 'Margin', 'value' => $now->grossMarginPercent(), 'unit' => 'percent'],
                ['label' => 'Net result', 'value' => self::taka($now->netProfit()->minor), 'unit' => 'money'],
            ],
            'basis' => 'ledger',
            'tone' => $now->grossProfit()->minor < 0 ? 'bad' : null,
        ];
    }
}
