<?php

namespace App\Modules\Sales\database\seeders;

use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Database\Seeder;

/**
 * The four official price groups, fixed by Joy on 2026-08-10 (D-PRC-2):
 * RETAIL (the default), DEALER, DISTRIBUTOR, CORPORATE.
 *
 * ⚠ Seeded for EVERY business, not just Bor de Guna - pricing is generic
 * infrastructure, per the standing constraint that Sales stays generic across
 * businesses. A future group is a row added by hand; this seeder does not need
 * changing for it.
 *
 * ⚠⚠ IDEMPOTENT PER GROUP, not all-or-nothing - each row is matched on
 * (business_id, code) and created only if missing, so re-running is safe and
 * adding a group later actually adds it. Matched on `code`, never `name`, so
 * renaming "Dealer" to "Authorised Dealer" cannot spawn a second group.
 *
 * ⚠ Runs INSIDE each tenant: PriceGroup is business-scoped and BusinessScope
 * fails closed, so a seeder that did not enter the tenant would read an empty
 * table and write rows into no business at all.
 */
class PriceGroupSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string}>
     */
    private const GROUPS = [
        ['code' => PriceGroup::DEFAULT_CODE, 'name' => 'Retail'],
        ['code' => 'DEALER', 'name' => 'Dealer'],
        ['code' => 'DISTRIBUTOR', 'name' => 'Distributor'],
        ['code' => 'CORPORATE', 'name' => 'Corporate'],
        // ⭐ 26 Sep 2026: shops are priced apart from consumers and never at MRP.
        ['code' => PriceGroup::SHOP_CODE, 'name' => 'Retail shop'],
        // ⭐ D-B (18 Oct 2026): the proprietor at the FIFO batch cost (basis cost).
        ['code' => PriceGroup::OWNER_CODE, 'name' => 'Owner (at cost)'],
    ];

    public function run(): void
    {
        foreach (Business::withoutGlobalScopes()->pluck('id') as $businessId) {
            TenantScope::enter($businessId);

            try {
                foreach (self::GROUPS as $group) {
                    PriceGroup::firstOrCreate(
                        ['business_id' => $businessId, 'code' => $group['code']],
                        [
                            'name' => $group['name'],
                            'prices_include_tax' => true,
                            // Only RETAIL pays MRP when a product has no price for it (21 Sep 2026).
                            'falls_back_to_mrp' => $group['code'] === PriceGroup::DEFAULT_CODE,
                            // ⚠ Only on CREATE (firstOrCreate): an OWNER group made by
                            // hand is never adopted or changed.
                            'price_basis' => $group['code'] === PriceGroup::OWNER_CODE ? PriceGroup::BASIS_COST : PriceGroup::BASIS_LIST,
                            'is_active' => true,
                        ],
                    );
                }
            } finally {
                TenantScope::leave();
            }
        }
    }
}
