<?php

use App\Modules\Foundation\database\seeders\CorrectionWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may correct, approve a correction, see the Corrections report and open
 * the Recycle bin (PHASE-0-CONTRACT v2, 2.2), and the two approval workflows a
 * correction request travels through.
 *
 *   accounting.correct                 Reverse and Correct in an OPEN month: Owner, Finance
 *   accounting.correct.approve         be the second person above the limit: Owner, Finance
 *   accounting.correct.closed_period   Owner. ⚠ The code ALSO requires the Owner role, so a
 *                                      tick in Settings > Roles cannot give a closed month to Finance
 *   corrections.report.view            the monthly Corrections report: Owner, Finance
 *   recycle_bin.manage                 view and restore the bin: Owner (answer 1: Owner only)
 *
 * The counterparty-grant pattern: insert the permission, insertOrIgnore into
 * role_has_permissions for every role of that name in every business, forget
 * the cache. Idempotent. The workflows are seeded here too (same seeder a fresh
 * install runs), so a deploy that forgets `db:seed --class=...` still has a
 * request that reaches somebody.
 */
return new class extends Migration
{
    private const GRANTS = [
        'accounting.correct' => ['Owner', 'Finance'],
        'accounting.correct.approve' => ['Owner', 'Finance'],
        'accounting.correct.closed_period' => ['Owner'],
        'corrections.report.view' => ['Owner', 'Finance'],
        'recycle_bin.manage' => ['Owner'],
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

        (new CorrectionWorkflowSeeder)->run();
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
