<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Retail sellers (initial plan B21, R109; Joy's Q3 = A, 24 Oct 2026):
 *
 *   sales.sellers.manage   make a seller stock, hand goods over, report his
 *                          sales, take unsold goods back, see every seller's
 *                          statement, reverse a hand-over or a return.
 *                          Owner, Manager.
 *
 * The seller himself needs no permission to read his OWN statement: he is
 * known by his login (Employee::user_id), like every self-service page. A
 * reported sale's Void sale keeps the invoice's own rules.
 *
 * The counterparty-grant pattern: insert the permission, insertOrIgnore into
 * role_has_permissions for every role of that name in every business, forget
 * the cache. Idempotent.
 */
return new class extends Migration
{
    private const GRANTS = [
        'sales.sellers.manage' => ['Owner', 'Manager'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permissionId = DB::table('permissions')->where('name', (string) $name)->where('guard_name', 'web')->value('id')
                ?? DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach (DB::table('roles')->whereIn('name', array_map('strval', $roles))->pluck('id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            $permissionId = DB::table('permissions')->where('name', (string) $name)->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
