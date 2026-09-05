<?php

namespace App\Modules\Accounting\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may read the books, change the chart, and write to the ledger.
 *
 * ⚠⚠ THREE PERMISSIONS, DELIBERATELY SEPARATE, in increasing order of danger:
 *
 * `accounting.view` reads. It is NOT a general-purpose permission - a general
 * ledger eventually carries a line per salary payment, so reading it is close to
 * reading payroll. It follows the C5/ACL-02 principle already established for
 * salary: Owner, Finance and Payroll, nobody else by default.
 *
 * `accounting.chart.manage` changes what an account MEANS. Renaming or
 * re-parenting an account silently changes every report that has ever been run
 * against it, so it sits with the Owner until a real accountant has a role.
 *
 * `accounting.journal.post` writes to the ledger by hand, which is the highest
 * risk permission in the whole system (C-GL-6): it is the one action that can
 * move money between any two accounts with no document behind it. Owner only.
 *
 * `accounting.income.record` records money that came in and was not a sale.
 * ⚠ It exists rather than reusing `accounting.journal.post` because the whole
 * point of the income voucher is that recording other income should NOT require
 * the one permission that can move money anywhere with no document. Owner and
 * Finance; the document itself refuses sales revenue, so it can never become a
 * back door into the sales figures.
 *
 * ℹ `accounting.journal.post` gates nothing until slice 2.3. That is the right
 * way round - a permission that exists before its route is inert, a route that
 * exists before its permission is open.
 *
 * ℹ The accountant, when they arrive, goes on the `Finance` role through the
 * Roles UI. That is one grant, not a code change, which is the point of having
 * kept the roles configurable.
 */
class AccountingPermissionsSeeder extends Seeder
{
    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'accounting.view',
            'accounting.chart.manage',
            'accounting.journal.post',
            'accounting.income.record',
        ],
        'Finance' => [
            'accounting.view',
            // ⚠ Receiving money is a different act from reading the books, and
            // a smaller one than writing a journal by hand. Finance can record
            // scrap, interest or a refund without being handed the ledger key.
            'accounting.income.record',
        ],
        // Musa's role. He prepares and pays; reading a trial balance is part of
        // that, writing to it is not - the same separation that already keeps
        // him from approving his own payroll run.
        'Payroll' => [
            'accounting.view',
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

                // givePermissionTo is additive - syncPermissions would wipe out
                // whatever other modules' seeders already granted this role.
                // ⚠ The role may not exist in every business: `Payroll` is part
                // of Bor de Guna's real structure, not the seeded default set.
                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
