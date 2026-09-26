<?php

namespace App\Modules\Crm\database\seeders;

use App\Modules\Foundation\Models\Business;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who may work leads.
 *
 * ⚠ Joy's D2 (August): NO new role. Call-centre agents use the existing Staff
 * role with dedicated CRM permissions.
 *
 * ⭐⭐ SUPERSEDED FOR FIELD SALES by his answer of 14 Sep 2026: two NEW CRM-only
 * roles, 'Sales Officer' and 'Sales Lead'. Measured on production that day,
 * Shoykod (ASM) and his two officers held only the empty Self-service role, and
 * the existing roles were the wrong shape for them - Staff also opens Sales,
 * Inventory and Purchasing, Manager also opens HRM and Recruitment. The new
 * roles carry CRM permissions and nothing else, and are added NEXT TO
 * Self-service, never instead of it. Staff keeps its D2 meaning for a call
 * centre.
 *

 * ⚠ The same split every other module uses: Staff does the operational work -
 * capturing and working leads - while the things that shape REPORTING AXES
 * (sources, campaigns) or WORKLOAD (assignment) stay with Owner and Manager.
 * A source invented mid-campaign by a busy agent is how a margin report ends up
 * with four spellings of "Meta".
 *
 * ⚠⚠ `givePermissionTo` is additive. `syncPermissions` would wipe whatever the
 * Inventory, Purchasing, Sales and Accounting seeders already granted this role.
 */
class CrmPermissionsSeeder extends Seeder
{
    /**
     * ⚠ The phone book gets its OWN pair (29 Aug 2026) rather than riding on the
     * lead permissions. It holds people who are neither leads nor customers, and
     * it is what a future SMS or email send is aimed at. Staff may read it and
     * work with groups; changing who is in the book, and pulling CRM records
     * into it, stays with Owner and Manager for the same reason sources and
     * campaigns do. A send costs money, and the audience is half of the send.
     */
    private const ROLE_PERMISSIONS = [
        // ⚠⚠ `crm.campaigns.send` and `crm.campaigns.approve` are SEPARATE, and
        // only the Owner approves. A send is the one action in this ERP that
        // spends money by itself, so the person who presses send must not also
        // be the person who agrees to the spend - the same preparer/approver
        // split payroll already uses.
        // ⚠⚠ `crm.leads.verify` is NOT given to Staff, and that is the entire
        // point of choosing verification over approval-on-every-edit. A field
        // officer creates a retailer, works it, corrects its phone and records
        // visits immediately and unblocked - but cannot certify their own
        // record, and cannot archive it out of sight. The same preparer/approver
        // split as `crm.campaigns.send` above and payroll.
        //
        // ⚠ It also gates ARCHIVING, because there is no delete: taking a lead
        // out of everybody's working list is the same class of decision as
        // saying it is genuine.
        //
        // ⭐⭐ `crm.leads.view.all` is the OWNER'S ALONE (Joy, 14 Sep 2026:
        // "only the Owner sees every lead"). Everybody else sees their own leads
        // and their team's - see LeadVisibility. Manager deliberately does not
        // get it; measured on production 15 Sep, nobody in Bor de Guna holds
        // Manager, so nobody lost access on the day this shipped.
        //
        // ⭐⭐ `crm.samples.judge` is the OWNER'S ALONE too (Joy, answer 15:
        // "the Owner decides"). Anyone who works a supplier lead logs a sample
        // that arrived; only the Owner says whether it passed.
        'Owner' => ['crm.leads.view', 'crm.leads.view.all', 'crm.leads.manage', 'crm.leads.assign', 'crm.leads.verify', 'crm.settings.manage', 'crm.tasks.view.all', 'crm.leads.convert', 'crm.contacts.view', 'crm.contacts.manage', 'crm.campaigns.send', 'crm.campaigns.approve', 'crm.samples.judge'],
        'Manager' => ['crm.leads.view', 'crm.leads.manage', 'crm.leads.assign', 'crm.leads.verify', 'crm.settings.manage', 'crm.tasks.view.all', 'crm.leads.convert', 'crm.contacts.view', 'crm.contacts.manage', 'crm.campaigns.send'],
        'Staff' => ['crm.leads.view', 'crm.leads.manage', 'crm.contacts.view'],

        // ⭐ Field sales (14 Sep 2026). The officer captures shops, visits them,
        // records notes and follow-ups, and corrects what he got wrong.
        // ⭐ 26 Sep 2026 (Q21): the officer takes the retailer's order too; it
        // waits for a Manager (CreateSalesOrder leaves it pending for anyone
        // without sales.orders.approve) and he sees only the orders he took.
        'Sales Officer' => ['crm.leads.view', 'crm.leads.manage', 'sales.orders.record'],
        // ⭐ The ASM adds everything Joy reserved for him: verify, reassign,
        // merge (duplicate linking rides on verify), convert and archive. He
        // sees his team's leads through the org chart, not through a permission.
        'Sales Lead' => ['crm.leads.view', 'crm.leads.manage', 'crm.leads.assign', 'crm.leads.verify', 'crm.leads.convert', 'sales.orders.record'],
    ];

    /**
     * Roles this seeder CREATES when a business does not have them yet.
     *
     * ⚠ Only the field-sales pair. Owner, Manager and Staff belong to the
     * Foundation seeder, and a module seeder quietly creating those would hide a
     * business that was set up wrong.
     */
    private const CREATES_ROLES = ['Sales Officer', 'Sales Lead'];

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

                if ($role) {
                    $role->givePermissionTo($permissions);
                }
            }
        }
    }
}
