<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SSLCommerz slice P1: money a customer pays for an order before any invoice
     * exists (Joy, 13 September).
     *
     * ⭐⭐ THE MONEY IS AN ORDINARY CUSTOMER RECEIPT, held as credit on the
     * customer's account and linked to the order it was paid for. When the order
     * is invoiced the receipt is allocated to that invoice - an allocation row,
     * no journal, because both sides already sit in 1140. The ageing tie-out
     * (Σinvoices − Σreceipts − Σcredits + Σrefunds) assumes every receipt credits
     * 1140, and it already treats money with no invoice behind it as credit on
     * account (C-GLA-11), so every report works unchanged.
     *
     * ⚠ A refund of money held for an order that will never be invoiced - a
     * cancelled order, a parcel that came back - has no sales return behind it.
     * So a refund now names EITHER a return OR an order. Exactly one, enforced in
     * RecordCustomerRefund.
     */
    public function up(): void
    {
        Schema::table('customer_receipts', function (Blueprint $table) {
            // ⚠ Nullable: every receipt before this, and every receipt against an
            // invoice, belongs to no order.
            $table->foreignId('sales_order_id')->nullable()->after('customer_id')->constrained()->restrictOnDelete();
        });

        // ⚠ The foreign key is dropped and put back around the change rather than
        // trusting MySQL to alter a constrained column in place.
        Schema::table('customer_refunds', function (Blueprint $table) {
            $table->dropForeign(['sales_return_id']);
        });

        Schema::table('customer_refunds', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_return_id')->nullable()->change();
            $table->foreign('sales_return_id')->references('id')->on('sales_returns')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->after('sales_return_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // ⛔ A refund of an order's money has no return to point at, and putting
        // NOT NULL back would have to delete it. Refused rather than destroyed.
        if (DB::table('customer_refunds')->whereNull('sales_return_id')->exists()) {
            throw new RuntimeException('Refunds of money held for an order exist. Rolling back would delete them.');
        }

        Schema::table('customer_refunds', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
            $table->dropForeign(['sales_return_id']);
        });

        Schema::table('customer_refunds', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_return_id')->nullable(false)->change();
            $table->foreign('sales_return_id')->references('id')->on('sales_returns')->restrictOnDelete();
        });

        Schema::table('customer_receipts', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
    }
};
