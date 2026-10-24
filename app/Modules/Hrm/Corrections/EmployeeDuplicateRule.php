<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Foundation\Support\DuplicateKeys;
use App\Modules\Foundation\Support\ListDuplicateRule;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ B13 (24 Oct 2026; FINAL-PLAN 5): a new employee whose National ID or phone
 * is already on another employee asks "Possible duplicate" first. A rehire is
 * a real case (the same NID on a separated row), so it warns, never blocks.
 *
 * ⚠ The sentence names the employee and code only. The NID itself is private
 * personal data and is never repeated back.
 */
class EmployeeDuplicateRule extends ListDuplicateRule
{
    public function key(): string
    {
        return 'hrm.employee';
    }

    protected function keysOf(array $payload): array
    {
        return ['nid' => DuplicateKeys::idNumber($payload['national_id'] ?? null), 'phone' => DuplicateKeys::phone($payload['phone'] ?? null)];
    }

    protected function rows(int $businessId): Builder
    {
        // ⚠ Employees belong to the group, not to one business (no business_id).
        return Employee::query()
            ->select(['id', 'employee_code', 'first_name', 'last_name', 'phone', 'national_id', 'status', 'created_at']);
    }

    protected function rowKeys(Model $row): array
    {
        /** @var Employee $row */
        return ['nid' => DuplicateKeys::idNumber($row->national_id), 'phone' => DuplicateKeys::phone($row->phone)];
    }

    protected function describe(Model $row, string $matched): string
    {
        /** @var Employee $row */
        $who = trim("{$row->fullName()} ({$row->employee_code})");

        return $matched === 'nid'
            ? "An employee with the same National ID already exists: {$who}"
            : "An employee with the phone {$row->phone} already exists: {$who}";
    }

    protected function numberOf(Model $row): string
    {
        return (string) $row->employee_code;
    }

    protected function urlOf(Model $row): ?string
    {
        return route('hrm.employees.show', $row->getKey());
    }

    protected function visibleTo(User $viewer): bool
    {
        return $viewer->can('hrm.employees.manage') || $viewer->can('hrm.employees.view');
    }
}
