<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Ledger;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * Income by day across the range, from posted journals: the 4xxx section net
 * of returns and discounts (they sit under 4 too), ex-VAT. Revenue is
 * recognised at invoice, so a courier parcel counts the day its delivery is
 * recorded. Gross profit per day rides beside it (income less 5xxx).
 */
class IncomeTrendWidget extends Widget
{
    public function key(): string
    {
        return 'income_trend';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'Sales income';
    }

    public function description(): ?string
    {
        return 'Posted income by journal date (ex-VAT, net of returns and discounts) and gross profit after cost of goods sold.';
    }

    public function category(): string
    {
        return 'Sales';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Finance];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function size(): string
    {
        return 'lg';
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

    public function refreshOn(): array
    {
        return [DashboardEventType::PAYMENT_RECEIVED, DashboardEventType::ORDER_APPROVED];
    }

    public function data(WidgetContext $context): array
    {
        $range = $context->range;
        $income = Ledger::dailyByPrefix($context->businessId, '4', $range);
        $cost = Ledger::dailyByPrefix($context->businessId, '5', $range);

        $points = [];

        foreach ($range->dates() as $date) {
            $in = -($income[$date] ?? 0);
            $points[] = [
                'date' => $date,
                'label' => date('j M', strtotime($date)),
                'income' => self::taka($in),
                'gross_profit' => self::taka($in - ($cost[$date] ?? 0)),
            ];
        }

        $total = -Ledger::totalByPrefix($context->businessId, '4', $range);
        $previous = -Ledger::totalByPrefix($context->businessId, '4', $range->previous());

        return [
            'kind' => 'series',
            'chart' => 'area',
            'unit' => 'money',
            'series' => [
                ['key' => 'income', 'label' => 'Income'],
                ['key' => 'gross_profit', 'label' => 'Gross profit'],
            ],
            'points' => $points,
            'total' => self::taka($total),
            'previous_total' => self::taka($previous),
            'basis' => 'ledger',
        ];
    }
}
