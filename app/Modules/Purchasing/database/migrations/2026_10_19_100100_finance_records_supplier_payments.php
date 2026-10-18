<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Joy, 18 Oct 2026: Finance records supplier payments.
 *
 * Finance already takes settlement discounts and records advances; the
 * discount box lives on the payment screen, so Finance pays suppliers too.
 * Granted to every role named Finance, in every business. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'purchasing.payment.record')->where('guard_name', 'web')->value('id');

        if ($permissionId === null) {
            return;
        }

        foreach (DB::table('roles')->where('name', 'Finance')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'purchasing.payment.record')->where('guard_name', 'web')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', DB::table('roles')->where('name', 'Finance')->pluck('id'))
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
