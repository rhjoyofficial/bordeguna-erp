<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ What an existing business needs for the Tk 10,000 sold-stock rule
 * (corrections wave 1 gaps, 24 Oct 2026); a fresh install gets the same from
 * the seeders.
 *
 *  1. The head "Stock Cost Corrections on Goods Sold" (cost of sales, slug
 *     stock_cost_correction_consumed): the cost difference a correction leaves
 *     on goods already sold or used, posted today. ⚠ Its OWN head, never 5100
 *     (the R7 reconciliation ties 5100 to invoiced cost released to the
 *     paisa), never 5160 or 5170 (late costs and discounts are read apart).
 *     Slug first; else the first free code from 5180 up under 5000 Cost of
 *     Sales. An existing head is never renamed or adopted.
 *  2. The mapping inventory.cost_correction_consumed, per business that has
 *     inventory posting on. Never re-pointed.
 *
 * Idempotent. Data only, no structure.
 */
return new class extends Migration
{
    private const NOTE = 'Seeded default (sold stock cost rule)';

    public function up(): void
    {
        $head = $this->plantHead();

        if ($head === null) {
            return;
        }

        foreach (DB::table('account_mappings')->where('key', 'inventory.stock_on_hand')->distinct()->pluck('business_id') as $businessId) {
            if (DB::table('account_mappings')->where('business_id', $businessId)->where('key', 'inventory.cost_correction_consumed')->exists()) {
                continue;
            }

            DB::table('account_mappings')->insert([
                'business_id' => $businessId,
                'key' => 'inventory.cost_correction_consumed',
                'account_id' => $head,
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
        DB::table('account_mappings')->where('key', 'inventory.cost_correction_consumed')->where('note', self::NOTE)->delete();

        $head = DB::table('accounts')->where('system_slug', 'stock_cost_correction_consumed')->where('name', 'Stock Cost Corrections on Goods Sold')->value('id');

        if ($head !== null && ! DB::table('journal_lines')->where('account_id', $head)->exists()) {
            DB::table('accounts')->where('id', $head)->delete();
        }
    }

    /** By slug first; else planted on the first free code from 5180 under 5000. */
    private function plantHead(): ?int
    {
        $bySlug = DB::table('accounts')->where('system_slug', 'stock_cost_correction_consumed')->value('id');

        if ($bySlug !== null) {
            return (int) $bySlug;
        }

        $parentId = DB::table('accounts')->where('code', '5000')->value('id');

        if ($parentId === null) {
            // No chart yet: the chart seeder plants 5180 with everything else.
            return null;
        }

        $code = null;
        foreach (range(5180, 5199) as $candidate) {
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
            'name' => 'Stock Cost Corrections on Goods Sold',
            'type' => 'expense',
            'parent_id' => $parentId,
            'is_postable' => true,
            'is_active' => true,
            'system_slug' => 'stock_cost_correction_consumed',
            'note' => 'The cost difference a correction leaves on goods already sold or used (the Tk 10,000 rule: posted today, past sales never re-costed). Posted by the corrections process only.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
