<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C-FLOW-5 step 2: what the business already had on the day the books start.
     *
     * ⚠⚠ ITS OWN BUSINESS EVENT, and the reason the generic "receive stock"
     * screen can be deleted. Opening inventory is not a purchase - there is no
     * supplier, no bill and no liability - and it is not an adjustment, because
     * nothing is being corrected. It is the photograph of the warehouse on day
     * one.
     *
     * ⚠ It cannot be an accounting journal alone: a journal carries one figure,
     * and opening inventory is a quantity, a unit cost, a batch number and an
     * expiry date per product per warehouse. Those batches are what FIFO
     * consumes for the rest of the business's life, so **the first sale's cost
     * of goods sold depends on this document being right**.
     *
     * ⚠ It must NOT go through Purchasing. Joy, explicitly: Purchasing
     * represents real purchases from real suppliers, and using it here would
     * invent purchase orders that never happened and Accounts Payable the
     * business does not owe.
     *
     * Posts `Dr Stock on Hand / Cr Opening Balance Equity` as an
     * OpeningBalance journal, which is what keeps the promise that Opening
     * Balance Equity reads ZERO once every opening figure is in.
     */
    public function up(): void
    {
        Schema::create('opening_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            // ⚠ The day before the books start, like every other opening figure.
            // Stored per document because a business may open its warehouses on
            // different dates.
            $table->date('as_at');

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // ⚠⚠ ONCE PER WAREHOUSE, enforced by the database. Opening stock
            // entered twice doubles the inventory, and no later report
            // disagrees - the trial balance still balances, because the second
            // entry credits equity just as correctly as the first.
            $table->unique(['business_id', 'warehouse_id']);
        });

        Schema::create('opening_stock_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opening_stock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();

            // ⚠ The movement this line created, so the document and the
            // warehouse can never drift. One line makes exactly one batch, so
            // unlike a delivery line this is a single id - the same shape
            // goods_receipt_lines uses, and for the same reason.
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'opening_stock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stock_lines');
        Schema::dropIfExists('opening_stocks');
    }
};
