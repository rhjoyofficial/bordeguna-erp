<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * What a business that already exists needs for supplier discounts (ruling
 * P3, 18 Oct 2026); a fresh install gets the same from the seeders.
 *
 *  1. The head "Purchase Discounts on Goods Sold" (cost of sales, slug
 *     purchase_discount_consumed): the share of a discount that belongs to
 *     goods already consumed or sold. ⚠ Its OWN head, never 5100 (the R7
 *     reconciliation ties 5100 to invoiced cost released) and never 5160
 *     Landed Cost on Goods Sold (a credit there would net discounts against
 *     late costs and neither could be read on its own). Slug first; else the
 *     first free code from 5170 up under 5000 Cost of Sales. An existing head
 *     is never renamed or adopted.
 *  2. The mapping purchasing.discount_consumed, per business that has
 *     inventory posting on. Never re-pointed.
 *  3. Permission purchasing.discount.settle (take a settlement discount when
 *     paying): Owner and Finance.
 *
 * Idempotent. Data only, no structure.
 */
return new class extends Migration
{
    private const GRANTS = [
        'purchasing.discount.settle' => ['Owner', 'Finance'],
    ];

    private const NOTE = 'Seeded default (supplier discounts)';

    public function up(): void
    {
        $head = $this->plantHead();

        if ($head !== null) {
            foreach (DB::table('account_mappings')->where('key', 'inventory.stock_on_hand')->distinct()->pluck('business_id') as $businessId) {
                if (DB::table('account_mappings')->where('business_id', $businessId)->where('key', 'purchasing.discount_consumed')->exists()) {
                    continue;
                }

                DB::table('account_mappings')->insert([
                    'business_id' => $businessId,
                    'key' => 'purchasing.discount_consumed',
                    'account_id' => $head,
                    'effective_from' => '2000-01-01',
                    'effective_to' => null,
                    'note' => self::NOTE,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

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
        DB::table('account_mappings')->where('key', 'purchasing.discount_consumed')->where('note', self::NOTE)->delete();

        foreach (array_keys(self::GRANTS) as $name) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $head = DB::table('accounts')->where('system_slug', 'purchase_discount_consumed')->where('name', 'Purchase Discounts on Goods Sold')->value('id');

        if ($head !== null && ! DB::table('journal_lines')->where('account_id', $head)->exists()) {
            DB::table('accounts')->where('id', $head)->delete();
        }
    }

    /** By slug first; else planted on the first free code from 5170 under 5000. */
    private function plantHead(): ?int
    {
        $bySlug = DB::table('accounts')->where('system_slug', 'purchase_discount_consumed')->value('id');

        if ($bySlug !== null) {
            return (int) $bySlug;
        }

        $parentId = DB::table('accounts')->where('code', '5000')->value('id');

        if ($parentId === null) {
            // No chart yet: the chart seeder plants 5170 with everything else.
            return null;
        }

        $code = null;
        foreach (range(5170, 5199) as $candidate) {
            if (! DB::table('accounts')->where('code', (string) $candidate)->exists()) {
                $code = (string) $candidate;
                break;
            }
        }

        if ($code === null) {
            return null;
        }

        return (int) DB::table('accounts')->insertGetId([
            'code' => $code,
            'name' => 'Purchase Discounts on Goods Sold',
            'type' => 'expense',
            'parent_id' => $parentId,
            'is_postable' => true,
            'is_active' => true,
            'system_slug' => 'purchase_discount_consumed',
            'note' => 'The share of a supplier trade or settlement discount that belongs to stock already consumed or sold (a credit to cost of sales). Posted by Purchases > Bills and Payments only.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
