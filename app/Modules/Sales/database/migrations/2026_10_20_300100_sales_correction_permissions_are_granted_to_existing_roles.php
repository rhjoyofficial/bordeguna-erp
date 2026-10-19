<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ Who may correct a sales document (corrections wave 1, FINAL-PLAN 3.2 "Who"):
 *
 *   sales.correct              a sales invoice: Credit note, Correct, Price credit,
 *                              Extra charge. Owner, Manager, Accountant (Finance)
 *   sales.receipts.correct     a customer receipt: Reverse, Correct. Accountant
 *                              (Finance) and the Owner. Re-allocate stays with
 *                              whoever records receipts (sales.receipt.record)
 *   sales.deliveries.reverse   a challan: Reverse. Owner, Manager
 *   sales.counter.void_any     Void sale on somebody else's sale, or after the
 *                              till shift closed. Owner, Manager (a cashier
 *                              voids her own sale while her shift is open with
 *                              sales.sell)
 *
 * Editing and cancelling an order keep sales.orders.record and
 * sales.orders.cancel. A closed month is the Owner's alone, by role, whatever
 * these say (Owners::mayCorrectClosedMonth).
 *
 * The counterparty-grant pattern: insert the permission, insertOrIgnore into
 * role_has_permissions for every role of that name in every business, forget
 * the cache. Idempotent.
 */
return new class extends Migration
{
    private const GRANTS = [
        'sales.correct' => ['Owner', 'Manager', 'Finance'],
        'sales.receipts.correct' => ['Owner', 'Finance'],
        'sales.deliveries.reverse' => ['Owner', 'Manager'],
        'sales.counter.void_any' => ['Owner', 'Manager'],
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
