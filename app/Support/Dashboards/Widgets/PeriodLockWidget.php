<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Foundation\Models\AccountingPeriod;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;

/**
 * How far the books are closed, whether the lock is open for a correction, and
 * how many days have gone by since the last closed day.
 */
class PeriodLockWidget extends Widget
{
    public function key(): string
    {
        return 'period_lock';
    }

    public function title(): string
    {
        return 'Books closed through';
    }

    public function description(): ?string
    {
        return 'Nothing can be posted on or before the closed date unless the lock is opened for a correction.';
    }

    public function category(): string
    {
        return 'Money';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Finance];
    }

    public function permission(): ?string
    {
        return 'accounting.view';
    }

    public function cacheSeconds(): int
    {
        return 120;
    }

    public function refreshSeconds(): int
    {
        return 300;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/accounting/period-close';
    }

    public function emptyText(): string
    {
        return 'The books have never been closed.';
    }

    public function data(WidgetContext $context): array
    {
        $lock = AccountingPeriod::current();

        if ($lock === null || $lock->closed_through === null) {
            return ['kind' => 'stat', 'unit' => 'text', 'value' => 'Never closed', 'tone' => 'warn', 'basis' => 'ledger'];
        }

        $through = Carbon::parse($lock->closed_through);
        $open = (int) $through->copy()->startOfDay()->diffInDays(Carbon::today());

        return [
            'kind' => 'stat',
            'unit' => 'text',
            'value' => $through->format('j M Y'),
            'tone' => $lock->is_unlocked ? 'bad' : ($open > 45 ? 'warn' : 'good'),
            'parts' => [
                ['label' => 'Lock', 'value' => $lock->is_unlocked ? 'Open for a correction' : 'Closed', 'unit' => 'text'],
                ['label' => 'Days since', 'value' => $open, 'unit' => 'count'],
            ],
            'basis' => 'ledger',
        ];
    }
}
