<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Purchase redesign PR2 (18 Oct 2026): a product may say how long it keeps
 * from the day it is made (Tally's "use expiry dates"). A new batch's expiry
 * then defaults to its manufacture date plus these days, on a goods receipt,
 * Buy and receive and a production run (BatchNumbers::label).
 *
 * Null for every existing product: nothing changes until Joy types a shelf
 * life in the product popup. Schema only, no data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('shelf_life_warning_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('shelf_life_days');
        });
    }
};
