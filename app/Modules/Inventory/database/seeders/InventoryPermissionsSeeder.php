<?php

namespace App\Modules\Inventory\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions are global rows (spatie doesn't scope the `permissions` table by
 * team), but role_has_permissions is reached through each business's own Role
 * row, so granting these to "Owner" for LaLaDia never touches Bionic's Owner.
 */
class InventoryPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'inventory.catalog.manage',
            'inventory.stock.record',
            'inventory.stock.adjust',
            'inventory.stock.request',
            'inventory.stock.approve',
            'inventory.reports.view',
        ],
        'Manager' => [
            'inventory.catalog.manage',
            'inventory.stock.record',
            'inventory.stock.adjust',
            'inventory.stock.request',
            'inventory.stock.approve',
            'inventory.reports.view',
        ],
        'Staff' => [
            'inventory.stock.record',
            'inventory.stock.request',
            'inventory.reports.view',
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
                    $role->syncPermissions($permissions);
                }
            }
        }
    }
}
