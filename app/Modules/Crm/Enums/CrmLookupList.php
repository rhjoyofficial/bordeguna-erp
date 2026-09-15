<?php

namespace App\Modules\Crm\Enums;

/**
 * The editable lists a CRM lead picks from (build plan D15, 15 Sep 2026).
 *
 * ⚠ The values are what the company says, typed in CRM Settings. Shop types and
 * supplier types start from Joy's own answers (4 and 14); competitor brands
 * start EMPTY, because which brands matter is his to list and never ours to
 * invent.
 */
enum CrmLookupList: string
{
    case ShopType = 'shop_type';

    case SupplierType = 'supplier_type';

    case CompetitorBrand = 'competitor_brand';

    public function label(): string
    {
        return match ($this) {
            self::ShopType => 'Shop types',
            self::SupplierType => 'Supplier types',
            self::CompetitorBrand => 'Competitor brands',
        };
    }
}
