<?php

namespace App\Modules\Assets\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may see what the business owns, add to it, and wear it down.
 *
 * ⚠ Owner and Manager only, with no Staff row at all. An asset register says
 * what the business owns and what it paid - closer to the payroll register than
 * to a stock list, and there is no operational task that needs it.
 *
 * ⚠⚠ `assets.depreciation.run` is OWNER ONLY. One run posts cost against every
 * asset in the business at once. The unique index makes a second run harmless,
 * which is not the same as reversible: nothing here un-posts a charge.
 */
class AssetsPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'assets.view',
            'assets.manage',
            'assets.depreciation.run',
        ],
        'Manager' => [
            'assets.view',
            'assets.manage',
        ],
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

                // Additive: syncPermissions would wipe whatever the other module
                // seeders have already granted this role.
                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
