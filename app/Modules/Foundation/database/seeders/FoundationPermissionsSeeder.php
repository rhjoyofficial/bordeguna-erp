<?php

namespace App\Modules\Foundation\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Platform-level permissions owned by Foundation (user & role management),
 * as opposed to the domain permissions each business module seeds. Same
 * shape as the module permission seeders: permissions are global rows,
 * granted per business through that business's own Role. Super Admins do
 * not need these — they bypass every gate (see FoundationServiceProvider).
 */
class FoundationPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        // Managing who belongs to a business, and who holds which role, is an
        // Owner-level responsibility — Managers/Staff do not administer access.
        'Owner' => [
            'foundation.users.manage',
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

                // Additive — never syncPermissions (would wipe other modules' grants).
                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
