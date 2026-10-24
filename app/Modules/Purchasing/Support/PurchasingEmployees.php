<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;

/**
 * ⭐ Initial plan B1 (R40, 25 Oct 2026): "Purchasing employee (reference):
 * who handled it, crucial for tracking if they carried the cash". The HR
 * employees a purchase can name, the same list the voucher screens use, less
 * anyone who has left.
 */
final class PurchasingEmployees
{
    /** @return list<array{id: int, name: string}> */
    public static function options(): array
    {
        return Employee::query()
            ->where('status', '!=', EmployeeStatus::Separated->value)
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'name' => trim($employee->fullName().($employee->employee_code ? " ({$employee->employee_code})" : '')),
            ])
            ->values()
            ->all();
    }
}
