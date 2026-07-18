<?php

namespace App\Modules\Purchasing\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Mirrors InventoryPermissionsSeeder's shape: Staff gets the operational
 * permissions (create a PO, record what physically arrived) but not the
 * financial-commitment ones (approving a PO, marking it paid) — same
 * philosophy as Inventory's direct-adjust-vs-approval-queue split.
 */
class PurchasingPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'purchasing.suppliers.manage',
            'purchasing.po.create',
            'purchasing.po.approve',
            'purchasing.receipt.record',
            'purchasing.payment.update',
            'purchasing.reports.view',
        ],
        'Manager' => [
            'purchasing.suppliers.manage',
            'purchasing.po.create',
            'purchasing.po.approve',
            'purchasing.receipt.record',
            'purchasing.payment.update',
            'purchasing.reports.view',
        ],
        'Staff' => [
            'purchasing.po.create',
            'purchasing.receipt.record',
            'purchasing.reports.view',
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
                // whatever InventoryPermissionsSeeder already granted this role.
                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
