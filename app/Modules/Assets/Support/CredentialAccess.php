<?php

namespace App\Modules\Assets\Support;

use App\Models\User;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may manage and who may reveal the logins of a thing or an account
 * (asset register slice 3, Joy's answer 1 of 13 October 2026).
 *
 * ⭐ Reveal: whoever holds `assets.secrets.reveal` (Owner, Manager, IT
 * Custodian), the CURRENT HOLDER of a thing for that thing's logins (they need
 * the laptop password to use the laptop), and an employee with ACTIVE access
 * to an account for that account's logins.
 *
 * ⚠ One class, two questions, so the controller, the page and My accounts all
 * answer "may this person see this" the same way. The password confirmation
 * and the throttle sit in the route; this decides only WHO.
 */
class CredentialAccess
{
    public static function mayManage(User $user, Model $owner): bool
    {
        return $user->can('assets.secrets.manage');
    }

    public static function mayReveal(User $user, Model $owner): bool
    {
        if ($user->can('assets.secrets.reveal')) {
            return true;
        }

        $employee = Employee::forUser($user);

        if ($employee === null) {
            return false;
        }

        if ($owner instanceof AssetItem) {
            return $owner->custodian_employee_id === $employee->id;
        }

        if ($owner instanceof CompanyAccount) {
            return $owner->access()->where('employee_id', $employee->id)->whereNull('revoked_on')->exists();
        }

        return false;
    }

    /**
     * The card is drawn for anyone who may reveal or manage; absent otherwise.
     */
    public static function maySee(User $user, Model $owner): bool
    {
        return self::mayManage($user, $owner) || self::mayReveal($user, $owner);
    }

    /**
     * How the owner is named in a log line: a thing by its tag, an account by
     * its name.
     */
    public static function ownerLabel(Model $owner): string
    {
        if ($owner instanceof AssetItem) {
            return "{$owner->tag} {$owner->name}";
        }

        if ($owner instanceof CompanyAccount) {
            return $owner->name;
        }

        return class_basename($owner).' #'.$owner->getKey();
    }
}
