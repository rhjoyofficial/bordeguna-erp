<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ WHICH PRODUCTS MUST CARRY A BATCH AND AN EXPIRY DATE (Joy, 26 Sep 2026,
 * Q8 and Q9): "add a 'tracks expiry' switch per product. Required where it's
 * on, optional elsewhere" and "warn, don't refuse" below 90 days of shelf life,
 * "configurable per product, with 90 days as the default".
 *
 * Until now every goods receipt took batch and dates as optional, because
 * whether a food product MUST carry them was an owner question. Now it is a
 * fact on the product: honey and ghee track expiry, jars and labels do not.
 *
 * - products.tracks_expiry: off by default. Nothing changes for any existing
 *   product until Joy switches it on in the product dialog.
 * - products.shelf_life_warning_days: null means the default, 90. The receipt
 *   WARNS when a batch has fewer days left, never refuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tracks_expiry')->default(false)->after('lead_time_days');
            $table->unsignedSmallInteger('shelf_life_warning_days')->nullable()->after('tracks_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tracks_expiry', 'shelf_life_warning_days']);
        });
    }
};
