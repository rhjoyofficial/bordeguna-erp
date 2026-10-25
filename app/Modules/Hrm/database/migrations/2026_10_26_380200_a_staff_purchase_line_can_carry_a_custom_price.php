<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ Joy's answer 6, first part (25 Oct 2026): "Not-for-sale items cannot be
 * sold from POS or anywhere, BUT an employee may buy one at a custom price
 * (staff purchase)."
 *
 * A line of a staff purchase may now be priced 'custom' (priced_at): the
 * Owner or a Manager types the price and the reason, kept on the line and in
 * the request's history. Only such a line lets a product whose Can be sold
 * switch is off through the counter (NotForSale::refuseUnlessAllowed).
 *
 *   staff_purchase_lines.custom_reason   why this price
 *   staff_purchase_lines.custom_by       who typed it
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_purchase_lines', function (Blueprint $table) {
            $table->string('custom_reason', 500)->nullable()->after('priced_at');
            $table->foreignId('custom_by')->nullable()->after('custom_reason')
                ->constrained('users', 'id', 'staff_purchase_lines_custom_by_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('staff_purchase_lines', function (Blueprint $table) {
            $table->dropForeign('staff_purchase_lines_custom_by_fk');
            $table->dropColumn(['custom_reason', 'custom_by']);
        });
    }
};
