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
            // ⭐⭐ Plan D11 (Joy's answer 19): an NID scan opens for the Owner
            // and nobody else by default. See HoldsIdentityDocuments.
            'sales.customers.identity.view',
            'sales.prices.manage',
            // C-IMP-4. Loading a whole price list is a manager's act.
            'sales.prices.import',
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            'sales.orders.record',
            'sales.orders.approve',
            // Approve an order over the credit limit, or a distributor's
            // before payment, with a written reason (21 Sep 2026). Owner only.
            'sales.credit.override',
            // ⭐ Grant credit limits and days: the authority's yes on a credit
            // terms request (26 Sep 2026, Q16). Owner only by default; a
            // Manager can be given it in Settings > Roles.
            'sales.credit.approve',
            // Change a price, or give a discount, at the counter, with a
            // reason kept on the invoice (21 Sep 2026). No cap yet: the owner
            // has not set one.
            'sales.price.override',
            'sales.orders.cancel',
            'sales.returns.record',
            'sales.returns.refund',
            // ⚠⚠ SSLCommerz slice P4, Joy 16 September: the OWNER only. By bank
            // or bKash a person sends the money and the ERP records it; through
            // SSLCommerz the ERP itself moves the money.
            'sales.gateway.refund',
        ],
        'Manager' => [
            'sales.customers.manage',
            'sales.prices.manage',
            // C-IMP-4. Loading a whole price list is a manager's act.
            'sales.prices.import',
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            'sales.orders.record',
            'sales.orders.approve',
            'sales.orders.cancel',
            'sales.returns.record',
            'sales.returns.refund',
            // ⚠ Staff sell at the list price only: a changed price or a
            // discount is a margin decision, like setting the price itself.
            'sales.price.override',
        ],
        // ⚠ Staff TAKE orders (order-book data entry, the same trust level as
        // sales.sell) but cannot approve them - a staff-created order sits
        // pending until Owner/Manager review (C-SO-1) - and cannot cancel or
        // short-close one: erasing a commitment is a management decision
        // (C-SO-6), the same class as an invoice-level discount.
        //
        // ⚠ Staff RECORD returns too (C-SR-9, Joy: "both") - goods arrive when
        // they arrive - but CASH going back over the counter is Owner/Manager
        // (sales.returns.refund), the same class as cancelling an order.
        'Staff' => [
            'sales.sell',
            'sales.receipt.record',
            'sales.reports.view',
            'sales.orders.record',
            'sales.returns.record',
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
