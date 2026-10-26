<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\SalesOrderAddress;
use Illuminate\Support\Collection;

/**
 * The place a customer's last delivery went to: district, thana, area, street
 * and landmark, whole, or nothing (24 Oct 2026).
 *
 * ⚠ Whole or nothing. A street prefilled with no district was refused on save
 * "We do not recognise that district", so only an address that had a district
 * is ever offered back.
 *
 * ⭐ 26 Oct 2026: moved out of SalesOrderController so the customer lookup
 * (CustomerLookupController) fills the same place the order picker does.
 */
final class CustomerSavedPlaces
{
    /**
     * @param  iterable<int, int>  $customerIds
     * @return Collection<int, array{district: string, thana: string, area: string, address_line: string, landmark: string}> keyed by customer id
     */
    public static function for(iterable $customerIds): Collection
    {
        $ids = collect($customerIds)->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $latest = SalesOrderAddress::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_addresses.sales_order_id')
            ->whereIn('sales_orders.customer_id', $ids)
            ->whereNotNull('sales_order_addresses.district')
            ->groupBy('sales_orders.customer_id')
            ->selectRaw('MAX(sales_order_addresses.id) as id')
            ->pluck('id');

        return SalesOrderAddress::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_addresses.sales_order_id')
            ->whereIn('sales_order_addresses.id', $latest)
            ->get(['sales_orders.customer_id', 'sales_order_addresses.district', 'sales_order_addresses.thana', 'sales_order_addresses.area', 'sales_order_addresses.address_line', 'sales_order_addresses.landmark'])
            ->mapWithKeys(fn ($place) => [(int) $place->customer_id => [
                'district' => (string) $place->district,
                'thana' => (string) $place->thana,
                'area' => (string) ($place->area ?? ''),
                'address_line' => (string) $place->address_line,
                'landmark' => (string) ($place->landmark ?? ''),
            ]]);
    }
}
