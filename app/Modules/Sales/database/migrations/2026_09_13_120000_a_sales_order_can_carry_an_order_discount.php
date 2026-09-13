<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Phase 2, slice 2.5b: an order can carry a discount on the whole order,
 * and an invoice records how much of it it billed.
 *
 * ⭐⭐ JOY'S 13 SEPTEMBER DECISION: a coupon is an ORDER-LEVEL discount posting to
 * 4210 Discounts Allowed, so coupon spend is its own line in the profit and
 * loss. The ledger already posts an invoice's whole-invoice discount there, so
 * nothing new is needed in Accounting; what was missing is the order holding the
 * agreement and the invoice carrying it.
 *
 * ⚠ `coupon_code` is a WORD on the order, like `bundle_label` on a line. Sales
 * does not learn what a store coupon is, and a coupon later deleted or renamed
 * cannot change what an old order says was agreed.
 *
 * ⚠⚠ `sales_invoice_order_discounts` exists for the same reason `cost_released`
 * does. An order can be invoiced in parts, and rounded shares do not sum back to
 * the whole - so the invoice that finishes an order bills whatever is still
 * unbilled, which is only exact if every earlier share was recorded.
 *
 * ⚠ Additive: on production `sales_orders` and `sales_invoices` hold zero rows
 * (measured 13 Sep). The new table's unique index leads on `sales_invoice_id`,
 * which a foreign key would use - so down() drops the TABLE, never the index on
 * its own, and there is nothing for MySQL error 1553 to refuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('order_discount', 15, 4)->default(0)->after('delivery_charge');
            $table->string('coupon_code', 40)->nullable()->after('order_discount');

            // ⚠ Usage of a coupon is COUNTED from orders (Joy: never a stored
            // counter), and this is the question it asks.
            // ⚠⚠ Led by `coupon_code`, NOT `store_id`. An index leading on a
            // foreign-key column can become the index MySQL uses for that key,
            // and dropping it in down() then fails with error 1553 - exactly
            // how slice 2.4's rollback broke on MySQL while SQLite said nothing.
            $table->index(['coupon_code', 'store_id'], 'sales_orders_coupon_store_index');
        });

        Schema::create('sales_invoice_order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 4);
            $table->timestamps();

            $table->unique(['sales_invoice_id', 'sales_order_id'], 'invoice_order_discount_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_invoice_order_discounts');

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropIndex('sales_orders_coupon_store_index');
            $table->dropColumn(['order_discount', 'coupon_code']);
        });
    }
};
