<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Foundation\Support\BusinessHealthSignals;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;

/**
 * The Business Health signals, the same list /health shows (one source,
 * BusinessHealthSignals). Each signal is behind its own permission, so the
 * payload is per person.
 */
class BusinessHealthWidget extends Widget
{
    public function key(): string
    {
        return 'business_health';
    }

    public function title(): string
    {
        return 'Business health';
    }

    public function description(): ?string
    {
        return "Today's action signals: each line links to the list where the work is done.";
    }

    public function category(): string
    {
        return 'Work';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner, DashboardKey::Manager];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Self;
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
        return '/health';
    }

    public function data(WidgetContext $context): array
    {
        $signals = BusinessHealthSignals::for($context->user);

        return [
            'kind' => 'signals',
            'items' => $signals,
            'basis' => 'operational',
            'watch' => ['problems' => collect($signals)->where('count', '>', 0)->count()],
        ];
    }
}
