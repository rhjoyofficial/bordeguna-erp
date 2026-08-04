<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sales slice 2: the goods leaving.
     *
     * ⚠⚠ THE HALF OF A SALE THAT MOVES STOCK, and it is deliberately a document
     * of its own. Joy's distributor flow is "delivery challan today, invoice at
     * month end", so goods routinely leave in one month and are invoiced in the
     * next. A retail counter sale is the same document with the invoice raised
     * in the same transaction. One engine, two rhythms - see
     * SALES-PHASE1-DESIGN.md §2.
     *
     * ⛔ NO REVENUE HERE. A delivery posts Dr Goods Delivered Not Invoiced /
     * Cr Stock on Hand, at the FIFO cost of the batches actually consumed.
     * Revenue and cost of goods sold belong to the invoice.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // ⚠⚠ R4 of the architecture review. A counter sale in Uttar Adabor
            // must not silently consume Savar's stock, and there are three
            // locations. Required, never inferred.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            // ⚠ OURS, unlike a supplier bill's number which belongs to them.
            $table->string('delivery_number');

            // ⚠ A datetime, not a date, because it is the same instant the stock
            // movements carry in `occurred_at` - and the two must agree exactly
            // or the ledger and the warehouse disagree about which day it was.
            $table->dateTime('delivered_at');

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'delivery_number']);
            $table->index(['business_id', 'delivered_at']);
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('delivery_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->decimal('quantity', 15, 4);

            // ⚠ The third quantity, exactly as `purchase_order_lines` carries
            // ordered/received/invoiced. Delivered and invoiced disagreeing is
            // not an error to prevent - it is the normal state of a challan that
            // has not been billed yet, and it is what the GDNI balance reports.
            $table->decimal('quantity_invoiced', 15, 4)->default(0);

            $table->timestamps();

            $table->index(['business_id', 'delivery_id']);
        });

        /*
         * ⚠⚠ WHY THIS TABLE EXISTS, AND WHY reason_id COULD NOT BE USED.
         *
         * The design first said the movements would point back at their delivery
         * line through `stock_movements.reason_id`. That is wrong:
         * `reason_id` is a FOREIGN KEY to the `reasons` table (added
         * 2026_07_18_130003, `->constrained()->nullOnDelete()`), so writing a
         * delivery-line id into it would either violate the constraint or, far
         * worse, silently point at whichever Reason happened to share that id.
         *
         * ⚠ One delivery line consumes SEVERAL batches under FIFO and therefore
         * produces SEVERAL movements (R2 of the review), so the single
         * `stock_movement_id` column that `goods_receipt_lines` uses cannot work
         * either - a receipt creates exactly one movement, a withdrawal does not.
         *
         * ▶ The link therefore lives HERE, on the Sales side, pointing into
         * Inventory. That is the same direction Purchasing already uses and it
         * keeps the boundary rule intact: Sales never adds a column to a table
         * Inventory owns, and Inventory never learns what a delivery is.
         */
        Schema::create('delivery_line_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            // ⚠ The same movement must not be claimed by a line twice; that
            // would double the cost of goods sold released at invoice time.
            $table->unique(['delivery_line_id', 'stock_movement_id'], 'delivery_line_movement_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_line_movements');
        Schema::dropIfExists('delivery_lines');
        Schema::dropIfExists('deliveries');
    }
};
