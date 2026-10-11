<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * The three courier permissions, granted to the roles that already exist in every
 * business, here rather than left to a seeder run (a deploy step that says "run
 * the seeder" is a step that gets skipped).
 *
 * ⚠ Technical permissions only (ChatGPT risk 11): holding a courier's
 * credentials or booking a parcel gives no accounting authority. Approving a
 * discount, changing an invoice or posting a journal stays with the permissions
 * that already guard them.
 */
return new class extends Migration
{
    private const GRANTS = [
        // Credentials and webhooks: the Owner alone by default.
        'couriers.settings.manage' => ['Owner'],
        'couriers.parcels.view' => ['Owner', 'Manager', 'Staff'],
        // Real API booking arrives in a later slice.
        'couriers.parcels.book' => ['Owner', 'Manager'],
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
