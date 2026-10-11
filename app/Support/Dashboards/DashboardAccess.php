<?php

namespace App\Support\Dashboards;

use App\Models\User;
use App\Modules\Foundation\Models\DashboardRoleAssignment;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Role;

/**
 * Who may open which dashboard in the current business, and which one they land on.
 *
 * ChatGPT ruling 1, adopted: an EXPLICIT per-business mapping (role -> dashboard,
 * Settings > Dashboards) decides; permissions only fill in for a role nobody has
 * mapped yet. The highest-ranked dashboard among the user's roles wins, and the
 * rank (DashboardKey::rank) is the stable tie-breaker.
 *
 * - Every member may open the employee dashboard: it reads only their own records.
 * - `dashboard.view_all` (Owner) opens every dashboard. A dashboard reached ONLY
 *   through it is logged by the controller (ruling 1: "dedicated, audited").
 * - Roles are per business (spatie teams), so everything here reads the roles
 *   the user holds in the business they are standing in.
 */
class DashboardAccess
{
    public const VIEW_ALL = 'dashboard.view_all';

    public function __construct(private readonly BusinessContext $context) {}

    public function canViewAll(User $user): bool
    {
        return $user->can(self::VIEW_ALL);
    }

    /**
     * The dashboards the user's own roles give them, highest first, always
     * ending with the employee dashboard.
     *
     * @return list<DashboardKey>
     */
    public function assignedKeys(User $user): array
    {
        $businessId = $this->context->id();
        $keys = [DashboardKey::Employee];

        if ($businessId !== null) {
            $roles = Role::query()
                ->where('business_id', $businessId)
                ->whereIn('id', $user->roles()->pluck('roles.id'))
                ->with('permissions:id,name')
                ->orderBy('id')
                ->get();

            $assigned = DashboardRoleAssignment::query()
                ->whereIn('role_id', $roles->pluck('id'))
                ->pluck('dashboard', 'role_id');

            foreach ($roles as $role) {
                $keys[] = DashboardKey::tryFrom((string) ($assigned[$role->id] ?? '')) ?? $this->fallbackFor($role);
            }
        }

        return self::sorted($keys);
    }

    /**
     * For a role nobody has mapped (a custom role made on Settings > Roles):
     * judged by the role's own permissions, most senior first.
     */
    public function fallbackFor(Role $role): DashboardKey
    {
        $names = $role->permissions->pluck('name');
        $has = fn (string ...$any) => $names->intersect($any)->isNotEmpty();

        return match (true) {
            $has(self::VIEW_ALL, 'foundation.organization.manage') => DashboardKey::Owner,
            $has('accounting.view') => DashboardKey::Finance,
            $has('hrm.attendance.manage', 'hrm.leave.manage', 'inventory.stock.approve', 'purchasing.po.approve', 'sales.orders.approve') => DashboardKey::Manager,
            $has('sales.sell', 'sales.orders.record') => DashboardKey::Sales,
            default => DashboardKey::Employee,
        };
    }

    /**
     * Everything the user may open: all five with view_all, otherwise their own.
     *
     * @return list<DashboardKey>
     */
    public function allowedKeys(User $user): array
    {
        return $this->canViewAll($user) ? DashboardKey::ordered() : $this->assignedKeys($user);
    }

    /** Where /dashboard takes them: the highest of their OWN roles. */
    public function landingKey(User $user): DashboardKey
    {
        return $this->assignedKeys($user)[0];
    }

    public function canOpen(User $user, DashboardKey $key): bool
    {
        return in_array($key, $this->allowedKeys($user), true);
    }

    /** True when only view_all lets them in, which is what gets audited. */
    public function reachedThroughViewAll(User $user, DashboardKey $key): bool
    {
        return ! in_array($key, $this->assignedKeys($user), true) && $this->canViewAll($user);
    }

    /**
     * Whether the switcher offers the old dashboard ("Classic"). It stays open
     * to every member exactly as before (each tile keeps its own permission
     * check); the tab is offered only to whoever would see a tile on it.
     */
    public function offersClassic(User $user): bool
    {
        return $user->canAny([
            'sales.reports.view', 'purchasing.reports.view', 'inventory.reports.view',
            'accounting.view', 'inventory.stock.approve', 'purchasing.po.approve',
        ]);
    }

    /**
     * @param  list<DashboardKey>  $keys
     * @return list<DashboardKey>
     */
    private static function sorted(array $keys): array
    {
        $unique = [];

        foreach ($keys as $key) {
            $unique[$key->value] = $key;
        }

        $list = array_values($unique);
        usort($list, fn (DashboardKey $a, DashboardKey $b) => $b->rank() <=> $a->rank());

        return $list;
    }
}
