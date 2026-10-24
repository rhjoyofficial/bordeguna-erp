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
            // D-MFG-4: one-step confirm by the authorised user. A run mirrors
            // physical work already done; the control is the yield report.
            'inventory.production.manage',
            // C-IMP-4. Replacing three hundred products at once is not the same
            // power as editing one row, so it is not the same permission.
            'inventory.catalogue.import',
            // C-BOM-9. A recipe decides what every future production run
            // suggests consuming, which is master data about how a product is
            // MADE - a different power from adjusting stock or editing the
            // catalogue.
            'inventory.bom.manage',
            // ⭐ Farm harvest (24 Oct 2026): crops in progress into stock, or
            // written off as a loss. Money moves between ledger heads, so it
            // is the Owner's and the Manager's, not the store's.
            'inventory.harvest.manage',
        ],
        'Manager' => [
            'inventory.catalog.manage',
            'inventory.stock.record',
            'inventory.stock.adjust',
            'inventory.stock.request',
            'inventory.stock.approve',
            'inventory.reports.view',
            'inventory.production.manage',
            'inventory.catalogue.import',
            'inventory.bom.manage',
            'inventory.harvest.manage',
        ],
        // ⭐ 26 Sep 2026 (Joy, Q18): the accountant READS the stock book, so a
        // valuation can be checked against the shelf. No recording, no adjusting.
        'Finance' => [
            'inventory.reports.view',
        ],
        // ⚠ Staff deliberately does NOT get the import. Staff records stock and
        // raises requests; rewriting the catalogue is a manager's act.
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

                // givePermissionTo is additive — syncPermissions would wipe out
                // whatever other modules' permission seeders already granted this role.
                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
