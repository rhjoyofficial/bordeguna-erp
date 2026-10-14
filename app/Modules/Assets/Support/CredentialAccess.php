<?php

namespace App\Modules\Assets\Support;

use App\Models\User;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\AssetCredential;
use App\Modules\Assets\Models\AssetItem;
use App\Modules\Assets\Models\CompanyAccount;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may manage and who may reveal the logins of a thing or an account
 * (asset register slice 3, Joy's answer 1 of 13 October 2026, narrowed by
 * the review's ruling 3 the same day).
 *
 * ⭐ Reveal, login by login:
 * - an ADMIN or RECOVERY login opens only to `assets.secrets.reveal_privileged`
 *   (Owner, IT Custodian). Holding the laptop is not holding its BIOS password.
 * - a USER LOGIN or OTHER opens to `assets.secrets.reveal`, and to the CURRENT
 *   HOLDER of the thing / an employee with ACTIVE access to the account, but
 *   only while the login's own `holder_may_reveal` says so.
 * - nobody reveals through a closed account or a disposed thing, and a
 *   deactivated user reveals nothing (the middleware signs them out besides).
 * - the holder of a thing a stock-take marked MISSING reveals nothing through
 *   it (FEA-006, 14 Oct 2026): the thing is no longer in the business's hands,
 *   so being its last holder is no longer a reason to know its password. The
 *   secrets permissions still reveal, so IT can change it.
 *
 * ⚠ One class, every question, so the controller, the page, My accounts and
 * the leaver check all answer "may this person see this" the same way. The
 * password confirmation and the throttle sit in the route; this decides WHO,
 * and says WHY NOT in words the refusal log can carry.
 */
class CredentialAccess
{
    public static function mayManage(User $user, Model $owner): bool
    {
        return $user->can('assets.secrets.manage');
    }

    /**
     * Null when the person may reveal this login; otherwise the reason, in
     * words that are safe to log.
     */
    public static function revealDenial(User $user, AssetCredential $credential, Model $owner): ?string
    {
        if (! self::isActive($user)) {
            return 'user deactivated';
        }

        if ($owner instanceof CompanyAccount && $owner->isClosed()) {
            return 'account closed';
        }

        if ($owner instanceof AssetItem && $owner->status === AssetStatus::Disposed) {
            return 'thing disposed';
        }

        if ($credential->isPrivileged()) {
            return $user->can('assets.secrets.reveal_privileged') ? null : 'privileged login, no reveal_privileged permission';
        }

        if ($user->can('assets.secrets.reveal')) {
            return null;
        }

        if ($owner instanceof AssetItem && $owner->status === AssetStatus::Missing) {
            return 'thing missing';
        }

        if (! self::holds($user, $owner)) {
            return $owner instanceof CompanyAccount ? 'no active access to the account' : 'not the current holder';
        }

        return $credential->holder_may_reveal ? null : 'the holder may not reveal this login';
    }

    public static function mayReveal(User $user, AssetCredential $credential, Model $owner): bool
    {
        return self::revealDenial($user, $credential, $owner) === null;
    }

    /**
     * Could this person reveal ANY login of the owner? Decides whether the
     * card is drawn and whether its footer explains the confirmation.
     */
    public static function mayRevealSome(User $user, Model $owner): bool
    {
        if (! self::isActive($user)) {
            return false;
        }

        return $user->can('assets.secrets.reveal') || $user->can('assets.secrets.reveal_privileged') || self::holds($user, $owner);
    }

    /**
     * ⚠ Read FRESH from the row, as EnsureUserIsActive does: the instance on
     * the request may be as old as the login (or, from a factory, carry no
     * `is_active` at all), and a deactivation must bite on the next request.
     */
    private static function isActive(User $user): bool
    {
        return (bool) User::query()->whereKey($user->getKey())->value('is_active');
    }

    /**
     * The current holder of a thing, or an employee with active access to an
     * account. Read from the persisted row every time: a page loaded before
     * the return does not keep the password.
     */
    public static function holds(User $user, Model $owner): bool
    {
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
     * The card is drawn for anyone who may reveal something or manage; absent otherwise.
     */
    public static function maySee(User $user, Model $owner): bool
    {
        return self::mayManage($user, $owner) || self::mayRevealSome($user, $owner);
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
