<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a price row as a discount, so the Discount screen can find its own
     * rows again.
     *
     * ⚠⚠ THE RESOLVER DOES NOT READ EITHER COLUMN, AND MUST NOT. Joy asked for
     * a Discount section, and the temptation is to teach `ResolveSellingPrice`
     * about discounts. That class says outright that the day it grows an `if`
     * for a kind of pricing is the day the table stopped being the answer. A
     * discount is a ROW like any other: a lower unit price, dated, optionally
     * from a quantity up, optionally for one group. These two columns are
     * labelling for humans, nothing more - strip them and every price still
     * resolves identically.
     *
     * ⚠ `is_discount` is therefore descriptive, not behavioural. It answers
     * "show me the discounts I have set", never "should this win".
     *
     * ℹ Default false, so all 52 existing rows read as ordinary prices, which
     * is what they are.
     */
    public function up(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->boolean('is_discount')->default(false)->after('unit_price');
            $table->string('discount_label')->nullable()->after('is_discount');
        });
    }

    public function down(): void
    {
        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropColumn(['is_discount', 'discount_label']);
        });
    }
};
