<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * What a business that already exists needs for owner withdrawals (Phase 2,
 * 17 Oct 2026); a fresh install gets the same from the seeders.
 *
 *  1. The mapping `inventory.owner_withdrawal` -> the head with slug
 *     `owners_drawings` (3200 Owner's Drawings on the live chart, decision
 *     D5), per business that has inventory posting switched on
 *     (inventory.stock_on_hand mapped). ⚠⚠ Found BY SLUG, never by code and
 *     never planted: the live chart is Joy's (the 5150 lesson, PLAN-PHASE1).
 *     A business whose chart has no drawings head gets no mapping, and the
 *     posting refuses with the mapping's name until the accountant points it.
 *  2. Permissions, granted the counterparty-grant way (insertOrIgnore per role
 *     name per business):
 *       accounting.owner_withdrawal.view    Owner, Finance, Manager
 *       accounting.owner_withdrawal.manage  Owner, Finance (draft, post)
 *       owner_withdrawal.authorise          Finance, Manager (the second person;
 *                                           the proprietor authorises himself
 *                                           with a note instead, D2)
 *
 * Idempotent. Data only, no structure.
 */
return new class extends Migration
{
    private const GRANTS = [
        'accounting.owner_withdrawal.view' => ['Owner', 'Finance', 'Manager'],
        'accounting.owner_withdrawal.manage' => ['Owner', 'Finance'],
        'owner_withdrawal.authorise' => ['Finance', 'Manager'],
    ];

    private const NOTE = 'Seeded default (owner withdrawal, Phase 2)';

    public function up(): void
    {
        $drawings = DB::table('accounts')->where('system_slug', 'owners_drawings')->value('id');

        if ($drawings !== null) {
            $businessIds = DB::table('account_mappings')->where('key', 'inventory.stock_on_hand')->distinct()->pluck('business_id');

            foreach ($businessIds as $businessId) {
                $exists = DB::table('account_mappings')->where('business_id', $businessId)->where('key', 'inventory.owner_withdrawal')->exists();

                if ($exists) {
                    continue;
                }

                DB::table('account_mappings')->insert([
                    'business_id' => $businessId,
                    'key' => 'inventory.owner_withdrawal',
                    'account_id' => $drawings,
                    'effective_from' => '2000-01-01',
                    'effective_to' => null,
                    'note' => self::NOTE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

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
        DB::table('account_mappings')->where('key', 'inventory.owner_withdrawal')->where('note', self::NOTE)->delete();

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
