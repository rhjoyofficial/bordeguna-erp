<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's addition after the practice run (17 Oct 2026):
 *
 * - sales_orders.special_instructions: "call before delivery", "deliver after
 *   5 pm". Shown on the order, pick list and challan, and offered as the
 *   courier's "Note for the rider". Separate from `note`, which is the
 *   office's own remark and never travels with the parcel.
 * - sales_order_lines.description: "extra sealed", "gift wrap", per line.
 *   Carried to the pick list, challan, invoice and the courier's
 *   "What is inside".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('special_instructions', 500)->nullable()->after('note');
        });

        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('bundle_label');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn('special_instructions');
        });
    }
};
