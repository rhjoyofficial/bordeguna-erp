<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 2, B8 (24 Oct 2026, FINAL-PLAN 3.5, PHASE-0-CONTRACT 2.2
 * "pattern for later modules"): `assets.correct` = Reverse or Dispose a fixed
 * asset on the books. The plan's "Accountant prepares, Owner approves": the
 * Owner acts, so the Owner holds it. The Tk 20,000 second person and the
 * Owner-only closed month still apply to Reverse, through the corrections
 * process.
 *
 * A fresh install gets the same from AssetsPermissionsSeeder. Idempotent.
 */
return new class extends Migration
{
    private const GRANTS = [
        'assets.correct' => ['Owner'],
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
