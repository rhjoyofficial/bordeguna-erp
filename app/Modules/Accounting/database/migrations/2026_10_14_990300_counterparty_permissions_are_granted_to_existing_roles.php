<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accounting > Counterparties (loans plan v2, phase 1, 14 Oct 2026).
 *
 *   accounting.counterparties.view    read the list, a counterparty's page, its
 *                                     statement and its confirmation letter:
 *                                     Owner, Finance and Manager
 *   accounting.counterparties.manage  add, change, switch off: Owner and Finance
 *
 * Granted here to the roles that already exist in every business, rather than
 * left to a seeder run; AccountingPermissionsSeeder does the same for a fresh
 * install. Idempotent: a second run adds nothing.
 */
return new class extends Migration
{
    private const GRANTS = [
        'accounting.counterparties.view' => ['Owner', 'Finance', 'Manager'],
        'accounting.counterparties.manage' => ['Owner', 'Finance'],
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
