<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Phase 2, slice 2.5a: an order line can record a discount that was
 * agreed when the order was taken.
 *
 * ⚠⚠ UNTIL NOW A SALES ORDER COULD NOT HOLD A DISCOUNT OF ANY KIND, and the
 * invoice's discount fields were typed by hand and never read from the order.
 * So a bundle sold on a shop at less than its parts would have been quoted at
 * the bundle price and billed at the full price of every jar in it - the shop
 * showing one number and the invoice another, which is the defect slice 2.4
 * was written to make impossible.
 *
 * ⭐ A LINE DISCOUNT, NOT AN ORDER DISCOUNT, and that is Joy's 13 September
 * decision for combos: a bundle's saving is shared across its parts in
 * proportion to their prices, so each product's revenue is stated net of its
 * share and returning one jar from a bundle refunds that jar's share. A coupon
 * is the other animal - an order-level discount posting to 4210 Discounts
 * Allowed - and arrives separately in 2.5b.
 *
 * ⚠ `bundle_label` is a WORD, not a key into Commerce. Sales must not learn
 * what a store combo is; it records that these lines were sold together under
 * a name, which is all an order screen, a picker or a dispute needs.
 *
 * ⚠ Both columns are additive: a default of zero is exactly what every existing
 * line already meant, and on production `sales_order_lines` holds zero rows
 * (measured 13 Sep). Neither carries an index or a foreign key, so the rollback
 * has nothing for MySQL to refuse (error 1553 bit slice 2.4's).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            // ⚠ Four places, matching `sales_invoice_lines.line_discount`, so
            // the figure the invoice bills can be the figure the order agreed
            // without a cast in between deciding a paisa.
            $table->decimal('line_discount', 15, 4)->default(0)->after('unit_price');

            $table->string('bundle_label', 150)->nullable()->after('price_note');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn(['line_discount', 'bundle_label']);
        });
    }
};
