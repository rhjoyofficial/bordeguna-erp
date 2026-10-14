<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Accounting\Support\Ageing;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;

/**
 * Who owes us and for how long: Ageing::receivables as at today, the same
 * engine the receivables ageing screen runs (tied to 1140 on that screen).
 */
class ReceivablesAgeingWidget extends Widget
{
    public function key(): string
    {
        return 'receivables_ageing';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'Receivables';
    }

    public function description(): ?string
    {
        return 'What customers owe as at today, by how overdue it is. Five buckets always, so an empty one reads as empty.';
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
        // The operating-reports gate the ageing screen uses (Q19); accounting
        // readers pass it too.
        return 'accounting.operations.view';
    }

    public function size(): string
    {
        return 'md';
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
        return '/accounting/receivables-ageing';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::PAYMENT_RECEIVED];
    }

    public function data(WidgetContext $context): array
    {
        return AgeingPayload::from(Ageing::receivables(Carbon::today()), 'Overdue');
    }
}
