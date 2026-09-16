<?php

namespace App\Modules\Assets\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may see what the business owns, add to it, and wear it down.
 *
 * ⚠ The FINANCIAL register (`assets.view`, `assets.manage`) is Owner and Manager
 * only, with no Staff row at all. An asset register says what the business owns
 * and what it paid - closer to the payroll register than to a stock list.
 *
 * ⚠⚠ `assets.depreciation.run` is OWNER ONLY. One run posts cost against every
 * asset in the business at once. The unique index makes a second run harmless,
 * which is not the same as reversible: nothing here un-posts a charge.
 *
 * ⭐ The PHYSICAL register (`assets.items.*`, asset management slice A1, 16
 * September 2026) adds a role of its own, the Asset Keeper: someone who keeps
 * the things - registers them, prints their tags, says where they are - and
 * never sees what they cost (plan §2.14). Created in every business, given to
 * nobody; the Owner assigns it.
 */
class AssetsPermissionsSeeder extends Seeder
{
    private const CREATES_ROLES = ['Asset Keeper'];

    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'assets.view',
            'assets.manage',
            'assets.depreciation.run',
            'assets.items.view',
            'assets.items.manage',
            // Slice A3: confirming a stock-take loss is the write-off decision.
            'assets.items.write_off',
        ],
        'Manager' => [
            'assets.view',
            'assets.manage',
            'assets.items.view',
            'assets.items.manage',
            'assets.items.write_off',
        ],
        'Asset Keeper' => [
            'assets.items.view',
            'assets.items.manage',
        ],
    ];

    public function run(): void
    {
        $permissionNames = collect(self::ROLE_PERMISSIONS)->flatten()->unique();

        foreach ($permissionNames as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (Business::all() as $business) {
            foreach (self::CREATES_ROLES as $roleName) {
                Role::firstOrCreate(['name' => $roleName, 'business_id' => $business->id, 'guard_name' => 'web']);
            }

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
