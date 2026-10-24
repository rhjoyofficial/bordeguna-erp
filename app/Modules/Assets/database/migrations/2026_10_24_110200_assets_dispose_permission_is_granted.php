<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Corrections wave 3, B11 (24 Oct 2026): who may say a thing left the
 * company (FINAL-PLAN 3.5, "Custodian or Manager starts. Approval by value").
 *
 *   assets.items.dispose   Dispose and Undo disposal: Owner, Manager, IT Custodian
 *
 * ⚠ A permission of its own, not `assets.items.write_off`: the IT Custodian
 * disposes of a dead router without being the person who confirms a count's
 * loss. Approval by value is in the policy: above the Tk 20,000 limit
 * (FinancePolicy) only the Owner or a Manager disposes.
 *
 * The counterparty-grant pattern (insert, insertOrIgnore per role of that
 * name in every business, forget the cache). Idempotent.
 */
return new class extends Migration
{
    private const GRANTS = [
        'assets.items.dispose' => ['Owner', 'Manager', 'IT Custodian'],
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
