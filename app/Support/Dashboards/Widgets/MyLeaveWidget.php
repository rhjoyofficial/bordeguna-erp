<?php

namespace App\Support\Dashboards\Widgets;

use App\Events\Dashboards\DashboardEventType;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Support\LeaveBalance;
use App\Support\Dashboards\WidgetContext;

/**
 * My leave balance per type this year (LeaveBalance, the ledger behind the
 * balances screen) and my requests still waiting for a decision.
 */
class MyLeaveWidget extends EmployeeWidget
{
    public function key(): string
    {
        return 'my_leave';
    }

    public function title(): string
    {
        return 'My leave';
    }

    public function description(): ?string
    {
        return 'Days left per leave type this year, and your requests waiting for a decision.';
    }

    public function size(): string
    {
        return 'md';
    }

    public function refreshSeconds(): int
    {
        return 120;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/hrm/my-leave';
    }

    public function refreshOn(): array
    {
        return [DashboardEventType::LEAVE_DECIDED, DashboardEventType::APPROVAL_DECIDED];
    }

    public function notifications(): array
    {
        return [['watch' => 'decided', 'sound' => 'success', 'message' => 'A leave request of yours was decided.']];
    }

    public function data(WidgetContext $context): array
    {
        $me = $this->me($context);

        if ($me === null) {
            return $this->notLinked();
        }

        $balances = app(LeaveBalance::class)->summaryFor($me->id, (int) now()->year)
            ->filter(fn (array $row) => $row['deducts_balance']);

        $requests = LeaveRequest::query()->where('employee_id', $me->id)->with('leaveType:id,name')->latest('start_date')->limit(20)->get();
        $pending = $requests->filter(fn (LeaveRequest $leave) => $leave->applied_at === null
            && ($leave->approvalRequest === null || $leave->approvalRequest->status->value === 'pending'));

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'days',
            'items' => $balances->map(fn (array $row) => [
                'label' => $row['name'],
                'value' => $row['balance'],
                'hint' => $row['taken'].' taken of '.$row['annual_quota'],
            ])->values()->all(),
            'parts' => [['label' => 'Waiting for a decision', 'value' => $pending->count(), 'unit' => 'count']],
            'basis' => 'operational',
            'watch' => ['decided' => $requests->count() - $pending->count()],
        ];
    }
}
