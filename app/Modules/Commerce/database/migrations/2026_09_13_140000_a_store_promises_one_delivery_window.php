<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A store promises ONE delivery window, in hours after an order is confirmed.
 *
 * ⭐⭐ D24 (Joy, 7 September), confirmed again on 13 September: lead time is one
 * shop-wide promise - the customer is told 24 to 72 hours after order
 * confirmation - with no product exceptions today. Slices 2.1 and 2.4 were built
 * around a per-product "ready in N days" instead, which contradicted that answer;
 * this is the correction. `products.lead_time_days` stays in the model, unused,
 * for the day an exception is genuinely needed, as D24 itself said.
 *
 * ⚠ Hours, not days, because that is how the promise is spoken, and a minimum as
 * well as a maximum because the promise is a range. Defaulted to D24's own
 * figures, so every store keeps a real answer rather than none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_promise_min_hours')->default(24)->after('availability_policy');
            $table->unsignedSmallInteger('delivery_promise_max_hours')->default(72)->after('delivery_promise_min_hours');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['delivery_promise_min_hours', 'delivery_promise_max_hours']);
        });
    }
};
