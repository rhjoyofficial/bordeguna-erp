<?php

namespace App\Support\Dashboards\Widgets;

use App\Models\User;
use App\Modules\Hrm\Models\Employee;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use App\Support\Dashboards\WidgetScope;

/**
 * The base of every "my ..." widget on the employee dashboard.
 *
 * ⚠⚠ STRICT SELF-SCOPE (ruling 3). The employee is ALWAYS the one linked to the
 * signed-in user (Employee::forUser), never an id from the request, so no query
 * string can point one of these at somebody else's salary, payslip, attendance,
 * leave or claims. Visible to every member: it shows only their own records.
 */
abstract class EmployeeWidget extends Widget
{
    public function dashboards(): array
    {
        return [DashboardKey::Employee];
    }

    public function category(): string
    {
        return 'Me';
    }

    public function scope(): WidgetScope
    {
        return WidgetScope::Self;
    }

    public function authorize(User $user): bool
    {
        return true;
    }

    public function cacheSeconds(): int
    {
        return 15;
    }

    protected function me(WidgetContext $context): ?Employee
    {
        return Employee::forUser($context->user);
    }

    /** @return array<string, mixed> */
    protected function notLinked(): array
    {
        return ['kind' => 'empty', 'message' => 'Your login is not linked to an employee record in this business yet. Ask HR to link it.'];
    }
}
