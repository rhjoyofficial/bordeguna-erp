<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * Money that is the business's but not yet in hand: 1145 with couriers and
 * 1146 with the payment gateway, from posted journals.
 */
class MoneyInTransitWidget extends Widget
{
    public function key(): string
    {
        return 'money_in_transit';
    }

    public function title(): string
    {
        return 'With couriers and gateway';
    }

    public function description(): ?string
    {
        return 'Cash on delivery a courier has collected and not paid out, and online payments the gateway has not settled. Posted journals only.';
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

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/cash-and-bank';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::PAYMENT_RECEIVED, DashboardEventType::STATEMENT_IMPORTED];
    }

    public function data(WidgetContext $context): array
    {
        $bySlug = MoneyLedgers::inTransitLines($context->businessId)
            ->groupBy('accounts.system_slug')
            ->selectRaw('accounts.system_slug as slug, SUM(journal_lines.amount_minor) as total')
            ->pluck('total', 'slug');

        $courier = (int) ($bySlug['courier_receivable'] ?? 0);
        $gateway = (int) ($bySlug['gateway_receivable'] ?? 0);

        return [
            'kind' => 'stat',
            'unit' => 'money',
            'value' => self::taka($courier + $gateway),
            'parts' => [
                ['label' => 'With couriers', 'value' => self::taka($courier), 'unit' => 'money'],
                ['label' => 'With the gateway', 'value' => self::taka($gateway), 'unit' => 'money'],
            ],
            'basis' => 'ledger',
        ];
    }
}
