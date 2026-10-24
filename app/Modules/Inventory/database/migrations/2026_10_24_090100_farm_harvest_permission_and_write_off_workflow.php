<?php

use App\Modules\Inventory\database\seeders\CropWriteOffWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): what a business that already exists needs;
 * a fresh install gets the same from InventoryPermissionsSeeder and
 * CropWriteOffWorkflowSeeder.
 *
 *  1. `inventory.harvest.manage` (record a harvest, raise a crop failure
 *     write-off, reverse either), granted to Owner and Manager, the
 *     counterparty-grant way (insertOrIgnore per role name per business).
 *  2. The `crop_write_off` approval workflow: one stage, the Owner. Without
 *     it a write-off would reach nobody (EveryApprovalReachesSomebodyTest).
 *
 * Idempotent. Data only, no structure.
 */
return new class extends Migration
{
    private const GRANTS = [
        'inventory.harvest.manage' => ['Owner', 'Manager'],
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

        (new CropWriteOffWorkflowSeeder)->run();
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

        $workflowIds = DB::table('approval_workflows')->where('key', 'crop_write_off')->pluck('id');
        DB::table('approval_workflow_stages')->whereIn('approval_workflow_id', $workflowIds)->delete();
        DB::table('approval_workflows')->whereIn('id', $workflowIds)->delete();
    }
};
