<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ THE PRICE GROUPS (Joy, 25 Oct 2026, Q13 = A, R106): Retail (MRP),
 * Distributor Price (DP), Trade Price (TP), Corporate, Owner (at cost).
 *
 * - "Retail shop" (code SHOP) is renamed Trade Price (TP): shops and retail
 *   sellers buy at it. "Distributor" becomes Distributor Price (DP). Codes
 *   never change (they are the identity key the price file and the resolver
 *   read), only the names people see.
 * - DEALER is removed in favour of Distributor:
 *     1. customers placed in the Dealer group move to the Distributor group;
 *     2. web stores pointing at Dealer move to Distributor;
 *     3. Dealer PRICE ROWS move to Distributor ONLY for products with no
 *        Distributor price at all (moving them beside existing Distributor
 *        rows would silently change what distributors pay). Rows left behind
 *        are NOT deleted: they stay on the Dealer group, which is switched
 *        off, so Joy can read them and decide;
 *     4. the Dealer group is switched off (is_active = false), never deleted.
 *   The counts of each go to the application log ("price groups 25 Oct").
 * - A customer whose TYPE is Dealer and who has no group was priced in DEALER
 *   by default; CustomerType::defaultPriceGroupCode() now sends them to
 *   DISTRIBUTOR. Their TYPE is left alone on purpose: type Distributor means
 *   "pays in advance" (CheckCustomerCredit), which is Joy's call per customer.
 *
 * ⚠ down() puts the names back and switches Dealer on again; moved rows stay
 * where they are (which ones moved is in the log).
 */
return new class extends Migration
{
    private const NAMES = [
        'RETAIL' => 'Retail (MRP)',
        'DISTRIBUTOR' => 'Distributor Price (DP)',
        'SHOP' => 'Trade Price (TP)',
        'CORPORATE' => 'Corporate',
        'OWNER' => 'Owner (at cost)',
    ];

    private const OLD_NAMES = [
        'RETAIL' => 'Retail',
        'DISTRIBUTOR' => 'Distributor',
        'SHOP' => 'Retail shop',
        'CORPORATE' => 'Corporate',
        'OWNER' => 'Owner (at cost)',
    ];

    public function up(): void
    {
        foreach (self::NAMES as $code => $name) {
            DB::table('price_groups')->where('code', $code)->update(['name' => $name, 'updated_at' => now()]);
        }

        foreach (DB::table('price_groups')->where('code', 'DEALER')->get(['id', 'business_id']) as $dealer) {
            $distributorId = DB::table('price_groups')
                ->where('business_id', $dealer->business_id)
                ->where('code', 'DISTRIBUTOR')
                ->value('id');

            $counts = ['business_id' => $dealer->business_id, 'customers' => 0, 'stores' => 0, 'price_rows_moved' => 0, 'price_rows_left_on_dealer' => 0];

            if ($distributorId !== null) {
                $counts['customers'] = DB::table('customers')->where('price_group_id', $dealer->id)
                    ->update(['price_group_id' => $distributorId, 'updated_at' => now()]);

                if (Schema::hasTable('stores') && Schema::hasColumn('stores', 'price_group_id')) {
                    $counts['stores'] = DB::table('stores')->where('price_group_id', $dealer->id)
                        ->update(['price_group_id' => $distributorId]);
                }

                $productsWithDistributorPrice = DB::table('product_prices')
                    ->where('price_group_id', $distributorId)
                    ->distinct()
                    ->pluck('product_id')
                    ->all();

                $movable = DB::table('product_prices')
                    ->where('price_group_id', $dealer->id)
                    ->whereNotIn('product_id', $productsWithDistributorPrice === [] ? [0] : $productsWithDistributorPrice);

                $counts['price_rows_moved'] = (clone $movable)->update(['price_group_id' => $distributorId, 'updated_at' => now()]);
                $counts['price_rows_left_on_dealer'] = DB::table('product_prices')->where('price_group_id', $dealer->id)->count();
            }

            DB::table('price_groups')->where('id', $dealer->id)->update(['is_active' => false, 'updated_at' => now()]);

            Log::info('price groups 25 Oct: Dealer removed in favour of Distributor', $counts);
        }
    }

    public function down(): void
    {
        foreach (self::OLD_NAMES as $code => $name) {
            DB::table('price_groups')->where('code', $code)->update(['name' => $name]);
        }

        DB::table('price_groups')->where('code', 'DEALER')->update(['is_active' => true]);
    }
};
