<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A store's price group becomes a price group.
 *
 * ⛔⛔ THE DEFECT THIS CORRECTS, AND IT CONTRADICTED A RULE THIS PROJECT HAD
 * ALREADY WRITTEN DOWN IN CAPITALS. Slice 1.1 gave `stores` a `price_group`
 * string cast to `CustomerType` - the enum of walk-in, retail, dealer,
 * distributor, corporate. But `PriceGroup` says, on Joy's explicit instruction
 * of 10 August 2026: "A GROUP IS NOT A CUSTOMER TYPE... Three concepts, three
 * columns, and the day one is derived from another is the day a report is
 * silently wrong."
 *
 * ⚠⚠ IT WAS NOT MERELY UNTIDY, IT COULD NOT WORK. Prices are resolved through
 * `product_prices.price_group_id`, a foreign key to `price_groups`. A customer
 * type string joins to nothing, so a store set to "dealer" would have been
 * quoted the default price and nobody would have seen an error - the shop would
 * simply have charged the wrong amount. Slice 1.7 is where that price is first
 * read, which is why it is corrected here rather than left.
 *
 * ⚠ NULLABLE, AND NULL MEANS RETAIL. That is not a new rule: `customers`
 * already works this way and the mapping lives in ONE place, the selling-price
 * resolver. Adding a second opinion about what "no group" means is how the two
 * come to disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('price_group_id')->nullable()->after('order_prefix')
                ->constrained('price_groups')->nullOnDelete();
        });

        // The old value was a CustomerType; its uppercase form is the price
        // group's code where one corresponds. `walk_in` corresponds to no group
        // at all and correctly becomes null, which is RETAIL.
        foreach (DB::table('stores')->select('id', 'business_id', 'price_group')->get() as $store) {
            $code = strtoupper((string) $store->price_group);

            $groupId = DB::table('price_groups')
                ->where('business_id', $store->business_id)
                ->where('code', $code)
                ->value('id');

            if ($groupId !== null) {
                DB::table('stores')->where('id', $store->id)->update(['price_group_id' => $groupId]);
            }
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('price_group');
        });
    }

    /**
     * ⚠ NOT PERFECTLY REVERSIBLE, and measured rather than assumed: a store that
     * held `walk_in` comes back as `retail`, because walk-in is a customer type
     * and no price group corresponds to it. That is the correct answer rather
     * than a loss - the value was meaningless in this column to begin with,
     * which is the whole reason for this migration.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('price_group', 20)->default('retail')->after('order_prefix');
        });

        foreach (DB::table('stores')->select('id', 'price_group_id')->get() as $store) {
            $code = $store->price_group_id === null
                ? 'RETAIL'
                : (string) DB::table('price_groups')->where('id', $store->price_group_id)->value('code');

            DB::table('stores')->where('id', $store->id)->update(['price_group' => strtolower($code)]);
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropForeign(['price_group_id']);
            $table->dropColumn('price_group_id');
        });
    }
};
