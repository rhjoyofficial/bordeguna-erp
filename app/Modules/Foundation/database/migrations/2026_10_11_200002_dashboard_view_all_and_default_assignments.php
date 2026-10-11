<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The audited see-every-dashboard permission, granted to the Owner role in every
 * business, and the default dashboard for each role that already exists, matched
 * by name. Here rather than left to a seeder run (a deploy step that says "run
 * the seeder" is a step that gets skipped). The seeders do the same for a fresh
 * install (FoundationPermissionsSeeder, DashboardAssignmentSeeder).
 *
 * ⚠ Only roles with no assignment yet get one, so re-running never overwrites a
 * choice made on Settings > Dashboards.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'Owner' => 'owner',
        'Finance' => 'finance',
        'Manager' => 'manager',
        'Staff' => 'sales',
        'Self-service' => 'employee',
    ];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'dashboard.view_all')->where('guard_name', 'web')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'name' => 'dashboard.view_all',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach (DB::table('roles')->where('name', 'Owner')->pluck('id') as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }

        $roles = DB::table('roles')
            ->whereIn('name', array_keys(self::DEFAULTS))
            ->whereNotNull('business_id')
            ->get(['id', 'name', 'business_id']);

        foreach ($roles as $role) {
            if (DB::table('dashboard_role_assignments')->where('role_id', $role->id)->exists()) {
                continue;
            }

            DB::table('dashboard_role_assignments')->insert([
                'business_id' => $role->business_id,
                'role_id' => $role->id,
                'dashboard' => self::DEFAULTS[$role->name],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'dashboard.view_all')->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        DB::table('dashboard_role_assignments')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
