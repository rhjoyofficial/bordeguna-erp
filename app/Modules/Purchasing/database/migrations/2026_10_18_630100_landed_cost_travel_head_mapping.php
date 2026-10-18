<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The expense head a "Travel / market visit" landed-cost line posts to
 * (18 Oct 2026, decision D-A), for a business that already exists; a fresh
 * install gets the same from InventoryAccountMappingSeeder (6173 Market Visit).
 *
 * ⚠⚠ An existing head is used, never renamed, re-tagged or adopted by slug:
 * the live chart has 6173 Market Visit and 6019 Travel and TA (checked on the
 * 17 Oct copy of production). Looked up by NAME in this order, postable and
 * active expense heads only. Only when no suitable head exists is one planted,
 * on the first free code from 6020 under 6000 Operating Expenses.
 *
 * Mapped per business that has inventory posting on (inventory.stock_on_hand
 * mapped), and never re-pointed when a mapping is already there. Idempotent.
 */
return new class extends Migration
{
    public const KEY = 'purchasing.landed_cost.travel';

    public const NOTE = 'Seeded default (purchase cost types, 18 Oct 2026)';

    public const PLANTED_SLUG = 'landed_cost_travel';

    /** In order of preference: the purchase-trip head first, then general travel. */
    public const NAMES = ['Market Visit', 'Travel and TA', 'Inter-district Travel', 'Business Meeting Travel', 'Conveyance'];

    public function up(): void
    {
        $headId = $this->existingHead() ?? $this->plantHead();

        if ($headId === null) {
            // No chart yet: the chart and mapping seeders cover a fresh install.
            return;
        }

        $businessIds = DB::table('account_mappings')->where('key', 'inventory.stock_on_hand')->distinct()->pluck('business_id');

        foreach ($businessIds as $businessId) {
            if (DB::table('account_mappings')->where('business_id', $businessId)->where('key', self::KEY)->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'business_id' => $businessId,
                'key' => self::KEY,
                'account_id' => $headId,
                'effective_from' => '2000-01-01',
                'effective_to' => null,
                'note' => self::NOTE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('account_mappings')->where('key', self::KEY)->where('note', self::NOTE)->delete();

        $planted = DB::table('accounts')->where('system_slug', self::PLANTED_SLUG)->value('id');

        if ($planted !== null && ! DB::table('journal_lines')->where('account_id', $planted)->exists()
            && ! DB::table('account_mappings')->where('account_id', $planted)->exists()) {
            DB::table('accounts')->where('id', $planted)->delete();
        }
    }

    private function existingHead(): ?int
    {
        $usable = fn () => DB::table('accounts')->where('type', 'expense')->where('is_postable', true)->where('is_active', true);

        $planted = $usable()->where('system_slug', self::PLANTED_SLUG)->value('id');

        if ($planted !== null) {
            return (int) $planted;
        }

        foreach (self::NAMES as $name) {
            $id = $usable()->where('name', $name)->orderBy('code')->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $any = $usable()->whereRaw('LOWER(name) LIKE ?', ['%travel%'])->orderBy('code')->value('id');

        return $any === null ? null : (int) $any;
    }

    private function plantHead(): ?int
    {
        $parentId = DB::table('accounts')->where('code', '6000')->value('id');

        if ($parentId === null) {
            return null;
        }

        foreach (range(6020, 6049) as $candidate) {
            if (DB::table('accounts')->where('code', (string) $candidate)->exists()) {
                continue;
            }

            return (int) DB::table('accounts')->insertGetId([
                'code' => (string) $candidate,
                'name' => 'Travel and Market Visit',
                'type' => 'expense',
                'parent_id' => $parentId,
                'is_postable' => true,
                'is_active' => true,
                'system_slug' => self::PLANTED_SLUG,
                'note' => 'Staff travel and market visits for purchasing. Planted 18 Oct 2026 because the chart had no travel head: an expense, never stock cost, unless a landed-cost line is overridden with a reason.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return null;
    }
};
