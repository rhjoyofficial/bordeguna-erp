<?php

use App\Modules\Inventory\Support\StockLossHeads;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Initial plan B6 (Joy, 24 Oct 2026; Q14 = A, R72): the five loss reasons
 * Expired, Broken/Damaged, Spoiled, Shrinkage/Lost and Damaged returns, each
 * posting to its own head. What an existing installation needs; a fresh one
 * gets the same from the seeders.
 *
 *  1. The five heads (StockLossHeads::plant): by slug first, else the first
 *     free codes from 5201 up, beside 5200 under 5000 Cost of Sales.
 *  2. Per business with inventory posting on, the mapping of each loss type:
 *     a key still on 5200 is re-pointed. ⚠ If the business has never posted to
 *     5200, the row is simply pointed at the new head (nothing to keep apart);
 *     otherwise the old row closes yesterday and the new one starts today, so
 *     every journal already posted still resolves where it posted. A key an
 *     accountant pointed somewhere else is left alone.
 *  3. Per business, the reason list: "Damaged" becomes "Broken/Damaged",
 *     "Theft or loss" becomes "Shrinkage/Lost" (old adjustments keep their
 *     reason row, only its name changes), "Spoiled" and "Damaged returns" are
 *     added. Never renamed onto a name that already exists.
 *
 * Idempotent. Data only, no structure.
 */
return new class extends Migration
{
    private const NOTE = 'Initial plan B6: loss reason head';

    /** @var array<string, string> mapping key => head slug */
    private const KEYS = [
        'inventory.adjustment.expired' => 'inventory_loss_expired',
        'inventory.adjustment.damaged' => 'inventory_loss_damaged',
        'inventory.adjustment.spoiled' => 'inventory_loss_spoiled',
        'inventory.adjustment.lost_or_stolen' => 'inventory_loss_shrinkage',
        'inventory.adjustment.damaged_return' => 'inventory_loss_damaged_returns',
    ];

    public function up(): void
    {
        $heads = StockLossHeads::plant();
        $writeOff = DB::table('accounts')->where('system_slug', 'inventory_write_off')->value('id');
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        if ($heads !== []) {
            foreach (DB::table('account_mappings')->where('key', 'inventory.stock_on_hand')->distinct()->pluck('business_id') as $businessId) {
                $postedTo5200 = $writeOff !== null && DB::table('journal_lines')->where('business_id', $businessId)->where('account_id', $writeOff)->exists();

                foreach (self::KEYS as $key => $slug) {
                    $head = $heads[$slug] ?? null;

                    if ($head === null) {
                        continue;
                    }

                    $open = DB::table('account_mappings')->where('business_id', $businessId)->where('key', $key)
                        ->whereNull('effective_to')->orderByDesc('effective_from')->orderByDesc('id')->first();

                    if ($open === null) {
                        if (! DB::table('account_mappings')->where('business_id', $businessId)->where('key', $key)->exists()) {
                            $this->insert($businessId, $key, $head, '2000-01-01');
                        }

                        continue;
                    }

                    if ((int) $open->account_id === $head || $writeOff === null || (int) $open->account_id !== (int) $writeOff) {
                        continue;
                    }

                    if (! $postedTo5200 || $open->effective_from >= $today) {
                        DB::table('account_mappings')->where('id', $open->id)->update(['account_id' => $head, 'note' => self::NOTE, 'updated_at' => now()]);

                        continue;
                    }

                    DB::table('account_mappings')->where('id', $open->id)->update(['effective_to' => $yesterday, 'updated_at' => now()]);
                    $this->insert($businessId, $key, $head, $today);
                }
            }
        }

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $this->rename($businessId, 'Damaged', 'Broken/Damaged');
            $this->rename($businessId, 'Theft or loss', 'Shrinkage/Lost');

            foreach (['Expired', 'Broken/Damaged', 'Spoiled', 'Shrinkage/Lost', 'Damaged returns'] as $name) {
                if (! $this->reasonExists($businessId, $name)) {
                    DB::table('reasons')->insert([
                        'business_id' => $businessId,
                        'context' => 'inventory.stock_adjustment',
                        'name' => $name,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // ⚠ The reason renames stay: old and new names post the same way
        // (AdjustmentCause knows both). Mappings this migration wrote go; a
        // head nothing has posted to goes with them.
        DB::table('account_mappings')->whereIn('key', array_keys(self::KEYS))->where('note', self::NOTE)->delete();

        foreach (array_keys(StockLossHeads::HEADS) as $slug) {
            $id = DB::table('accounts')->where('system_slug', $slug)->value('id');

            if ($id !== null && ! DB::table('journal_lines')->where('account_id', $id)->exists() && ! DB::table('account_mappings')->where('account_id', $id)->exists()) {
                DB::table('accounts')->where('id', $id)->delete();
            }
        }
    }

    private function insert(int $businessId, string $key, int $head, string $from): void
    {
        DB::table('account_mappings')->insert([
            'business_id' => $businessId,
            'key' => $key,
            'account_id' => $head,
            'effective_from' => $from,
            'effective_to' => null,
            'note' => self::NOTE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rename(int $businessId, string $from, string $to): void
    {
        if ($this->reasonExists($businessId, $to)) {
            return;
        }

        DB::table('reasons')->where('business_id', $businessId)->where('context', 'inventory.stock_adjustment')
            ->where('name', $from)->update(['name' => $to, 'updated_at' => now()]);
    }

    private function reasonExists(int $businessId, string $name): bool
    {
        return DB::table('reasons')->where('business_id', $businessId)->where('context', 'inventory.stock_adjustment')->where('name', $name)->exists();
    }
};
