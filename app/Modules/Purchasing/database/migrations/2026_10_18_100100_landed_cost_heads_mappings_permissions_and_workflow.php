<?php

use App\Modules\Foundation\database\seeders\ApprovalWorkflowSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * What a business that already exists needs for landed cost (Phase 1, 17 Oct
 * 2026); a fresh install gets the same from the seeders.
 *
 *  1. The head 5160 Landed Cost on Goods Sold (cost of sales, slug
 *     landed_cost_consumed): the consumed share of a late cost. ⚠ Its OWN head
 *     beside 5100, never 5100 itself: the subledger reconciliation ties 5100 to
 *     the paisa against invoiced cost released (R7), and a landed-cost debit on
 *     it would read as a permanent break. ⚠⚠ Found by the live rehearsal, 17 Oct
 *     2026: the first draft used 5150, and the live chart already has Joy's own
 *     5150 "Purchases (Goods Not Stock-Tracked)" (added 12 Oct). An existing
 *     head is NEVER renamed or re-tagged: the slug decides, then a free code
 *     from 5160 up is planted.
 *  2. Mappings, per business that has inventory posting switched on
 *     (inventory.stock_on_hand mapped): inventory.landed_cost (the inventory
 *     head capitalised cost is debited to, seeded to Stock on Hand itself so the
 *     stock reconciliation keeps tying), purchasing.landed_cost_consumed (5150),
 *     and the expense heads of the typed lines: freight 5300, labour 6096 (or
 *     6201 Packaging Labour where the live chart has no 6090 group), bank
 *     charge 6471. Only where the target head exists; never re-pointed.
 *  3. Permissions purchasing.landed_cost.view / manage / post, granted the
 *     counterparty-grant way (insertOrIgnore per role name per business).
 *  4. The `landed_cost` approval workflow (same seeder a fresh install runs).
 *
 * Idempotent. Data only, no structure.
 */
return new class extends Migration
{
    private const GRANTS = [
        'purchasing.landed_cost.view' => ['Owner', 'Manager', 'Finance'],
        'purchasing.landed_cost.manage' => ['Owner', 'Manager'],
        'purchasing.landed_cost.post' => ['Owner', 'Manager'],
    ];

    private const NOTE = 'Seeded default (landed cost, Phase 1)';

    public function up(): void
    {
        $consumed = $this->plantConsumedHead();

        $bySlug = fn (string $slug) => DB::table('accounts')->where('system_slug', $slug)->value('id');
        $byCode = fn (string $code) => DB::table('accounts')->where('code', $code)->value('id');

        $targets = array_filter([
            'inventory.landed_cost' => $bySlug('stock_on_hand'),
            'purchasing.landed_cost_consumed' => $consumed,
            'purchasing.landed_cost.freight' => $byCode('5300'),
            'purchasing.landed_cost.labour' => $byCode('6096') ?? $byCode('6201'),
            'purchasing.landed_cost.bank_charge' => $byCode('6471'),
        ]);

        $businessIds = DB::table('account_mappings')->where('key', 'inventory.stock_on_hand')->distinct()->pluck('business_id');

        foreach ($businessIds as $businessId) {
            foreach ($targets as $key => $accountId) {
                $exists = DB::table('account_mappings')->where('business_id', $businessId)->where('key', $key)->exists();

                if ($exists) {
                    continue;
                }

                DB::table('account_mappings')->insert([
                    'business_id' => $businessId,
                    'key' => $key,
                    'account_id' => $accountId,
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

        (new ApprovalWorkflowSeeder)->run();
    }

    public function down(): void
    {
        foreach (['inventory.landed_cost', 'purchasing.landed_cost_consumed', 'purchasing.landed_cost.freight', 'purchasing.landed_cost.labour', 'purchasing.landed_cost.bank_charge'] as $key) {
            DB::table('account_mappings')->where('key', $key)->where('note', self::NOTE)->delete();
        }

        foreach (array_keys(self::GRANTS) as $name) {
            $permissionId = DB::table('permissions')->where('name', (string) $name)->value('id');

            if ($permissionId !== null) {
                DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $consumed = DB::table('accounts')->where('system_slug', 'landed_cost_consumed')->where('name', 'Landed Cost on Goods Sold')->value('id');

        if ($consumed !== null && ! DB::table('journal_lines')->where('account_id', $consumed)->exists()) {
            DB::table('accounts')->where('id', $consumed)->delete();
        }
    }

    /**
     * The consumed-share head, by slug first; else planted on the first free
     * code from 5160 under 5000 Cost of Sales. ⚠ Never adopts a head somebody
     * else made on that code: Joy's 5150 is his, whatever its number.
     */
    private function plantConsumedHead(): ?int
    {
        $bySlug = DB::table('accounts')->where('system_slug', 'landed_cost_consumed')->value('id');

        if ($bySlug !== null) {
            return (int) $bySlug;
        }

        $parentId = DB::table('accounts')->where('code', '5000')->value('id');

        if ($parentId === null) {
            // No chart yet: the chart seeder plants 5160 with everything else.
            return null;
        }

        $code = null;
        foreach (range(5160, 5199) as $candidate) {
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
            'name' => 'Landed Cost on Goods Sold',
            'type' => 'expense',
            'parent_id' => $parentId,
            'is_postable' => true,
            'is_active' => true,
            'system_slug' => 'landed_cost_consumed',
            'note' => 'The share of a late landed cost (packaging that arrived after the goods) that belongs to stock already consumed or sold. Posted by Purchases > Landed costs only.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
