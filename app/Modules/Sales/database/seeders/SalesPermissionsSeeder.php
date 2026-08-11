<?php

namespace App\Modules\Sales\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may sell, and who may decide what things cost.
 *
 * ⚠ The same split every other module here uses: Staff gets the OPERATIONAL
 * permissions - serve a customer, take the money - and not the ones that commit
 * the business financially. Setting a selling price is a margin decision, and
 * editing the customer master is where a credit limit lives, so both stay with
 * Owner and Manager.
 *
 * ⚠ A counter can still work with no customer-management rights, because the
 * walk-in customer is seeded per business and is the default for a counter sale.
 * That is the reason it exists as a real row.
 *
 * ⚠ `givePermissionTo` is additive. `syncPermissions` would wipe out whatever
 * the Inventory, Purchasing and Accounting seeders already granted this role.
 */
class SalesPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'sales.customers.manage',
            'sales.prices.manage',
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            'sales.orders.record',
            'sales.orders.approve',
            'sales.orders.cancel',
        ],
        'Manager' => [
            'sales.customers.manage',
            'sales.prices.manage',
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            'sales.orders.record',
            'sales.orders.approve',
            'sales.orders.cancel',
        ],
        // ⚠ Staff TAKE orders (order-book data entry, the same trust level as
        // sales.sell) but cannot approve them - a staff-created order sits
        // pending until Owner/Manager review (C-SO-1) - and cannot cancel or
        // short-close one: erasing a commitment is a management decision
        // (C-SO-6), the same class as an invoice-level discount.
        'Staff' => [
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            'sales.orders.record',
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
