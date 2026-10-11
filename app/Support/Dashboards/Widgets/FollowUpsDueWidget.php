<?php

namespace App\Support\Dashboards\Widgets;

use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRM follow-ups assigned to me, due today or overdue: the edge of my queue
 * (My Work's own rule, filtered to this business by hand).
 */
class FollowUpsDueWidget extends Widget
{
    public function key(): string
    {
        return 'followups_due';
    }

    public function title(): string
    {
        return 'My follow-ups due';
    }

    public function description(): ?string
    {
        return 'Customer follow-ups assigned to you, due today or already late.';
    }

    public function category(): string
    {
        return 'Work';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Manager, DashboardKey::Sales];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Self;
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function cacheSeconds(): int
    {
        return 15;
    }

    public function emptyText(): string
    {
        return 'No follow-ups due. Well done.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/crm/queue';
    }

    public function data(WidgetContext $context): array
    {
        $due = DB::table('crm_tasks')
            ->where('business_id', $context->businessId)
            ->where('assigned_to_user_id', $context->user->id)
            ->whereNull('completed_at')
            ->where('due_at', '<=', now()->endOfDay())
            ->orderBy('due_at');

        $count = (clone $due)->count();
        $late = (clone $due)->where('due_at', '<', now())->count();

        return [
            'kind' => 'list',
            'parts' => [
                ['label' => 'Due', 'value' => $count, 'unit' => 'count'],
                ['label' => 'Late', 'value' => $late, 'unit' => 'count', 'tone' => $late > 0 ? 'bad' : null],
            ],
            'columns' => [
                ['key' => 'title', 'label' => 'Follow-up'],
                ['key' => 'due', 'label' => 'Due', 'align' => 'right'],
            ],
            'rows' => $due->limit(6)->get(['id', 'title', 'due_at'])->map(fn ($task) => [
                'id' => $task->id,
                'url' => '/crm/queue',
                'cells' => ['title' => $task->title, 'due' => Carbon::parse($task->due_at)->diffForHumans(short: true)],
            ])->all(),
            'total_count' => $count,
            'basis' => 'operational',
            'watch' => ['due' => $count],
        ];
    }
}
