<?php

namespace App\Modules\Hrm\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * HRM permissions. Seeded to Owner; HR-specific roles (HR Admin, HR Manager,
 * …) created through the Roles UI can be granted these without a code change.
 */
class HrmPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'hrm.employees.manage',
            'hrm.scheduling.manage',
            'hrm.attendance.manage',
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

                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
