<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Accounting\Support\MoneyLedgers;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;

/**
 * Cash and equivalents: what the business holds in cash, bank and mobile
 * wallets right now, from the ledger heads under 1110 / 1120 / 1130.
 *
 * ⚠ Courier (1145) and gateway (1146) money is NOT cash (Slice 0, D2); it has
 * its own widget beside this one.
 */
class CashPositionWidget extends Widget
{
    public function key(): string
    {
        return 'cash_position';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'Cash and equivalents';
    }

    public function description(): ?string
    {
        return 'Cash in hand, bank and mobile wallets, from posted journals. Money still with couriers or the gateway is not counted here.';
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

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function cacheSeconds(): int
    {
        return 20;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/cash-and-bank';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::PAYMENT_RECEIVED];
    }

    public function data(WidgetContext $context): array
    {
        $byGroup = MoneyLedgers::cashLines($context->businessId)
            ->join('accounts as groups', 'groups.id', '=', 'accounts.parent_id')
            ->groupBy('groups.system_slug')
            ->selectRaw('groups.system_slug as slug, SUM(journal_lines.amount_minor) as total')
            ->pluck('total', 'slug');

        $parts = [];

        foreach (MoneyLedgers::KINDS as [$slug, $name]) {
            $parts[] = ['label' => $name, 'value' => self::taka((int) ($byGroup[$slug] ?? 0)), 'unit' => 'money'];
        }

        $total = MoneyLedgers::cashMinor($context->businessId);

        return [
            'kind' => 'stat',
            'unit' => 'money',
            'value' => self::taka($total),
            'parts' => $parts,
            'basis' => 'ledger',
            'tone' => $total < 0 ? 'bad' : null,
            'watch' => ['cash_minor' => $total],
        ];
    }
}
