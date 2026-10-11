<?php

namespace App\Modules\Foundation\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Which of a user's businesses they hold a permission IN.
 *
 * ⚠⚠ ROLES ARE PER BUSINESS (spatie teams, keyed by business_id). An Owner of
 * Bor de Guna who is plain staff at LaLaDia holds `accounting.view` in one and
 * not the other, so "can this user read the books" has no answer until the
 * business is named. A group screen that reads several businesses at once must
 * ask the question once per business, with the team id switched to each in
 * turn, which is what the morning briefing already does for its readers.
 *
 * ⚠ The team id is put back afterwards, whatever happens: the rest of the
 * request is still in the business the user is working in, and a team id left
 * pointing at the last business checked would answer every later `can()` for
 * the wrong business. The user's cached `roles` and `permissions` relations are
 * dropped on every switch for the same reason (spatie caches them on the model,
 * see TenantScope::enter()).
 */
final class BusinessesWithPermission
{
    /**
     * @return Collection<int, int> the business ids, in id order
     */
    public static function of(User $user, string $permission): Collection
    {
        return self::walk($user, $permission, stopAtFirst: false);
    }

    /** True if the user holds the permission in at least one of their businesses. */
    public static function any(User $user, string $permission): bool
    {
        return self::walk($user, $permission, stopAtFirst: true)->isNotEmpty();
    }

    /**
     * @return Collection<int, int>
     */
    private static function walk(User $user, string $permission, bool $stopAtFirst): Collection
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $found = collect();

        try {
            foreach ($user->businesses()->orderBy('businesses.id')->pluck('businesses.id') as $businessId) {
                $registrar->setPermissionsTeamId((int) $businessId);
                $user->unsetRelation('roles')->unsetRelation('permissions');

                if ($user->can($permission)) {
                    $found->push((int) $businessId);

                    if ($stopAtFirst) {
                        break;
                    }
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        return $found;
    }
}
