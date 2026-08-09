<?php

namespace App\Modules\Crm\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may work leads.
 *
 * ⚠ Joy's D2: NO new role. Call-centre agents use the existing Staff role with
 * dedicated CRM permissions, which keeps the role model at Owner/Manager/Staff
 * while letting CRM access be granted separately.
 *
 * ⚠ The same split every other module uses: Staff does the operational work -
 * capturing and working leads - while the things that shape REPORTING AXES
 * (sources, campaigns) or WORKLOAD (assignment) stay with Owner and Manager.
 * A source invented mid-campaign by a busy agent is how a margin report ends up
 * with four spellings of "Meta".
 *
 * ⚠⚠ `givePermissionTo` is additive. `syncPermissions` would wipe whatever the
 * Inventory, Purchasing, Sales and Accounting seeders already granted this role.
 */
class CrmPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => ['crm.leads.view', 'crm.leads.manage', 'crm.leads.assign', 'crm.settings.manage', 'crm.tasks.view.all', 'crm.leads.convert'],
        'Manager' => ['crm.leads.view', 'crm.leads.manage', 'crm.leads.assign', 'crm.settings.manage', 'crm.tasks.view.all', 'crm.leads.convert'],
        'Staff' => ['crm.leads.view', 'crm.leads.manage'],
    ];

    public function run(): void
    {
        $permissionNames = collect(self::ROLE_PERMISSIONS)->flatten()->unique();

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Business::all() as $business) {
            foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
                $role = Role::where('business_id', $business->id)->where('name', $roleName)->first();

                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
