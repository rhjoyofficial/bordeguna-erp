<?php

namespace App\Modules\Inventory\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Seeder;

/**
 * Seeds the managed reason lists consumed by stock adjustments/transfers
 * (see Reason model) — replaces the old free-text reason field with a
 * per-business, per-context pick list, matching every business seeded by
 * FoundationSeeder.
 */
class InventoryReasonsSeeder extends Seeder
{
    private const CONTEXTS = [
        // ⭐ Initial plan B6 (Joy, 24 Oct 2026; Q14 = A): his five loss
        // reasons, each posting to its own head (StockLossHeads). "Damaged"
        // and "Theft or loss" were renamed on live by the B6 migration.
        'inventory.stock_adjustment' => [
            'Expired',
            'Broken/Damaged',
            'Spoiled',
            'Shrinkage/Lost',
            'Damaged returns',
            'Physical count correction',
            'Data entry error',
            'Other',
        ],
        'inventory.stock_transfer' => [
            'Warehouse rebalancing',
            'Fulfilling sales order',
            'Return to main warehouse',
            'Other',
        ],
    ];

    public function run(BusinessContext $context): void
    {
        foreach (Business::all() as $business) {
            $context->set($business->id);

            foreach (self::CONTEXTS as $reasonContext => $names) {
                foreach ($names as $name) {
                    Reason::firstOrCreate([
                        'business_id' => $business->id,
                        'context' => $reasonContext,
                        'name' => $name,
                    ]);
                }
            }
        }

        $context->forget();
    }
}
