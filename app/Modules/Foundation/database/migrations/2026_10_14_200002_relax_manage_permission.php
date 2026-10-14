<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * relax.manage: Settings > Relax zone (14 Oct 2026). Granted to the Owner AND
 * the Manager role in every business, here rather than left to a seeder run;
 * the seeder does the same for a fresh install (FoundationPermissionsSeeder).
 * Opening the Relax zone itself needs no permission, only a sign-in.
 */
return new class extends Migration
{
    private const PERMISSION = 'relax.manage';

    private const ROLES = ['Owner', 'Manager'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => self::PERMISSION,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::PERMISSION)->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
