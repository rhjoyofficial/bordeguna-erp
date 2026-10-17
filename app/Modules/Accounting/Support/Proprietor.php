<?php

namespace App\Modules\Accounting\Support;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Hrm\Models\Employee;

/**
 * Who the proprietor of a business is (Phase 2, 17 Oct 2026, ruling Q2:
 * the sole proprietor's stock withdrawals are drawings, not sales).
 *
 * Settings > Company names him (`businesses.proprietor_user_id`). Until it
 * does, the seed rule applies: the Owner-role user whose employee record is
 * BDG-000001 (C M Moin on the live system). ⚠ A PERSON, NOT A PERMISSION:
 * holding the Owner role does not make somebody the proprietor, and the
 * super admin (Joy) is not him either.
 */
final class Proprietor
{
    public const SEED_EMPLOYEE_CODE = 'BDG-000001';

    public static function userFor(Business $business): ?User
    {
        if ($business->proprietor_user_id !== null) {
            return $business->users()->whereKey($business->proprietor_user_id)->first();
        }

        $employee = Employee::query()->where('employee_code', self::SEED_EMPLOYEE_CODE)->first();

        if ($employee === null || $employee->user_id === null) {
            return null;
        }

        $user = $business->users()->whereKey($employee->user_id)->first();

        return $user !== null && $user->hasRole('Owner') ? $user : null;
    }

    public static function is(?User $user, Business $business): bool
    {
        if ($user === null) {
            return false;
        }

        $proprietor = self::userFor($business);

        return $proprietor !== null && (int) $proprietor->id === (int) $user->id;
    }

    /** The proprietor's employee record, when he has one (the withdrawal keeps it for the HRM view). */
    public static function employeeOf(User $user): ?Employee
    {
        return Employee::query()->where('user_id', $user->id)->first();
    }
}
