<?php

namespace App\Modules\Crm\database\seeders;

use App\Modules\Crm\Enums\CrmLookupList;
use App\Modules\Crm\Models\CrmLookup;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use Illuminate\Database\Seeder;

/**
 * The starting entries of the CRM's editable lists (CRM lead slice 2c).
 *
 * ⭐ Joy's own words, not ours: shop types from his answer 4 and supplier types
 * from his answer 14 (CRM-LEAD-PROFILES-DESIGN-2026-09-14.md). Competitor brands
 * are deliberately NOT seeded - which brands a shop stocks against Bor de Guna
 * is his list to type in CRM Settings.
 *
 * ⚠⚠ A LIST IS SEEDED ONLY WHILE IT IS EMPTY for that business. These are
 * starting values somebody is expected to edit. Seeding by name would re-create
 * "Grocery / mudi dokan" the next deploy after he renamed it "Mudi dokan", and
 * re-activate every entry he retired. Once a list has one entry of any kind, the
 * list is his and this seeder never touches it again.
 */
class CrmLookupSeeder extends Seeder
{
    private const STARTING = [
        'shop_type' => [
            'Grocery / mudi dokan',
            'Super shop / departmental',
            'Wholesale / arot',
            'Pharmacy / health / organic shop',
            'Food / restaurant',
        ],
        'supplier_type' => [
            'Producer / primary source',
            'Trader / wholesaler / arot',
            'Manufacturer / processor',
            'Packaging supplier',
        ],
    ];

    public function run(): void
    {
        foreach (Business::all() as $business) {
            TenantScope::enter($business->id);

            foreach (self::STARTING as $list => $names) {
                $exists = CrmLookup::query()->ofList(CrmLookupList::from($list))->exists();

                if ($exists) {
                    continue;
                }

                foreach ($names as $order => $name) {
                    CrmLookup::create([
                        'business_id' => $business->id,
                        'list' => $list,
                        'name' => $name,
                        'sort_order' => $order,
                    ]);
                }
            }
        }

        TenantScope::leave();
    }
}
