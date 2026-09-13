<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A website order keeps the delivery window it was promised.
 *
 * ⚠ FROZEN ON THE ORDER, like the price and the delivery charge, for the same
 * reason: a shop that changes its promise next week must not rewrite what it
 * told a customer this week. The promise starts at CONFIRMATION (D24), so
 * `expected_date` is written when the order is approved - confirmation plus the
 * upper bound (Joy, 13 September) - and these columns are what it is computed
 * from.
 *
 * ⚠ Nullable: an order typed into the ERP promised nothing of the kind, which is
 * every order that exists. No index, no foreign key, so the rollback has nothing
 * for MySQL to refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('delivery_promise_min_hours')->nullable()->after('delivery_charge');
            $table->unsignedSmallInteger('delivery_promise_max_hours')->nullable()->after('delivery_promise_min_hours');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_promise_min_hours', 'delivery_promise_max_hours']);
        });
    }
};
