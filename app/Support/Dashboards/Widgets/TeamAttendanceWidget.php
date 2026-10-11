<?php

namespace App\Support\Dashboards\Widgets;

use App\Models\User;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;
use Illuminate\Support\Collection;

/**
 * Today's attendance for the people this manager supervises (MyTeamController's
 * rule), or the whole business for whoever manages attendance for all of it.
 * Reporting scope is applied in the query (ruling 10), never in the browser.
 */
class TeamAttendanceWidget extends AttendanceTodayWidget
{
    public function key(): string
    {
        return 'team_attendance';
    }

    public function title(): string
    {
        return 'My team today';
    }

    public function description(): ?string
    {
        return 'The people you supervise, by status today. Not recorded yet means no punch has been processed, not absent.';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Manager];
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Team;
    }

    public function permission(): ?string
    {
        return null;
    }

    public function authorize(User $user): bool
    {
        return true;
    }

    public function emptyText(): string
    {
        return 'Nobody reports to you in this business.';
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return $context->user->can('hrm.attendance.manage') ? '/hrm/attendance/daily' : '/hrm/my-team';
    }

    protected function employeeIds(WidgetContext $context): ?Collection
    {
        if ($context->user->can('hrm.attendance.manage')) {
            return null;
        }

        return self::supervisedBy($context->user);
    }

    /** @return Collection<int, int> */
    public static function supervisedBy(User $user): Collection
    {
        $me = Employee::query()->where('user_id', $user->id)->first();

        if ($me === null) {
            return collect();
        }

        return EmployeeAssignment::query()
            ->where('supervisor_id', $me->id)
            ->whereNull('effective_to')
            ->pluck('employee_id')
            ->unique()
            ->values();
    }
}
