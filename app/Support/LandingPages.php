<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Foundation\Support\BusinessContext;
use Spatie\Permission\Models\Role;

/**
 * What each person sees first after login (C-LAND).
 *
 * Joy's concept, 31 Aug 2026: the powerful see the current dashboard, normal
 * staff their personal corner, HR the HR dashboard, sellers the counter - and
 * a role can be assigned a specific page that login goes to first.
 *
 * ⚠⚠ C-LAND-1: targets come from THIS catalogue, never a typed URL. Each
 * entry names the permission it needs (null = open to every member), and
 * resolution skips anything the user cannot open - an assignment must never
 * become a 403 the moment somebody logs in.
 */
class LandingPages
{
    /**
     * @var array<string, array{label: string, path: string, permission: ?string}>
     */
    private const CATALOGUE = [
        'dashboard' => ['label' => 'Main dashboard', 'path' => '/dashboard', 'permission' => null],
        // Open on purpose: its widget registry already filters per person.
        'hrm_dashboard' => ['label' => 'HR dashboard', 'path' => '/hrm/dashboard', 'permission' => null],
        'inventory_dashboard' => ['label' => 'Inventory dashboard', 'path' => '/inventory', 'permission' => 'inventory.reports.view'],
        'stock' => ['label' => 'Stock', 'path' => '/inventory/stock', 'permission' => 'inventory.reports.view'],
        'sales_orders' => ['label' => 'Sales orders', 'path' => '/sales/orders', 'permission' => 'sales.reports.view'],
        'pos_counter' => ['label' => 'POS counter', 'path' => '/sales/counter', 'permission' => 'sales.sell'],
        'payroll' => ['label' => 'Payroll', 'path' => '/hrm/payroll', 'permission' => 'hrm.payroll.manage'],
        'my_profile' => ['label' => 'My profile (personal)', 'path' => '/hrm/my-profile', 'permission' => null],
        'clock' => ['label' => 'Clock in/out', 'path' => '/hrm/clock', 'permission' => null],
    ];

    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** The picker's rows on the roles screen. */
    public static function options(): array
    {
        return collect(self::CATALOGUE)
            ->map(fn (array $entry, string $key) => ['key' => $key, 'label' => $entry['label']])
            ->values()
            ->all();
    }

    /**
     * C-LAND-3. The user's roles in the current business that carry a landing
     * (by role id, a total order) - first one the user may actually open wins.
     * Then the capability defaults, ending at a page with no permission at
     * all, so this can never fall through to nothing.
     */
    public static function resolveFor(User $user): string
    {
        $businessId = app(BusinessContext::class)->id();

        $assigned = Role::query()
            ->where('business_id', $businessId)
            ->whereNotNull('landing_page')
            ->whereIn('id', $user->roles()->pluck('roles.id'))
            ->orderBy('id')
            ->pluck('landing_page');

        foreach ($assigned as $key) {
            $entry = self::CATALOGUE[$key] ?? null;

            if ($entry !== null && ($entry['permission'] === null || $user->can($entry['permission']))) {
                return $entry['path'];
            }
        }

        // The capability defaults: Joy's concept, as a chain.
        if ($user->can('foundation.organization.manage') || $user->can('accounting.view')) {
            return '/dashboard';
        }

        if ($user->can('hrm.employees.manage') || $user->can('hrm.attendance.manage')) {
            return '/hrm/dashboard';
        }

        if ($user->can('sales.sell')) {
            return '/sales/counter';
        }

        if ($user->can('sales.reports.view')) {
            return '/sales/orders';
        }

        if ($user->can('inventory.reports.view')) {
            return '/inventory';
        }

        // The personal corner: open to every member, so the chain cannot miss.
        return '/hrm/my-profile';
    }
}
