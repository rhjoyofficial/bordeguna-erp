<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales orders slice 1: the recorded promise.
     *
     * ⚠⚠ A SALES ORDER MOVES NO STOCK AND POSTS NO JOURNAL, EVER. It is the
     * moment the price is agreed and the commitment made; stock leaves at
     * delivery and revenue is earned at invoicing, exactly as before. Nothing
     * in Accounting learns that orders exist, there is no mapping key, and the
     * trial balance cannot be affected by construction.
     * See SALES-ORDER-ARCHITECTURE.md §2 and §7.
     *
     * The chain mirror of Purchasing, approved by Joy 2026-08-11:
     *   PO          -> goods receipt -> supplier bill -> payment
     *   sales order -> delivery      -> sales invoice -> receipt
     *
     * ⚠ Every index is named explicitly - Laravel's generated names have
     * already hit MySQL's 64-character identifier ceiling once (the 3 Aug
     * payment-allocations failure).
     */
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // ⚠ NULLABLE, unlike a PO's warehouse, and unlike a delivery's. A
            // purchase order commits a destination; a sales order commits the
            // CUSTOMER a quantity, and which warehouse fulfils it is decided at
            // each delivery. When set it is a default for the delivery screen,
            // not a commitment. SALES-ORDER-ARCHITECTURE.md §4.1.
            $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();

            // ⚠ OURS (SO-FY2026-0001), the Sales numbering convention.
            $table->string('order_number');

            // ⚠ A date, not a datetime: an order is a promise made on a day,
            // not an instant that must agree with stock movements - there are
            // no stock movements.
            $table->date('ordered_at');
            $table->date('expected_date')->nullable();

            $table->string('status')->default('open');

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'order_number'], 'sales_orders_number_unique');
            $table->index(['business_id', 'status'], 'sales_orders_business_status_index');
            $table->index(['business_id', 'customer_id'], 'sales_orders_business_customer_index');
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->decimal('quantity_ordered', 15, 4);

            // ⚠ The ordered/delivered pair, exactly as purchase_order_lines
            // carries ordered/received. Ordered exceeding delivered is not an
            // error - it is an open order, which is what the order book reports.
            $table->decimal('quantity_delivered', 15, 4)->default(0);

            // ⚠⚠ THE AGREED PRICE, COPIED AT ORDER TIME from the resolver (or a
            // manual override), never referenced. Same reasoning as unit_cost
            // on a stock movement: the document shows what was agreed, not what
            // the rule says today. C-SO-4 (Joy): a challan delivered in one
            // month and invoiced in the next is billed at THIS price.
            $table->decimal('unit_price', 15, 4);

            // WHY it is this price - the resolver's explanation or "manual
            // override". The counter's price-explanation habit carried onto the
            // document where a dispute will actually be settled, weeks later.
            $table->string('price_note')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'sales_order_id'], 'sales_order_lines_business_order_index');
        });

        // ⚠ Null means direct delivery, which is every delivery that exists
        // today, so this is safe on live data by construction. Sales owns both
        // tables, so a plain column is correct here - unlike the
        // delivery_line_movements case, where a cross-module boundary forced a
        // link table. Mirrors goods_receipt_lines.purchase_order_line_id.
        Schema::table('delivery_lines', function (Blueprint $table) {
            $table->foreignId('sales_order_line_id')
                ->nullable()
                ->constrained('sales_order_lines', indexName: 'delivery_lines_order_line_index')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_line_id');
        });

        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
    }
};
