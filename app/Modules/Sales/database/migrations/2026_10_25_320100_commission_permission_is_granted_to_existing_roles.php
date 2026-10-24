<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Commission agreements (Joy's Q18, 25 Oct 2026): who makes a deal, settles
 * it, pays it and reverses either. Owner, Manager and Finance (the same people
 * who correct sales documents). A fresh install gets it from
 * SalesPermissionsSeeder. Idempotent.
 */
return new class extends Migration
{
    private const GRANTS = [
        'sales.commission.manage' => ['Owner', 'Manager', 'Finance'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
