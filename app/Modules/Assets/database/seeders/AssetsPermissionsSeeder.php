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
 *
 * ⭐ Slices 3 and 4 (13 October 2026, Joy's answers 2 and 4): the ACCOUNTS HUB
 * (`assets.accounts.*`) and DEVICE LOGINS (`assets.secrets.*`), and a second
 * role, the IT Custodian: keeps the things, the accounts and their logins,
 * and never sees the financial register. At launch: Moin (BDG-000001) and
 * Joy (BDG-000016); the Owner assigns it. The Asset Keeper gets NONE of the
 * new permissions: keeping a printer is not the same as holding its password.
 *
 * ⭐⭐ Review of 13 October (ruling 6), which CHANGES Joy's answer 1: the
 * permissions are split, and the MANAGER reveals and manages NO secret.
 * A Manager reads the hub and its renewal costs; a Manager who must hold
 * passwords is given the IT Custodian role by the Owner, deliberately.
 * - `assets.accounts.access.manage`: grant and revoke who uses an account.
 * - `assets.accounts.costs.view`: renewal costs (Owner, Manager; NOT the IT
 *   Custodian, who has no money in their remit).
 * - `assets.secrets.reveal_privileged`: administrator and recovery logins,
 *   which no holder opens (Owner, IT Custodian).
 *
 * ⚠ Additive, and re-runnable: a permission a role once had is never taken
 * away here. The Manager's old `assets.secrets.*` grants from the first run
 * of this seeder are REVOKED explicitly below, because that is the one case
 * where leaving it would leave a password door open.
 */
class AssetsPermissionsSeeder extends Seeder
{
    private const CREATES_ROLES = ['Asset Keeper', 'IT Custodian'];

    /** Permissions a role must NOT keep from an earlier run of this seeder. */
    private const REVOKES = [
        'Manager' => ['assets.secrets.manage', 'assets.secrets.reveal'],
    ];

    private const ROLE_PERMISSIONS = [
        'Owner' => [
            'assets.view',
            'assets.manage',
            'assets.depreciation.run',
            'assets.items.view',
            'assets.items.manage',
            // Slice A3: confirming a stock-take loss is the write-off decision.
            'assets.items.write_off',
            // Corrections wave 3 (24 Oct 2026): a thing leaves the company.
            'assets.items.dispose',
            'assets.accounts.view',
            'assets.accounts.manage',
            'assets.accounts.access.manage',
            'assets.accounts.costs.view',
            'assets.secrets.manage',
            'assets.secrets.reveal',
            'assets.secrets.reveal_privileged',
        ],
        'Manager' => [
            'assets.view',
            'assets.manage',
            'assets.items.view',
            'assets.items.manage',
            'assets.items.write_off',
            'assets.items.dispose',
            // Reads the hub and what renewals cost; changes nothing in it and
            // holds no password (review ruling 6).
            'assets.accounts.view',
            'assets.accounts.costs.view',
        ],
        'Asset Keeper' => [
            'assets.items.view',
            'assets.items.manage',
        ],
        'IT Custodian' => [
            'assets.items.view',
            'assets.items.manage',
            'assets.items.dispose',
            'assets.accounts.view',
            'assets.accounts.manage',
            'assets.accounts.access.manage',
            'assets.secrets.manage',
            'assets.secrets.reveal',
            'assets.secrets.reveal_privileged',
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

                    if (isset(self::REVOKES[$roleName])) {
                        $role->revokePermissionTo(self::REVOKES[$roleName]);
                    }
                }
            }
        }
    }
}
