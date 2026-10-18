<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ D-C (Joy, 18 Oct 2026): the Owner Personal Withdrawal screen is KEPT and
 * restricted to the Owner, Finance and any role explicitly given the
 * permission (an inventory role, say). The 18 Oct seed had also granted the
 * Manager role `accounting.owner_withdrawal.view` and
 * `owner_withdrawal.authorise`; those two seeded role grants are taken back
 * here. ⚠ Only the Manager ROLE's rows for these two names: a grant made to
 * a person directly (model_has_permissions) is untouched, and so is every
 * other role, so an explicit grant survives.
 *
 * Idempotent. Data only.
 */
return new class extends Migration
{
    private const REVOKED_FROM_MANAGER = ['accounting.owner_withdrawal.view', 'owner_withdrawal.authorise'];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', self::REVOKED_FROM_MANAGER)->pluck('id');
        $managerRoleIds = DB::table('roles')->where('name', 'Manager')->pluck('id');

        if ($permissionIds->isNotEmpty() && $managerRoleIds->isNotEmpty()) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permissionIds)
                ->whereIn('role_id', $managerRoleIds)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', self::REVOKED_FROM_MANAGER)->pluck('id');

        foreach (DB::table('roles')->where('name', 'Manager')->pluck('id') as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
