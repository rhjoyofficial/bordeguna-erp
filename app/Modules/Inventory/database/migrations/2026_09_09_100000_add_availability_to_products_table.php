<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Can this product be sold when there is none of it, and if so, by when?
 *
 * ⭐⭐ THIS LIVES ON THE PRODUCT MASTER, NOT ON THE WEBSITE. ADR-004 says a
 * per-product override exists for the cases a channel's default gets wrong, and
 * `store_products` says in capitals that no availability column may live there.
 * Both are right, and the resolution is that this is not a website fact at all:
 * "this cannot be made to order" is true of the product at the counter, on the
 * phone and on the web alike. Put it on the join and each website would hold its
 * own opinion about whether the factory can make something.
 *
 * ⚠ Both columns are NULLABLE and null is the ordinary case, so this migration
 * changes the behaviour of nothing that exists today. A null override means
 * "follow the channel's policy", which is what every product does now. A null
 * lead time means the shop promises no date, and a page with nothing to promise
 * must say nothing rather than imply next-day delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // ⚠ Deliberately NOT an enum column. It carries one value today
            // (`strict`) and the set it belongs to is AvailabilityPolicy, which
            // is versioned in PHP where it can be reasoned about. A database
            // enum would have to be migrated to add a case.
            $table->string('availability_override', 20)->nullable()->after('is_active');

            // The promise, in days, for a made-to-order sale. ⚠ Who maintains
            // this is open question Q7. Nullable is what lets the slice ship
            // without the answer: null promises nothing.
            $table->unsignedSmallInteger('lead_time_days')->nullable()->after('availability_override');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['availability_override', 'lead_time_days']);
        });
    }
};
