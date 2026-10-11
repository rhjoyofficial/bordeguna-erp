<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Support\ApprovalInbox;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Facades\DB;

/**
 * My to-dos, approvals waiting on me, and what I asked for that nobody has
 * decided yet (My Work's three personal sections, counted).
 */
class MyTasksWidget extends EmployeeWidget
{
    public function __construct(private readonly ApprovalInbox $inbox) {}

    public function key(): string
    {
        return 'my_tasks';
    }

    public function title(): string
    {
        return 'My to-dos and approvals';
    }

    public function description(): ?string
    {
        return 'Your open to-dos, approvals waiting for you, and your requests still waiting on others.';
    }

    public function cacheSeconds(): int
    {
        return 0;
    }

    public function refreshSeconds(): int
    {
        return 30;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/work';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::APPROVAL_REQUESTED, DashboardEventType::APPROVAL_DECIDED];
    }

    public function notifications(): array
    {
        return [['watch' => 'approvals', 'sound' => 'attention', 'message' => 'Something is waiting for your decision.']];
    }

    public function data(WidgetContext $context): array
    {
        $user = $context->user;

        $todos = DB::table('personal_todos')
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on');

        $approvals = $this->inbox->countFor($user);
        $waiting = ApprovalRequest::query()->where('status', ApprovalStatus::Pending)->where('requested_by', $user->id)->count();

        return [
            'kind' => 'list',
            'parts' => [
                ['label' => 'To-dos', 'value' => (clone $todos)->count(), 'unit' => 'count', 'url' => '/work'],
                ['label' => 'For you to decide', 'value' => $approvals, 'unit' => 'count', 'url' => '/approvals', 'tone' => $approvals > 0 ? 'warn' : null],
                ['label' => 'Waiting on others', 'value' => $waiting, 'unit' => 'count', 'url' => '/work'],
            ],
            'columns' => [
                ['key' => 'title', 'label' => 'To-do'],
                ['key' => 'due', 'label' => 'Due', 'align' => 'right'],
            ],
            'rows' => $todos->limit(5)->get(['id', 'title', 'due_on'])->map(fn ($todo) => [
                'id' => $todo->id,
                'url' => '/work',
                'cells' => ['title' => $todo->title, 'due' => $todo->due_on ? date('j M', strtotime((string) $todo->due_on)) : ''],
            ])->all(),
            'total_count' => (clone $todos)->count(),
            'basis' => 'operational',
            'watch' => ['approvals' => $approvals],
        ];
    }
}
