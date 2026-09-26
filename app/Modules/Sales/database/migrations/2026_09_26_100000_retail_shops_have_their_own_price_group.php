<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ A RETAIL SHOP NEVER PAYS MRP (Joy, 26 Sep 2026, answer to Q15): "for each
 * product must have retail shop prices, without it product can not be sold. not
 * MRP, will be below it but authority will add retail price".
 *
 * Until now a shop and a consumer were both `retail` and both priced in the
 * RETAIL group, which is the one group that falls back to MRP. So a shop with no
 * price of its own was quietly charged the consumer price, exactly what he has
 * forbidden. This adds the SHOP group, "Retail shop", to every business, with
 * `falls_back_to_mrp` off: a shop with no shop price is refused, like a dealer.
 * The new customer type `shop` (Enums\CustomerType) is priced in it by default.
 *
 * ⚠ Data only: no column changes. Nothing is retyped - a `retail` customer that
 * is really a shop is Joy's to retype on the Customers screen, because the code
 * cannot tell a corner shop from a person by looking at the row. PriceGroupSeeder
 * plants the same row for a business created later.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $exists = DB::table('price_groups')->where('business_id', $businessId)->where('code', 'SHOP')->exists();

            if (! $exists) {
                DB::table('price_groups')->insert([
                    'business_id' => $businessId,
                    'code' => 'SHOP',
                    'name' => 'Retail shop',
                    'prices_include_tax' => true,
                    'falls_back_to_mrp' => false,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only a SHOP group nothing refers to is removed; one with prices or
        // customers stays, because deleting it would orphan them.
        foreach (DB::table('price_groups')->where('code', 'SHOP')->get(['id']) as $group) {
            $used = DB::table('product_prices')->where('price_group_id', $group->id)->exists()
                || DB::table('customers')->where('price_group_id', $group->id)->exists();

            if (! $used) {
                DB::table('price_groups')->where('id', $group->id)->delete();
            }
        }
    }
};
