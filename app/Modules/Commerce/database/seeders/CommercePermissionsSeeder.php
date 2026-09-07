<?php

namespace App\Modules\Commerce\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may run the websites.
 *
 * ⚠ No new role, the same decision every other module in this ERP has taken.
 * The model stays Owner / Manager / Staff and access is granted by permission.
 *
 * ⚠⚠ The split is deliberate and it follows the money. Staff may EDIT CONTENT,
 * because writing a product story is ordinary work. Only Owner and Manager may
 * CREATE OR CONFIGURE A STORE, because that decides the order prefix, the price
 * group and the availability policy, which between them decide what a customer
 * is charged and whose money a shared settlement is.
 *
 * ⛔ `commerce.stores.publish` is separate from `commerce.stores.manage`, and
 * only the Owner has it. Taking a store live points a real domain at real
 * software and starts taking real money from the public. That is not the same
 * decision as renaming it, and the person who prepares a store should not be
 * the only person needed to launch it. Same preparer and approver split payroll
 * already uses.
 *
 * ⚠⚠ `givePermissionTo` is additive. `syncPermissions` would wipe whatever the
 * Inventory, Purchasing, Sales, Accounting and CRM seeders already granted this
 * role, which is why it is not used here.
 */
class CommercePermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'commerce.stores.view',
            'commerce.stores.manage',
            'commerce.stores.publish',
            'commerce.content.manage',
        ],
        'Manager' => [
            'commerce.stores.view',
            'commerce.stores.manage',
            'commerce.content.manage',
        ],
        'Staff' => [
            'commerce.stores.view',
            'commerce.content.manage',
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
