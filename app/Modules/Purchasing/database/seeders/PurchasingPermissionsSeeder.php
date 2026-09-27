<?php

namespace App\Modules\Purchasing\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Mirrors InventoryPermissionsSeeder's shape: Staff gets the operational
 * permissions (create a PO, record what physically arrived) but not the
 * financial-commitment ones (approving a PO, paying for it) — same
 * philosophy as Inventory's direct-adjust-vs-approval-queue split.
 *
 * ⚠⚠ `purchasing.payment.record` is deliberately NOT `purchasing.payment.update`.
 * The older one guarded a payment-STATUS string on a purchase order, which moved
 * no money and reached no ledger. This one lets money actually leave the
 * business. Reusing the existing name would have silently handed every current
 * holder the power to pay suppliers, which is exactly the kind of quiet
 * privilege widening an ACL review is supposed to catch.
 *
 * ⚠ `purchasing.payment.update` is no longer seeded (21 Sep 2026): the
 * dropdown it guarded was removed and it gates nothing. The row already in an
 * existing database is left alone, since deleting a permission also drops every
 * role's grant of it, and that is a decision for a person, not a seeder.
 *
 * ⚠ Owner and Manager only. Staff can raise a PO and receive goods; deciding
 * that money leaves the bank is a different job.
 *
 * ⚠⚠ `purchasing.expense.record` follows the same reasoning. An expense voucher
 * pays money out with NO supplier bill behind it and no goods to count, so it is
 * the least verifiable way money leaves the business - closer to a payment than
 * to a goods receipt. Owner and Manager only, deliberately.
 */
class PurchasingPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'purchasing.suppliers.manage',
            // ⭐⭐ Plan D11 (Joy's answer 19): an NID scan opens for the Owner
            // and nobody else by default. See HoldsIdentityDocuments.
            'purchasing.suppliers.identity.view',
            'purchasing.po.create',
            'purchasing.po.approve',
            'purchasing.receipt.record',
            'purchasing.payment.record',
            // ⚠ Same reasoning as payment.record: a credit note reduces what
            // leaves the bank, so recording one is a financial decision.
            // Owner and Manager only, deliberately.
            'purchasing.credit_note.record',
            'purchasing.expense.record',
            // ⚠ D-SB-5. Recording a supplier bill CREATES A LIABILITY. It moves
            // no cash, so it sits between receiving goods (Staff hold that) and
            // paying (Staff do not), and it follows the same reasoning as
            // payment.record and credit_note.record: committing the business to
            // money is a different job from handling goods. Owner and Manager.
            'purchasing.bill.record',
            'purchasing.reports.view',
        ],
        'Manager' => [
            'purchasing.suppliers.manage',
            'purchasing.po.create',
            'purchasing.po.approve',
            'purchasing.receipt.record',
            'purchasing.payment.record',
            'purchasing.credit_note.record',
            'purchasing.expense.record',
            'purchasing.bill.record',
            'purchasing.reports.view',
        ],
        // ⭐ 26 Sep 2026 (Joy, Q18): the accountant READS purchases, bills and
        // payments. Recording a bill or a payment stays a manager's act.
        'Finance' => [
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
