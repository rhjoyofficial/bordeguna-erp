<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Initial plan B6 (Joy, 24 Oct 2026; Q14 = A, R72): every stock loss
 * reason has its OWN expense head, so the P&L says how much expired, broke,
 * spoiled, went missing or came back damaged, instead of one 5200 total.
 *
 * The person picks a REASON, never an account (V16): the reason gives the
 * adjustment type (AdjustmentCause), the type is the mapping key
 * (`inventory.adjustment.{type}`, PostInventoryAdjustmentJournal), and the
 * mapping points at the head below. An accountant can still re-point a key in
 * the mappings without a deploy.
 *
 * ⚠ The heads sit on the first FREE codes after 5200 (5201 up), as siblings
 * of 5200 under 5000 Cost of Sales, never as children of 5200: 5200 is a
 * posted head, and a head under a posted head is skipped by the statements
 * (AccountController refuses exactly that for a person). Cost of Sales still
 * totals them all, which is what "under 5200 so totals stay" asked for.
 *
 * ⚠ Plain DB, no models: the B6 migration plants the same heads on the live
 * chart, and a migration must not depend on a model's later shape.
 */
final class StockLossHeads
{
    /**
     * slug => [head name, adjustment type it serves], in the order the codes
     * are handed out.
     *
     * @var array<string, array{0: string, 1: InventoryAdjustmentType}>
     */
    public const HEADS = [
        'inventory_loss_expired' => ['Stock Loss - Expired', InventoryAdjustmentType::Expired],
        'inventory_loss_damaged' => ['Stock Loss - Broken/Damaged', InventoryAdjustmentType::Damaged],
        'inventory_loss_spoiled' => ['Stock Loss - Spoiled', InventoryAdjustmentType::Spoiled],
        'inventory_loss_shrinkage' => ['Stock Loss - Shrinkage/Lost', InventoryAdjustmentType::LostOrStolen],
        'inventory_loss_damaged_returns' => ['Stock Loss - Damaged Returns', InventoryAdjustmentType::DamagedReturn],
    ];

    /** The mapping key a loss type posts through. */
    public static function keyFor(InventoryAdjustmentType $type): string
    {
        return "inventory.adjustment.{$type->value}";
    }

    /**
     * Finds or plants every loss head. Returns slug => account id; empty when
     * the chart has no 5200 yet (the chart seeder runs first on a fresh
     * install, and the mapping seeder calls this again after it).
     *
     * @return array<string, int>
     */
    public static function plant(): array
    {
        $writeOff = DB::table('accounts')->where('code', '5200')->first(['id', 'parent_id']);

        if ($writeOff === null) {
            return [];
        }

        $ids = [];

        foreach (self::HEADS as $slug => [$name]) {
            $existing = DB::table('accounts')->where('system_slug', $slug)->value('id');

            if ($existing !== null) {
                $ids[$slug] = (int) $existing;

                continue;
            }

            $code = null;
            foreach (range(5201, 5299) as $candidate) {
                if (! DB::table('accounts')->where('code', (string) $candidate)->exists()) {
                    $code = (string) $candidate;
                    break;
                }
            }

            if ($code === null) {
                // No free code left: the type falls back to 5200 through
                // inventory.adjustment.default, exactly as before B6.
                continue;
            }

            $ids[$slug] = (int) DB::table('accounts')->insertGetId([
                'code' => $code,
                'name' => $name,
                'type' => 'expense',
                'parent_id' => $writeOff->parent_id,
                'is_postable' => true,
                'is_active' => true,
                'system_slug' => $slug,
                'note' => 'A stock loss reason (Joy, 24 Oct 2026). Posted by stock write-offs only: the person picks the reason, the reason picks this head.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }
}
