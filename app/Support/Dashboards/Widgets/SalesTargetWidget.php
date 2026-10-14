<?php

namespace App\Support\Dashboards\Widgets;

use App\Models\User;
use App\Modules\Sales\Support\SalesTargetProgress;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Today's sales and the month so far against my target (sales_targets), with
 * what each remaining day needs. Measured exactly as the targets screen does
 * (SalesTargetProgress: my invoices ex-VAT, less returns against them).
 */
class SalesTargetWidget extends Widget
{
    public function key(): string
    {
        return 'sales_vs_target';
    }

    /** Money on the card: masked with أمان until the eye is tapped (14 Oct 2026). */
    public function sensitive(): bool
    {
        return true;
    }

    public function title(): string
    {
        return 'My sales against target';
    }

    public function description(): ?string
    {
        return 'Invoices you raised (ex-VAT, less returns) today and this month, against the target set for you.';
    }

    public function category(): string
    {
        return 'Sales';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Sales];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Self;
    }

    public function authorize(User $user): bool
    {
        return $user->canAny(['sales.orders.record', 'sales.sell']);
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
        return $context->user->can('sales.reports.view') ? '/sales/targets' : null;
    }

    public function emptyText(): string
    {
        return 'No target is set for you this month.';
    }

    public function data(WidgetContext $context): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        $target = (float) (DB::table('sales_targets')
            ->where('business_id', $context->businessId)
            ->where('user_id', $context->user->id)
            ->whereDate('month', $monthStart->toDateString())
            ->value('target_amount') ?? 0);

        $month = SalesTargetProgress::actualFor($context->businessId, $context->user->id, $monthStart->toDateString(), $monthEnd->toDateString());
        $day = SalesTargetProgress::actualFor($context->businessId, $context->user->id, $today->toDateString(), $today->toDateString());
        $daysLeft = (int) $today->diffInDays($monthEnd) + 1;
        $needed = $target > 0 ? max(0, round(($target - $month) / max(1, $daysLeft), 2)) : null;

        return [
            'kind' => 'progress',
            'unit' => 'money',
            'value' => $month,
            'target' => $target > 0 ? $target : null,
            'parts' => array_values(array_filter([
                ['label' => 'Today', 'value' => $day, 'unit' => 'money'],
                $needed !== null ? ['label' => 'Needed per day', 'value' => $needed, 'unit' => 'money'] : null,
                ['label' => 'Days left', 'value' => $daysLeft, 'unit' => 'count'],
            ])),
            'basis' => 'operational',
        ];
    }
}
