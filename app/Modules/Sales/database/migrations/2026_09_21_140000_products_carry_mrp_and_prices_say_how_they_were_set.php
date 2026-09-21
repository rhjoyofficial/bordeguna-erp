<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠⚠ PRICES ARE ENTERED, NEVER GENERATED, AND NO GROUP FALLS BACK TO ANOTHER
 * (Joy, 21 Sep 2026): "keep them null or zero so I can not sell them until I add
 * the price for the products and to whom I want to sell". The one default he
 * named: a retail shop or a consumer pays MRP when nothing else is set.
 *
 * - products.mrp: the printed maximum retail price, on the product itself, so
 *   "% off MRP" has a base and a consumer has a price before anybody types one.
 * - product_prices.price_method / percent_off / basis_mrp: how a price was set
 *   (fixed, or a percentage off MRP) and the MRP it was worked out from. The
 *   price itself stays the stored taka figure (settled with ChatGPT the same
 *   day): an MRP change never moves a price on its own, and a sale snapshots
 *   the price it used, so an accepted order never drifts.
 * - price_groups.falls_back_to_mrp: which groups pay MRP when they have no
 *   price of their own. RETAIL does; DEALER, DISTRIBUTOR and CORPORATE do not,
 *   so a dealer with no dealer price is refused instead of quietly charged a
 *   retail one. A group the owner adds later chooses for itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('mrp', 15, 4)->nullable()->after('name');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->string('price_method', 20)->default('fixed')->after('unit_price');
            $table->decimal('percent_off', 7, 4)->nullable()->after('price_method');
            $table->decimal('basis_mrp', 15, 4)->nullable()->after('percent_off');
        });

        Schema::table('price_groups', function (Blueprint $table) {
            $table->boolean('falls_back_to_mrp')->default(false)->after('prices_include_tax');
        });

        DB::table('price_groups')->where('code', 'RETAIL')->update(['falls_back_to_mrp' => true]);
    }

    public function down(): void
    {
        Schema::table('price_groups', function (Blueprint $table) {
            $table->dropColumn('falls_back_to_mrp');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropColumn(['price_method', 'percent_off', 'basis_mrp']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('mrp');
        });
    }
};
