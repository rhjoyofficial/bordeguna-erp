<?php

namespace App\Support\Dashboards;

use App\Modules\Sales\Support\OrderBrand;

/**
 * The dashboard's Brand switcher (Joy, 11 Oct 2026): All brands, LaLaDia,
 * Bionic-Garden. Kept in the URL as `brand`, beside the range.
 *
 * ⚠ Only a brand of the CURRENT business is taken; anything else (another
 * business's brand, "none", text) reads as all brands. Authorisation is not
 * touched: the brand narrows figures a person may already see.
 */
final class DashboardBrand
{
    public static function fromQuery(mixed $value, int $businessId): ?int
    {
        $filter = OrderBrand::filter($value, $businessId);

        return is_int($filter) ? $filter : null;
    }

    /** @return list<array{id: int, name: string}> */
    public static function options(int $businessId): array
    {
        return OrderBrand::options($businessId)
            ->map(fn (array $b) => ['id' => $b['id'], 'name' => $b['name']])
            ->values()
            ->all();
    }
}
