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
    ];

    public function run(): void
    {
        foreach (Business::withoutGlobalScopes()->pluck('id') as $businessId) {
            TenantScope::enter($businessId);

            try {
                foreach (self::GROUPS as $group) {
                    PriceGroup::firstOrCreate(
                        ['business_id' => $businessId, 'code' => $group['code']],
                        ['name' => $group['name'], 'prices_include_tax' => true, 'is_active' => true],
                    );
                }
            } finally {
                TenantScope::leave();
            }
        }
    }
}
