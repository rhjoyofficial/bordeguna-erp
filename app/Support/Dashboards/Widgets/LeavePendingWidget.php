<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Models\User;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;

/**
 * Leave requests not yet decided: for the people this manager supervises, or
 * the whole business for whoever manages leave. Same "pending" rule as the
 * My team screen (not applied, no decision or a pending one).
 */
class LeavePendingWidget extends Widget
{
    public function key(): string
    {
        return 'leave_pending';
    }

    public function title(): string
    {
        return 'Leave requests pending';
    }

    public function description(): ?string
    {
        return 'Requests waiting for a decision, oldest start date first.';
    }

    public function category(): string
    {
        return 'People';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Manager];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Team;
    }

    public function authorize(User $user): bool
    {
        return true;
    }

    public function size(): string
    {
        return 'md';
    }

    public function refreshSeconds(): int
    {
        return 30;
    }

    public function cacheSeconds(): int
    {
        return 15;
    }

    public function emptyText(): string
    {
        return 'No leave is waiting for a decision.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return $context->user->can('hrm.leave.manage') ? '/hrm/leave' : '/hrm/my-team';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::APPROVAL_REQUESTED, DashboardEventType::APPROVAL_DECIDED, DashboardEventType::LEAVE_DECIDED];
    }

    public function notifications(): array
    {
        return [['watch' => 'pending', 'sound' => 'attention', 'message' => 'A new leave request is waiting.']];
    }

    public function data(WidgetContext $context): array
    {
        $query = LeaveRequest::query()
            ->whereNull('applied_at')
            ->where(fn ($q) => $q
                ->whereDoesntHave('approvalRequest')
                ->orWhereHas('approvalRequest', fn ($approval) => $approval->where('status', 'pending')))
            ->with(['leaveType:id,name', 'employee:id,first_name,last_name'])
            ->orderBy('start_date');

        if (! $context->user->can('hrm.leave.manage')) {
            $query->whereIn('employee_id', TeamAttendanceWidget::supervisedBy($context->user));
        }

        $requests = $query->get();

        return [
            'kind' => 'list',
            'columns' => [
                ['key' => 'employee', 'label' => 'Employee'],
                ['key' => 'type', 'label' => 'Leave'],
                ['key' => 'dates', 'label' => 'Dates'],
                ['key' => 'days', 'label' => 'Days', 'align' => 'right'],
            ],
            'rows' => $requests->take(6)->map(fn (LeaveRequest $leave) => [
                'id' => $leave->id,
                'url' => $context->user->can('hrm.leave.manage') ? '/hrm/leave' : '/hrm/my-team',
                'cells' => [
                    'employee' => trim(($leave->employee?->first_name ?? '').' '.($leave->employee?->last_name ?? '')),
                    'type' => $leave->leaveType?->name,
                    'dates' => $leave->start_date?->format('j M').($leave->end_date && ! $leave->end_date->equalTo($leave->start_date) ? ' to '.$leave->end_date->format('j M') : ''),
                    'days' => (float) $leave->days,
                ],
            ])->values()->all(),
            'total_count' => $requests->count(),
            'basis' => 'operational',
            'watch' => ['pending' => $requests->count()],
        ];
    }
}
